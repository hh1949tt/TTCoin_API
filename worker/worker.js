/**
 * TTCoin 行情接口 —— Cloudflare Worker 版
 *
 * 和同目录上一层的 PHP 版（coin.php）**输出契约完全一致**，手表端不用改一行：
 *
 *   [{"symbol":"BTC","base":"BTC","price":"75872.07","change":"-0.214"}, ...]
 *
 * 为什么要有这个版本：PHP 版跑在家里 NAS 上，NAS 出口到币安经常要 5~30 秒，
 * 阻塞式抓取会占满 PHP-FPM 的 worker，手表 3 秒就超时了。Worker 跑在边缘，
 * 抓取和缓存都在离用户最近的地方完成，毫秒级返回。
 *
 * 数据源为什么不是币安：币安把 Cloudflare 的出口 IP 段（AS13335）整段挡了，
 * 从任何 colo 请求 api*.binance.com / data-api.binance.vision 都回
 * 403 + nginx 默认页（注意：不是 451 地域封锁，也不是 429 限流）。
 * 实测 HKG / HK 出口下，Bybit 与 Coinbase / Kraken 都是 200，所以主用 Bybit：
 * 它的现货符号与币安完全相同（BTCUSDT…），字段里同时有 lastPrice、
 * prevPrice24h、price24hPcnt，一次请求拿到价格和 24h 涨跌。
 *
 * 缓存策略（不用 KV，纯 Cache API）：
 *   - 新鲜副本 60 秒：过期前所有设备共享同一份，命中直接返回；
 *   - 冷备副本 7 天：上游挂了就拿它顶上，绝不给手表返回 503；
 *   - 缓存键只取 type（app / widget），**刻意忽略 uid**——uid 每台表唯一，
 *     拿它当键等于每台设备各存一份，等于没缓存。
 */

// 上游符号 → 手表上显示的简称。顺序即响应顺序（与 PHP 版 ttcoin_symbols() 一致）。
const SYMBOLS = [
  ['BTCUSDT', 'BTC'],
  ['ETHUSDT', 'ETH'],
  ['DOGEUSDT', 'DOGE'],
  ['SHIBUSDT', 'SHIB'],
  ['SOLUSDT', 'SOL'],
  ['BNBUSDT', 'BNB'],
  ['XRPUSDT', 'XRP'],
  ['ADAUSDT', 'ADA'],
  ['LINKUSDT', 'LINK'],
  ['LTCUSDT', 'LTC'],
  ['AVAXUSDT', 'AVAX'],
];

const MAX_DECIMALS = 4;          // 常规价格最多 4 位小数
const MAX_DECIMALS_SCALED = 5;   // 小额币放大后放宽到 5 位
const SCALE_MIN_ZEROS = 3;       // 小数点后前导 0 达 3 个才放大
const WIDGET_TOP_N = 3;          // Widget 一屏 3 行

const FRESH_TTL = 60;            // 秒
const STALE_TTL = 7 * 86400;

const CACHE_ORIGIN = 'https://ttcoin-cache.internal';

/**
 * 价格格式化：**全程字符串运算，不经过浮点**。
 *
 * 与 PHP 版 ttcoin_format_price() 逐行对应。为什么不能用 Number：
 * 手表那边早期用 float 格式化，BTC 的 75678.78 显示成 75678.7812（32 位浮点噪声）。
 * 位数必须在服务端就定死，客户端只负责拼接。
 */
