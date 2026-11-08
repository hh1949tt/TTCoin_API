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
 * 数据源为什么要多家自动切换：交易所对「数据中心 IP」的封锁按 IP 段做，而且
 * 按地域不同。实测同一份代码，从香港节点打 Bybit 是 200/55ms，从洛杉矶节点
 * 打 Bybit 却是 403 —— 这不是代码问题，是出口被拒。所以这里不押注单一家：
 *
 *   Coinbase（覆盖全部 11 个币，美/欧/亚都通，实测 7ms）
 *     → Bybit（亚太通，符号与币安一致，做补充）
 *
 * 每家按「整家并行拉一次 + 谁缺就由下一家补」的方式合并，最终每个币只要有一家
 * 给到价就算成功。全挂才退化到冷备，绝不给手表 503。
 *
 * 排查用：GET ?diag=1 会当场打印本次请求所在节点、出口国家、每家上游的真实
 * 状态码与耗时。它不读缓存、不写缓存，是判断「这次是网络还是代码」的最快方式。
 *
 * 缓存策略（不用 KV，纯 Cache API）：
 *   - 新鲜副本 60 秒：过期前所有设备共享同一份，命中直接返回；
 *   - 冷备副本 7 天：上游挂了就拿它顶上，绝不给手表返回 503；
 *   - 缓存键只取 type（app / widget），**刻意忽略 uid**——uid 每台表唯一，
 *     拿它当键等于每台设备各存一份，等于没缓存。
 */

// 币种基础符号。顺序即响应顺序（与 PHP 版 ttcoin_symbols() 一致）。
const BASES = ['BTC', 'ETH', 'DOGE', 'SHIB', 'SOL', 'BNB', 'XRP', 'ADA', 'LINK', 'LTC', 'AVAX'];

const MAX_DECIMALS = 4;          // 常规价格最多 4 位小数
const MAX_DECIMALS_SCALED = 5;   // 小额币放大后放宽到 5 位
const SCALE_MIN_ZEROS = 3;       // 小数点后前导 0 达 3 个才放大
const WIDGET_TOP_N = 3;          // Widget 一屏 3 行

const FRESH_TTL = 60;            // 秒
const STALE_TTL = 7 * 86400;

const UPSTREAM_TIMEOUT = 6000;   // 单家上游超时（毫秒）

const CACHE_ORIGIN = 'https://ttcoin-cache.internal';

/**
 * 浏览器风格的请求头。交易所前置的 WAF 对「像脚本的 UA」比对人严得多，
 * 默认的 Workers UA 在部分节点会直接吃 403。
 */
const BROWSER_HEADERS = {
  'Accept': 'application/json, text/plain, */*',
  'Accept-Language': 'en-US,en;q=0.9',
  'User-Agent':
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) ' +
    'Chrome/131.0.0.0 Safari/537.36',
};

/**
 * 上游定义。顺序即优先级，前一家已经给到的币不会被后一家覆盖。
 *
 * 每家只需实现两个方法：
 *   url(base)                     → 上游请求地址
 *   parse(json)                   → { lastPrice, prevPrice24h, price24hPcnt? }
 */
const PROVIDERS = [
  {
    name: 'coinbase',
    url: (base) => 'https://api.exchange.coinbase.com/products/' + base + '-USD/stats',
    parse: (json) => {
      if (!json || typeof json.last !== 'string' || json.last === '') {
        throw new Error('无 last 字段');
      }

      // stats 里的 open 是 24 小时前的开盘价，正好用来算涨跌幅
      return { lastPrice: json.last, prevPrice24h: typeof json.open === 'string' ? json.open : null };
    },
  },
  {
    name: 'bybit',
    url: (base) => 'https://api.bybit.com/v5/market/tickers?category=spot&symbol=' + base + 'USDT',
    parse: (json) => {
      if (!json || json.retCode !== 0) {
        throw new Error('retCode ' + (json && json.retCode) + ' ' + ((json && json.retMsg) || ''));
      }

      const item = json.result && json.result.list && json.result.list[0];

      if (!item || typeof item.lastPrice !== 'string') {
        throw new Error('无 lastPrice 字段');
      }

      return {
        lastPrice: item.lastPrice,
        prevPrice24h: item.prevPrice24h,
        price24hPcnt: item.price24hPcnt,
      };
    },
  },
];

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

/** 把「每个币的行情」拼成手表要的数组，顺序固定为 BASES。 */
export function buildPayload(quotes) {
  const result = [];

  for (const base of BASES) {
    const item = quotes[base];
    if (!item) continue;

    const scaled = scalePrice(item.lastPrice);
    if (scaled.price === null) continue;

    result.push({
      symbol: scaled.factor > 1 ? scaled.factor + '*' + base : base,
      base: base,
      price: scaled.price,
      change: changeOf(item),
    });
  }

  return result;
}

/** 取单个币，带超时。抛出的错误消息会被拼进最终结果，所以要说清是哪家挂的。 */
async function fetchQuote(provider, base) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), UPSTREAM_TIMEOUT);

  try {
    const response = await fetch(provider.url(base), {
      headers: BROWSER_HEADERS,
      signal: controller.signal,
    });

    if (!response.ok) throw new Error('HTTP ' + response.status);

    return provider.parse(await response.json());
  } catch (err) {
    if (err && err.name === 'AbortError') throw new Error('timeout ' + UPSTREAM_TIMEOUT + 'ms');
    throw new Error((err && err.message) || String(err));
  } finally {
    clearTimeout(timer);
  }
}

/**
 * 按优先级逐家拉取：一家拉完还缺的币才交给下一家。
 * 正常情况下只需 11 次子请求（Coinbase 一次拉全），远低于 Workers 的 50 次上限。
 */