export function formatPrice(raw, maxDecimals = MAX_DECIMALS) {
  if (raw === null || raw === undefined) return null;

  const text = typeof raw === 'string' ? raw.trim() : String(raw);

  if (text === '') return null;

  const m = /^(-?)(\d+)(?:\.(\d+))?$/.exec(text);

  // 科学计数法等罕见写法：退回浮点兜底，位数同样受 maxDecimals 限制
  if (m === null) {
    const value = Number(text);
    if (!Number.isFinite(value)) return null;
    const fixed = value.toFixed(maxDecimals).replace(/0+$/, '').replace(/\.$/, '');
    return fixed === '' || fixed === '-' ? null : fixed;
  }

  const sign = m[1];
  let intPart = m[2].replace(/^0+/, '');
  if (intPart === '') intPart = '0';

  let decimals = m[3] ? m[3].replace(/0+$/, '') : '';

  if (decimals.length > maxDecimals) {
    // 截断位之后的第一位决定进位（用去零之前的原始小数串取这一位）
    const roundUp = Number(m[3][maxDecimals]) >= 5;
    decimals = decimals.slice(0, maxDecimals);

    if (roundUp) {
      // 整数部分 + 保留位拼成一个整数串，末位 +1 处理进位
      const digits = (intPart + decimals).split('');
      let i = digits.length - 1;

      while (i >= 0) {
        if (digits[i] === '9') {
          digits[i] = '0';
          i--;
          continue;
        }
        digits[i] = String(Number(digits[i]) + 1);
        break;
      }

      if (i < 0) digits.unshift('1');   // 999.99999 → 1000.0000

      const all = digits.join('');
      const cut = all.length - maxDecimals;

      intPart = all.slice(0, cut).replace(/^0+/, '') || '0';
      decimals = all.slice(cut);
    }
  }

  decimals = decimals.replace(/0+$/, '');

  return sign + intPart + (decimals === '' ? '' : '.' + decimals);
}

/**
 * 小额价格放大：0.000004467 → 0.04467，同时告诉调用方倍数 10000。
 * 手表上显示成 "10000*SHIB"，提醒这不是原始报价。
 *
 * 不放大：整数部分非 0、负数、科学计数法、前导 0 少于 3 个、本来 4 位就显示得下。
 */
export function scalePrice(raw) {
  const plain = { price: formatPrice(raw), factor: 1 };

  if (typeof raw !== 'string') return plain;

  const text = raw.trim();
  const m = /^(\d+)\.(\d+)$/.exec(text);

  if (m === null) return plain;
  if (m[1].replace(/^0+/, '') !== '') return plain;   // 整数部分非 0

  const frac = m[2].replace(/0+$/, '');

  if (frac === '' || frac.length <= MAX_DECIMALS) return plain;

  let zeros = 0;
  while (zeros < frac.length && frac[zeros] === '0') zeros++;

  if (zeros < SCALE_MIN_ZEROS) return plain;

  // 直接移位小数串，不经过浮点：0.000004467 → "0.0" + "4467"
  const price = formatPrice('0.0' + frac.slice(zeros), MAX_DECIMALS_SCALED);

  if (price === null || price === '0') return plain;

  return { price, factor: Math.pow(10, zeros - 1) };
}

/** 24h 涨跌幅（百分比数值，不带 %）。优先用 prevPrice24h 精算。 */
export function changeOf(item) {
  const last = Number(item.lastPrice);
  const prev = Number(item.prevPrice24h);

  // 用 3 位小数，和币安的 priceChangePercent 输出对齐：
  // 表端的涨跌文本是直接拼 "%" 的，多一位就多一个字宽，三行布局容易挤出去。
  if (Number.isFinite(last) && Number.isFinite(prev) && prev > 0) {
    return formatPrice((((last - prev) / prev) * 100).toFixed(6), 3);
  }

  const pcnt = Number(item.price24hPcnt);

  if (Number.isFinite(pcnt)) {
    return formatPrice((pcnt * 100).toFixed(6), 3);
  }

  return null;
}

/** 把上游 item 集合拼成手表要的数组。任何一币失败就跳过它，不整体报错。 */
export function buildPayload(bySymbol) {
  const result = [];

  for (const [apiSymbol, label] of SYMBOLS) {
    const item = bySymbol[apiSymbol];
    if (!item) continue;

    const scaled = scalePrice(item.lastPrice);
    if (scaled.price === null) continue;

    result.push({
      symbol: scaled.factor > 1 ? scaled.factor + '*' + label : label,
      base: label,
      price: scaled.price,
      change: changeOf(item),
    });
  }

  return result;
}

/** 取单个币种。Bybit v5 现货 ticker，不传 symbol 会返回全量（太大），所以逐个取。 */
async function fetchOne(apiSymbol) {
  const url = 'https://api.bybit.com/v5/market/tickers?category=spot&symbol=' + apiSymbol;

  const response = await fetch(url, {
    headers: { 'Accept': 'application/json', 'User-Agent': 'TTCoin-Worker/1.0' },
  });

  if (!response.ok) {
    throw new Error('bybit HTTP ' + response.status);
  }

  const body = await response.json();

  if (body.retCode !== 0) {
    throw new Error('bybit retCode ' + body.retCode + ' ' + (body.retMsg || ''));
  }

  const item = body.result && body.result.list && body.result.list[0];

  if (!item || typeof item.lastPrice !== 'string') {
    throw new Error('bybit empty: ' + apiSymbol);
  }

  return item;
}

async function fetchAll() {
  // 并行取 11 个币种：串行的话边缘冷启动要 1 秒以上
  const settled = await Promise.allSettled(SYMBOLS.map(([apiSymbol]) => fetchOne(apiSymbol)));

  const bySymbol = {};
  const errors = [];

  settled.forEach((entry, index) => {
    const apiSymbol = SYMBOLS[index][0];

    if (entry.status === 'fulfilled') {
      bySymbol[apiSymbol] = entry.value;
    } else {
      errors.push(apiSymbol + ': ' + entry.reason.message);
    }
  });

  const data = buildPayload(bySymbol);

  if (data.length === 0) {
    return { ok: false, data: null, error: errors.join(' | ') || 'no valid price data' };
  }

  return { ok: true, data, error: errors.join(' | ') };
}

function cacheKey(kind, type, stale) {
  return new Request(
    CACHE_ORIGIN + '/coin.php?type=' + type + '&kind=' + kind + (stale ? '&stale=1' : ''),
    { method: 'GET' }
  );
}

function respond(body, meta) {
  return new Response(body, {
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      // 手表 60 秒自己请求一次，中间层再缓存只会让"退出再进"看到旧价
      'Cache-Control': 'no-store',
      'X-TTCoin-Cache': meta.cache,
      'X-TTCoin-Source': 'bybit',
      'X-TTCoin-Age': String(meta.age || 0),
      'X-TTCoin-Degraded': meta.degraded ? '1' : '0',
    },
  });
}

export default {
  async fetch(request) {
    const url = new URL(request.url);
    const type = url.searchParams.get('type') === 'widget' ? 'widget' : 'app';
    const cache = caches.default;

    const freshKey = cacheKey('data', type, false);
    const staleKey = cacheKey('data', type, true);

    const hit = await cache.match(freshKey);

    if (hit) {
      const text = await hit.text();
      const age = Number(hit.headers.get('X-TTCoin-Stored') || 0);
      const seconds = age > 0 ? Math.max(0, Math.round(Date.now() / 1000 - age)) : 0;
      return respond(trim(text, type), { cache: 'HIT', age: seconds });
    }

    const fetched = await fetchAll();

    if (fetched.ok) {
      const full = JSON.stringify(fetched.data);

      const fresh = new Response(full, {
        headers: {
          'Content-Type': 'application/json; charset=utf-8',
          'Cache-Control': 'public, max-age=' + FRESH_TTL,
          'X-TTCoin-Stored': String(Math.round(Date.now() / 1000)),
        },
      });

      // 新鲜副本要等写完再返回：否则紧接着的请求会二次回源
      await cache.put(freshKey, fresh);

      const stale = new Response(full, {
        headers: {
          'Content-Type': 'application/json; charset=utf-8',
          'Cache-Control': 'public, max-age=' + STALE_TTL,
          'X-TTCoin-Stored': String(Math.round(Date.now() / 1000)),
        },
      });

      await cache.put(staleKey, stale);

      return respond(trim(full, type), {
        cache: 'MISS',
        degraded: fetched.error !== '',
      });
    }

    // 上游全挂：拿 7 天内的冷备顶上，绝不返回 503
    const staleHit = await cache.match(staleKey);

    if (staleHit) {
      const text = await staleHit.text();
      const stored = Number(staleHit.headers.get('X-TTCoin-Stored') || 0);
      const age = stored > 0 ? Math.round(Date.now() / 1000 - stored) : 0;
      return respond(trim(text, type), { cache: 'STALE', age, degraded: true });
    }

    return new Response(JSON.stringify({ error: fetched.error }), {
      status: 503,
      headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' },
    });
  },
};

/**
 * Widget 只回前 3 项。与 PHP 版一致：**缓存里始终是完整列表**，
 * 裁剪只发生在出口，所以 App / Widget 混访不会触发二次抓取。
 */
function trim(text, type) {
  if (type !== 'widget') return text;

  try {
    const list = JSON.parse(text);
    if (!Array.isArray(list) || list.length <= WIDGET_TOP_N) return text;
    return JSON.stringify(list.slice(0, WIDGET_TOP_N));
  } catch (e) {
    return text;
  }
}