async function fetchAll() {
  const quotes = {};
  const lastError = {};
  const sources = [];
  let pending = BASES.slice();

  for (const provider of PROVIDERS) {
    if (pending.length === 0) break;

    const batch = pending;
    const settled = await Promise.allSettled(batch.map((base) => fetchQuote(provider, base)));

    const nextPending = [];
    let okCount = 0;

    settled.forEach((entry, index) => {
      const base = batch[index];

      if (entry.status === 'fulfilled') {
        quotes[base] = entry.value;
        okCount++;
      } else {
        nextPending.push(base);
        lastError[base] = provider.name + ' ' + entry.reason.message;
      }
    });

    if (okCount > 0) sources.push(provider.name);

    pending = nextPending;
  }

  const data = buildPayload(quotes);
  const errors = pending.map((base) => base + ': ' + (lastError[base] || 'no data'));

  if (data.length === 0) {
    return { ok: false, data: null, error: errors.join(' | ') || 'no valid price data', sources: '' };
  }

  return { ok: true, data, error: errors.join(' | '), sources: sources.join('+') };
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
      'X-TTCoin-Source': meta.sources || 'none',
      'X-TTCoin-Age': String(meta.age || 0),
      'X-TTCoin-Degraded': meta.degraded ? '1' : '0',
    },
  });
}

/**
 * 现场体检：不读缓存、不写缓存，直接逐家打一次，把节点和真实状态码打出来。
 * 判断「是出口被拒还是代码有 bug」用这个，比看日志快。
 */
async function runDiag(request) {
  const cf = request.cf || {};
  const lines = [];

  lines.push('colo=' + (cf.colo || '?') + '  country=' + (cf.country || '?') + '  city=' + (cf.city || '?'));
  lines.push('time=' + new Date().toISOString());
  lines.push('');

  for (const provider of PROVIDERS) {
    const started = Date.now();

    try {
      const quote = await fetchQuote(provider, 'BTC');
      lines.push(
        '✅ ' + provider.name.padEnd(10) +
        ' BTC last=' + quote.lastPrice +
        ' prev=' + (quote.prevPrice24h || '-') +
        '  ' + (Date.now() - started) + 'ms'
      );
    } catch (err) {
      lines.push(
        '❌ ' + provider.name.padEnd(10) + ' ' + err.message + '  ' + (Date.now() - started) + 'ms'
      );
    }
  }

  lines.push('');

  const fetched = await fetchAll();

  lines.push('聚合结果：' + (fetched.data ? fetched.data.length : 0) + '/' + BASES.length + ' 个币');
  lines.push('实际来源：' + (fetched.sources || 'none'));
  if (fetched.error) lines.push('失败明细：' + fetched.error);

  return new Response(lines.join('\n') + '\n', {
    headers: {
      'Content-Type': 'text/plain; charset=utf-8',
      'Cache-Control': 'no-store',
      'X-Robots-Tag': 'noindex',
    },
  });
}

export default {
  async fetch(request) {
    const url = new URL(request.url);

    if (url.searchParams.get('diag') === '1') return runDiag(request);

    const type = url.searchParams.get('type') === 'widget' ? 'widget' : 'app';
    const cache = caches.default;

    const freshKey = cacheKey('data', type, false);
    const staleKey = cacheKey('data', type, true);

    const hit = await cache.match(freshKey);

    if (hit) {
      const text = await hit.text();
      const age = Number(hit.headers.get('X-TTCoin-Stored') || 0);
      const seconds = age > 0 ? Math.max(0, Math.round(Date.now() / 1000 - age)) : 0;
      return respond(trim(text, type), {
        cache: 'HIT',
        age: seconds,
        sources: hit.headers.get('X-TTCoin-Source') || 'cache',
      });
    }

    const fetched = await fetchAll();

    if (fetched.ok) {
      const full = JSON.stringify(fetched.data);
      const stored = String(Math.round(Date.now() / 1000));

      const fresh = new Response(full, {
        headers: {
          'Content-Type': 'application/json; charset=utf-8',
          'Cache-Control': 'public, max-age=' + FRESH_TTL,
          'X-TTCoin-Stored': stored,
          'X-TTCoin-Source': fetched.sources,
        },
      });

      // 新鲜副本要等写完再返回：否则紧接着的请求会二次回源
      await cache.put(freshKey, fresh);

      const stale = new Response(full, {
        headers: {
          'Content-Type': 'application/json; charset=utf-8',
          'Cache-Control': 'public, max-age=' + STALE_TTL,
          'X-TTCoin-Stored': stored,
          'X-TTCoin-Source': fetched.sources,
        },
      });

      await cache.put(staleKey, stale);

      return respond(trim(full, type), {
        cache: 'MISS',
        sources: fetched.sources,
        degraded: fetched.error !== '',
      });
    }

    // 上游全挂：拿 7 天内的冷备顶上，绝不返回 503
    const staleHit = await cache.match(staleKey);

    if (staleHit) {
      const text = await staleHit.text();
      const stored = Number(staleHit.headers.get('X-TTCoin-Stored') || 0);
      const age = stored > 0 ? Math.round(Date.now() / 1000 - stored) : 0;
      return respond(trim(text, type), {
        cache: 'STALE',
        age,
        sources: staleHit.headers.get('X-TTCoin-Source') || 'cache',
        degraded: true,
      });
    }

    return new Response(JSON.stringify({ error: fetched.error }), {
      status: 503,
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
        'Cache-Control': 'no-store',
        'X-TTCoin-Source': 'none',
      },
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
