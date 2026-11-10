/**
 * TTCoin 行情接口 —— Cloudflare Worker 版
 *
 * 和同目录上一层的 PHP 版（coin.php）**输出契约完全一致**，手表端不用改一行：
 *
 *   [{"symbol":"BTC","base":"BTC","price":"75872.07","change":"-0.214"}, ...]
 *
 * ?type=face（表盘版）在出口多一步：按 wrangler.toml 的 FACE_* 配置重排 / 裁剪，
 * fixed 只回一条 = 表盘固定显示，scroll 回多条 = 表盘滚屏。缓存仍是完整列表。
 *
 * 为什么要有这个版本：PHP 版跑在家里的内网服务器上，内网出口到币安经常要 5~30 秒，
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
 *   - 缓存键只取 type（app / widget / face），**刻意忽略 uid**——uid 每台表唯一，
 *     拿它当键等于每台设备各存一份，等于没缓存。
 *
 * 设备标识（Worker 侧唯一的办法：客户端自愿带上来）：
 *   ?uid=  设备匿名 ID（手表端 uniqueIdentifier 派生），回显 X-TTCoin-Uid，
 *          并和 ?id=、CF-Connecting-IP 一起写进 console.log 日志
 *          （`npx wrangler tail` 或控制台 Logs 看），见 clientUid / logRequest。
 *   边缘节点看不到手表硬件，除了请求里带的参数，没有任何"设备 ID"可拿。
 */

// 币种基础符号。顺序即响应顺序（与 PHP 版 ttcoin_symbols() 一致）。
const BASES = ['BTC', 'ETH', 'DOGE', 'SHIB', 'SOL', 'BNB', 'XRP', 'ADA', 'LINK', 'LTC', 'AVAX'];

const MAX_DECIMALS = 4;          // 常规价格最多 4 位小数
const MAX_DECIMALS_SMALL = 7;    // 小额价格（小数位前面都是 0）放宽到 7 位
const WIDGET_TOP_N = 3;          // Widget 一屏 3 行

// 表盘（?type=face）专属显示配置。默认值，运行时由 wrangler.toml 的 [vars]
// （或 .dev.vars / secrets）同名覆盖，见 faceConfig()。
//
// 与 PHP 版 config.php 的配置项一一对应，两边改配置时改一样的字符串即可：
//   FACE_MODE    scroll（滚屏） / fixed（固定）
//   FACE_ORDER   独立的币种轮播顺序，按 base 匹配
//   FACE_FIXED   fixed 模式固定显示哪个币（留空 = ORDER 第一项）
//   FACE_TOP_N   scroll 模式最多回几条，0 = 按 ORDER 全给
//   FACE_COIN    三端共用的主币种（默认留空 = 默认币种）：face 的 fixed/滚屏
//                第一位、app / widget 列表的第一位都换成它。支持逗号分隔的
//                多币种优先级列表（如 "BTC,ETH"）：按给定顺序排到最前，
//                fixed 模式取列表第一个
//   FACE_IDS     按 ID 覆盖（JSON 对象，键 = 客户端请求 ?id= 带上来的行情配置
//                ID；表盘 / App / Widget 三端都带，同一个 id 三端同一套行情）。
//                登记哪个键覆盖哪个键（mode/order/fixed/topN/coin），没写的
//                继承默认；同一 id 里 coin 比 fixed 优先；没带 id、或 id 没
//                登记 = 全走默认一套（默认币种）。
//
// 为什么「滚屏 / 固定」靠数组条数表达：表盘端只认
// [{symbol,base,price,change}, ...]，多条就逐条轮播、一条就固定不动。
// 输出契约不变，表盘端零改动。缓存里仍存完整列表，裁剪只发生在出口。
const FACE_MODE = 'scroll';
const FACE_ORDER = 'DOGE,BTC,ETH,SHIB,SOL,BNB,XRP,ADA,LINK,LTC,AVAX';
const FACE_FIXED = '';
const FACE_TOP_N = 0;
const FACE_COIN = '';
// 测试用示例：11111111 固定 DOGE，22222222 滚播，33333333 主币种换 BTC
// （coin 也支持多币种优先级列表，如 {"coin":"BTC,ETH"}）。
// 涨幅口径（change 字段）。与 PHP 版 TTCOIN_CHANGE_* 一一对应：
//   '24h'  默认。上游给的滚动 24 小时涨跌幅，零额外请求。
//   'tz'   指定时区的自然日涨幅：以该时区当日 00:00 的开盘价为基准，
//          (现价 - 开盘价) / 开盘价 × 100，跨过当地 00:00 自动归零重算。
//          日开盘价按「时区 + 当地日期」缓存在 Cache API，一天最多抓一轮；
//          抓不到的币退回 24h。CHANGE_TZ 是固定 UTC 偏移（不换算夏令时）。
const CHANGE_MODE = '24h';
const CHANGE_TZ = '8';        // 东八区

const FACE_IDS =
  '{"11111111":{"mode":"fixed","fixed":"DOGE"},"22222222":{"mode":"scroll"},"33333333":{"coin":"BTC"}}';

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
 * 每家需实现四个方法：
 *   url(base)                     → 上游请求地址
 *   parse(json)                   → { lastPrice, prevPrice24h, price24hPcnt? }
 *   openUrl(base, startMs)        → 当地 00:00 那一根 1 分钟 K 线的请求地址
 *   parseOpen(json, startMs)      → 该币在 00:00 的开盘价字符串（拿不到返回 null）
 *
 * 为什么日开盘价必须问「同一家」：Coinbase 的 BTC-USD 与币安 / Bybit 的
 * BTCUSDT 常年差 0.1% 上下，拿别家的开盘价算涨幅会凭空多出一个系统性偏差。
 * 两家都没有 timezone 参数（Bybit 的 timeZone 实测被忽略），所以统一用
 * 「当地 00:00 那一分钟的 K 线」取 open —— 任意 UTC 偏移（含半小时区）都成立。
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
    openUrl: (base, startMs) => {
      // 取当地 00:00 起 10 分钟的 1 分钟 K。为什么不是只取零点这一根：
      // 冷门币（实测 SHIB）零点那一分钟可能一笔成交都没有，Coinbase 返回 []；
      // 放宽窗口后取「当日最早一根」的 open，就是当日第一笔成交价，语义正确。
      const iso = (ms) => new Date(ms).toISOString().replace('.000Z', 'Z');
      return 'https://api.exchange.coinbase.com/products/' + base + '-USD/candles' +
        '?granularity=60&start=' + encodeURIComponent(iso(startMs)) +
        '&end=' + encodeURIComponent(iso(startMs + 599000));
    },
    parseOpen: (json, startMs) => {
      if (!Array.isArray(json)) return null;

      const want = Math.floor(startMs / 1000);
      // [time, low, high, open, close, volume]，响应按时间倒序
      const rows = json
        .filter((c) => Array.isArray(c) && Number(c[0]) >= want && Number(c[3]) > 0)
        .sort((a, b) => Number(a[0]) - Number(b[0]));

      if (rows.length === 0) return null;

      // 有零点那根就用它，否则用当日最早有成交的一根
      const row = rows.find((c) => Number(c[0]) === want) || rows[0];

      return String(row[3]);
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
    // 同 Coinbase：窗口放宽到 10 分钟，取当日最早一根（冷门币零点可能无成交）
    openUrl: (base, startMs) =>
      'https://api.bybit.com/v5/market/kline?category=spot&symbol=' + base + 'USDT' +
      '&interval=1&start=' + startMs + '&end=' + (startMs + 600000) + '&limit=20',
    parseOpen: (json, startMs) => {
      const list = json && json.retCode === 0 && json.result &&
        Array.isArray(json.result.list) ? json.result.list : [];
      // [startTime, open, high, low, close, volume, turnover]，响应按时间倒序
      const rows = list
        .filter((r) => Array.isArray(r) && Number(r[0]) >= startMs && Number(r[1]) > 0)
        .sort((a, b) => Number(a[0]) - Number(b[0]));

      return rows.length > 0 ? String(rows[0][1]) : null;
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

  // 小额价格：整数部分为 0、小数位前面都是 0（0.00001826）时放宽位数，
  // 否则按常规位数四舍五入会直接变成 "0"。与 PHP 版 ttcoin_format_price()
  // 的规则一致，两种写法分支共用同一条规则。
  const max = /^-?0\.0\d/.test(text) ? MAX_DECIMALS_SMALL : maxDecimals;

  // 科学计数法等罕见写法：退回浮点兜底，位数同样受 max 限制
  if (m === null) {
    const value = Number(text);
    if (!Number.isFinite(value)) return null;
    const fixed = value.toFixed(max).replace(/0+$/, '').replace(/\.$/, '');
    return fixed === '' || fixed === '-' ? null : fixed;
  }

  const sign = m[1];
  let intPart = m[2].replace(/^0+/, '');
  if (intPart === '') intPart = '0';

  let decimals = m[3] ? m[3].replace(/0+$/, '') : '';

  if (decimals.length > max) {
    // 截断位之后的第一位决定进位（用去零之前的原始小数串取这一位）
    const roundUp = Number(m[3][max]) >= 5;
    decimals = decimals.slice(0, max);

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
      const cut = all.length - max;

      intPart = all.slice(0, cut).replace(/^0+/, '') || '0';
      decimals = all.slice(cut);
    }
  }

  decimals = decimals.replace(/0+$/, '');

  return sign + intPart + (decimals === '' ? '' : '.' + decimals);
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

// ---------------------------------------------------------------------------
// 涨幅口径：24h（滚动 24 小时） / tz（指定时区自然日）
// ---------------------------------------------------------------------------
// 与 PHP 版 ttcoin_tz_parse() / ttcoin_change_config() 逐行对应。
// tz 用固定 UTC 偏移（不做夏令时换算）：8 / +8 / UTC+8 → 东八区，
// 0 / Z → UTC，-5 → 美东标准时，5:30 / 5.5 → 印度。写错回落到 8。

/** 解析固定 UTC 偏移，返回 {offsetSec, param, label}；非法返回 null。 */
export function normalizeTz(raw) {
  let text = String(raw === undefined || raw === null ? '8' : raw).trim().toUpperCase();
  text = text.replace(/^(UTC|GMT)\s*/, '').replace(/\s+/g, '');

  if (text === '' || text === 'Z') text = '0';

  let sign = 1;
  let hours = 0;
  let mins = 0;
  let m;

  if (text.indexOf('.') !== -1) {
    // 小数小时：5.5 = 5:30
    m = /^([+-]?)(\d{1,2})\.(\d{1,2})$/.exec(text);
    if (!m) return null;
    hours = Number(m[2]);
    mins = Math.round(Number('0.' + m[3]) * 60);
    sign = m[1] === '-' ? -1 : 1;
  } else {
    m = /^([+-]?)(\d{1,2})(?::(\d{1,2}))?$/.exec(text);
    if (!m) return null;
    hours = Number(m[2]);
    mins = m[3] ? Number(m[3]) : 0;
    sign = m[1] === '-' ? -1 : 1;
  }

  if (mins > 59 || hours > 14) return null;

  const offsetSec = sign * (hours * 3600 + mins * 60);
  const mm = mins === 0 ? '' : ':' + String(mins).padStart(2, '0');
  const param = mins === 0
    ? String(sign * hours)
    : (sign < 0 ? '-' : '') + hours + ':' + String(mins).padStart(2, '0');
  const label = offsetSec === 0 ? 'UTC' : 'UTC' + (sign > 0 ? '+' : '-') + hours + mm;

  return { offsetSec, param, label };
}

/** 本次请求生效的涨幅配置。nowMs 可注入，方便单测跨日边界。 */
export function changeConfig(env = {}, nowMs = Date.now()) {
  const raw = String(envPick(env, 'CHANGE_MODE', CHANGE_MODE)).toLowerCase().trim();
  const mode = (raw === 'tz' || raw === 'timezone' || raw === 'day') ? 'tz' : '24h';
  const tz = normalizeTz(envPick(env, 'CHANGE_TZ', CHANGE_TZ)) || normalizeTz('8');
  const shifted = nowMs + tz.offsetSec * 1000;

  return {
    mode,
    tz,
    day: new Date(shifted).toISOString().slice(0, 10),
    // 当地 00:00 对应的 UTC 毫秒：用整数天取整，避免负偏移算出小数
    startMs: Math.floor(shifted / 86400000) * 86400000 - tz.offsetSec * 1000,
  };
}

/** 涨幅百分比 → 最多 3 位小数、去尾零、-0 归 0（与 change 字段同款）。 */
export function formatChange(pct) {
  if (typeof pct !== 'number' || !Number.isFinite(pct)) return null;

  let text = pct.toFixed(3).replace(/0+$/, '').replace(/\.$/, '');

  if (text === '' || text === '-' || text === '-0') text = '0';

  return text;
}

/** 自然日涨幅 = (现价 - 当地 00:00 开盘价) / 开盘价 × 100。 */
export function dayChangeOf(item, open) {
  const last = Number(item && item.lastPrice);
  const base = Number(open);

  if (!Number.isFinite(last) || !Number.isFinite(base) || base <= 0 || last <= 0) {
    return null;
  }

  return formatChange(((last - base) / base) * 100);
}

/** 把「每个币的行情」拼成手表要的数组，顺序固定为 BASES。 */
export function buildPayload(quotes, opens = {}, cfg = null) {
  const result = [];
  const useTz = !!(cfg && cfg.mode === 'tz');

  for (const base of BASES) {
    const item = quotes[base];
    if (!item) continue;

    // 小额价格由 formatPrice() 内部放宽到最多 7 位小数；
    // 不再放大换算，symbol 与 base 相同、不带倍数前缀。
    const price = formatPrice(item.lastPrice);
    if (price === null) continue;

    // tz 模式：有该币的日开盘价就用自然日涨幅，没抓到就保留 24h（绝不空着）
    let change = changeOf(item);

    if (useTz && opens[base]) {
      const day = dayChangeOf(item, opens[base]);
      if (day !== null) change = day;
    }

    result.push({
      symbol: base,
      base: base,
      price: price,
      change: change,
    });
  }

  return result;
}

// ---------------------------------------------------------------------------
// 表盘（type=face）出口：**与 PHP 版 ttcoin_face_*() 逐行对应**
// ---------------------------------------------------------------------------

/** 从 env 里取一个配置值，未设置 / 空 = 用 fallback。 */
function envPick(env, key, fallback) {
  return env && env[key] !== undefined && env[key] !== null && env[key] !== ''
    ? env[key]
    : fallback;
}

/** 清洗 face 配置。env 是 wrangler.toml [vars] / .dev.vars 注入的对象。 */
export function faceConfig(env = {}, id = '') {
  const pick = (key, fallback) => envPick(env, key, fallback);

  // id 只留字母数字、限长 32（与表盘端 configId() 的过滤一致）
  id = String(id === undefined || id === null ? '' : id)
    .replace(/[^A-Za-z0-9]/g, '')
    .slice(0, 32);

  // —— 默认一套的原始值 ——
  const raw = {
    mode: String(pick('FACE_MODE', FACE_MODE)).trim().toLowerCase(),
    order: pick('FACE_ORDER', FACE_ORDER),
    fixed: String(pick('FACE_FIXED', FACE_FIXED)).trim().toUpperCase(),
    topN: Number.parseInt(pick('FACE_TOP_N', FACE_TOP_N), 10),
    coin: String(pick('FACE_COIN', FACE_COIN)).trim().toUpperCase(),
  };

  // —— 按 id 覆盖：登记了哪个键就覆盖哪个键 ——
  // idKnown 只回报「这个 id 到底登记过没有」，给日志用（PHP 端同名逻辑
  // ttcoin_face_id_known()：没登记的 id 在日志里带 `!`），不参与出口计算。
  let idKnown = false;

  if (id !== '') {
    let ids = null;
    try {
      ids = JSON.parse(String(pick('FACE_IDS', FACE_IDS)));
    } catch (e) {
      ids = null;                            // 脏 JSON：全部走默认
    }

    if (ids && typeof ids === 'object' && ids[id] && typeof ids[id] === 'object') {
      idKnown = true;
      const over = ids[id];

      if (Object.prototype.hasOwnProperty.call(over, 'mode')) {
        raw.mode = String(over.mode).trim().toLowerCase();
      }
      if (Object.prototype.hasOwnProperty.call(over, 'order')) {
        raw.order = over.order;
      }
      if (Object.prototype.hasOwnProperty.call(over, 'fixed')) {
        raw.fixed = String(over.fixed).trim().toUpperCase();
      }
      if (Object.prototype.hasOwnProperty.call(over, 'topN')) {
        const n = Number.parseInt(over.topN, 10);
        raw.topN = Number.isFinite(n) && n >= 0 ? n : 0;
      }
      if (Object.prototype.hasOwnProperty.call(over, 'coin')) {
        raw.coin = String(over.coin).trim().toUpperCase();
      }
    }
  }

  // —— 统一清洗（默认与按 id 走同一套规则）——
  const mode = raw.mode === 'fixed' ? 'fixed' : 'scroll';

  const list = Array.isArray(raw.order) ? raw.order : String(raw.order).split(',');
  const order = [];

  for (const item of list) {
    const base = String(item).toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (base !== '' && !order.includes(base)) order.push(base);
  }

  if (order.length === 0) order.push(...BASES);

  let fixed = String(raw.fixed).toUpperCase().replace(/[^A-Z0-9]/g, '');
  if (fixed === '' || !order.includes(fixed)) fixed = order[0];

  let topN = raw.topN;
  if (!Number.isFinite(topN) || topN < 0) topN = 0;

  // —— 主币种（coin）：比 fixed 优先 ——
  // 支持逗号分隔的多币种优先级列表（如 "BTC,ETH"）：去重后按给定顺序排到
  // order 最前（order 里没有的币也会补进来，与单币种行为一致），列表外的币
  // 保持原相对顺序；fixed 模式下固定币换成列表第一个——fixed 只回一条，
  // 多币种对它无意义，取第一个即可。
  const coinList = [];
  const seen = new Set();

  for (const part of String(raw.coin).toUpperCase().split(',')) {
    const c = part.replace(/[^A-Z0-9]/g, '');

    if (c !== '' && !seen.has(c)) {
      seen.add(c);
      coinList.push(c);
    }
  }

  // 倒序逐个提到最前：正序遍历会把 "BTC,ETH" 摆成 ETH 在前
  for (let i = coinList.length - 1; i >= 0; i--) {
    const c = coinList[i];
    const pos = order.indexOf(c);

    if (pos > 0) {
      order.splice(pos, 1);
      order.unshift(c);
    } else if (pos === -1) {
      order.unshift(c);
    }
  }

  const coin = coinList.join(',');           // "BTC,ETH"；单币种时就是 "BTC"

  if (coinList.length > 0 && mode === 'fixed') {
    fixed = coinList[0];
  }

  return { mode, order, fixed, topN, coin, id, idKnown };
}

/**
 * 取一条行情的 base。当前版本 symbol 与 base 相同（不再加倍数前缀）。
 * 历史缓存可能没有 base；更早版本的缓存里 symbol 可能是 "1000*SHIB"
 * 这种带倍数前缀的写法，取星号后面那段兜底。
 */
export function faceBase(item) {
  if (!item || typeof item !== 'object') return '';

  let base = typeof item.base === 'string' ? item.base : '';

  if (base === '' && typeof item.symbol === 'string') {
    const parts = item.symbol.split('*');
    base = parts[parts.length - 1];
  }

  return String(base).toUpperCase().replace(/[^A-Z0-9]/g, '');
}

/**
 * 按 face 配置重排 / 裁剪完整列表。输入是 JSON 字符串，输出也是 JSON 字符串。
 * 只认 FACE_ORDER 里列出的币，没列到的不返回；一个都没匹配上就退回完整列表，
 * 宁可多给也不让表盘显示 "--"。
 */
export function faceOutput(text, cfg) {
  try {
    const data = JSON.parse(text);
    if (!Array.isArray(data) || data.length === 0) return text;

    const byBase = new Map();

    for (const item of data) {
      const base = faceBase(item);
      if (base !== '' && !byBase.has(base)) byBase.set(base, item);
    }

    let ordered = [];

    for (const base of cfg.order) {
      if (byBase.has(base)) ordered.push(byBase.get(base));
    }

    if (ordered.length === 0) ordered = data.slice();

    let result;

    if (cfg.mode === 'fixed') {
      result = [byBase.has(cfg.fixed) ? byBase.get(cfg.fixed) : ordered[0]];
    } else {
      result = cfg.topN > 0 ? ordered.slice(0, cfg.topN) : ordered;
    }

    return JSON.stringify(result);
  } catch (e) {
    return text;                              // 脏缓存原样返回
  }
}

/**
 * 把主币种排到完整列表最前（其余币的相对顺序不动）。与 PHP 版
 * ttcoin_coin_first() 一致：只在配置里 coin 非空时调用。
 * coin 支持逗号分隔的多币种列表（"BTC,ETH"）：去重后按给定顺序把列表里
 * 的币提到最前（一条行情只被挑一次），列表外的币保持原相对顺序；列表里
 * 的币一条都没匹配上（上游缺币 / 配置写错）时原样返回，绝不动列表。
 */
export function coinFirst(text, coin) {
  const coins = [];

  for (const part of String(coin).toUpperCase().split(',')) {
    const c = part.replace(/[^A-Z0-9]/g, '');

    if (c !== '' && !coins.includes(c)) coins.push(c);
  }

  if (coins.length === 0) return text;

  try {
    const data = JSON.parse(text);
    if (!Array.isArray(data) || data.length === 0) return text;

    const used = new Array(data.length).fill(false);
    const picked = [];

    for (const c of coins) {
      for (let i = 0; i < data.length; i++) {
        if (!used[i] && faceBase(data[i]) === c) {
          picked.push(data[i]);
          used[i] = true;
          break;
        }
      }
    }

    if (picked.length === 0) return text;    // 列表里的币一个都没匹配上

    for (let i = 0; i < data.length; i++) {
      if (!used[i]) picked.push(data[i]);
    }

    return JSON.stringify(picked);
  } catch (e) {
    return text;
  }
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

/** 取单个币在当地 00:00 那一分钟的开盘价，带超时；失败返回 null 由上层回退 24h。 */
async function fetchDayOpen(provider, base, startMs) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), UPSTREAM_TIMEOUT);

  try {
    const response = await fetch(provider.openUrl(base, startMs), {
      headers: BROWSER_HEADERS,
      signal: controller.signal,
    });

    if (!response.ok) throw new Error('HTTP ' + response.status);

    return { base, open: provider.parseOpen(await response.json(), startMs) };
  } catch (err) {
    return { base, open: null };
  } finally {
    clearTimeout(timer);
  }
}

/**
 * 带「按当地日期缓存」的日开盘价表。
 *
 * cache 内容 = {opens:{BASE:价格}, missed:{BASE:上次失败秒}}。
 * 缓存键含时区参数和当地日期，跨过 00:00 自然换成新键；抓不到的币
 * 5 分钟内不重试，避免某个上游缺 K 线时每分钟都白打一次。
 * caches 在本地 Node 单测里不存在，直接走「无缓存」分支。
 */
async function loadDayOpens(sourceOf, cfg) {
  const cache = (typeof caches !== 'undefined' && caches && caches.default) ? caches.default : null;
  const key = new Request(
    CACHE_ORIGIN + '/dayopen/' + cfg.tz.param.replace(/[^A-Za-z0-9]/g, '_') + '/' + cfg.day,
    { method: 'GET' }
  );

  let state = { opens: {}, missed: {} };

  if (cache) {
    try {
      const hit = await cache.match(key);
      if (hit) {
        const parsed = await hit.json();
        if (parsed && typeof parsed === 'object') {
          state.opens = parsed.opens && typeof parsed.opens === 'object' ? parsed.opens : {};
          state.missed = parsed.missed && typeof parsed.missed === 'object' ? parsed.missed : {};
        }
      }
    } catch (e) {
      state = { opens: {}, missed: {} };
    }
  }

  const nowSec = Math.floor(Date.now() / 1000);
  const need = Object.keys(sourceOf).filter((base) =>
    !state.opens[base] && nowSec - Number(state.missed[base] || 0) >= 300
  );

  if (need.length > 0) {
    const settled = await Promise.allSettled(
      need.map((base) => fetchDayOpen(sourceOf[base], base, cfg.startMs))
    );

    settled.forEach((entry) => {
      if (entry.status !== 'fulfilled' || !entry.value) return;

      const { base, open } = entry.value;

      if (open) {
        state.opens[base] = open;
        delete state.missed[base];
      } else {
        state.missed[base] = nowSec;
      }
    });

    if (cache) {
      try {
        await cache.put(key, new Response(JSON.stringify(state), {
          headers: { 'Cache-Control': 'public, max-age=86400' },
        }));
      } catch (e) {
        // 写缓存失败不影响本次结果
      }
    }
  }

  return state.opens;
}

/**
 * 按优先级逐家拉取：一家拉完还缺的币才交给下一家。
 * 正常情况下只需 11 次子请求（Coinbase 一次拉全），远低于 Workers 的 50 次上限。
 * tz 模式再多一轮「日开盘价」请求（按当地日期缓存，一天最多一次）。
 */
async function fetchAll(env = {}) {
  const quotes = {};
  const sourceOf = {};
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
        sourceOf[base] = provider;      // 日开盘价必须问同一家，见 PROVIDERS 注释
        okCount++;
      } else {
        nextPending.push(base);
        lastError[base] = provider.name + ' ' + entry.reason.message;
      }
    });

    if (okCount > 0) sources.push(provider.name);

    pending = nextPending;
  }

  const change = changeConfig(env);
  const opens = change.mode === 'tz' ? await loadDayOpens(sourceOf, change) : {};
  const data = buildPayload(quotes, opens, change);
  const errors = pending.map((base) => base + ': ' + (lastError[base] || 'no data'));

  if (data.length === 0) {
    return { ok: false, data: null, error: errors.join(' | ') || 'no valid price data', sources: '', change };
  }

  return { ok: true, data, error: errors.join(' | '), sources: sources.join('+'), change };
}

function cacheKey(kind, type, stale) {
  return new Request(
    CACHE_ORIGIN + '/coin.php?type=' + type + '&kind=' + kind + (stale ? '&stale=1' : ''),
    { method: 'GET' }
  );
}

/**
 * 客户端设备标识（可选参数 ?uid=），与 PHP 版 ttcoin_client_uid() 同一套规则。
 *
 * 手表端从 System.getDeviceSettings().uniqueIdentifier 取值，自己拼成
 * `?uid=` 带上来：同一设备 + 本应用恒定、卸载重装不变，换应用就换值，
 * 关联不到 Garmin 账号，只能当匿名设备区分符。
 *
 * Worker 侧**没有**任何办法主动拿到「设备 id」——边缘节点只看得见 HTTP 请求，
 * 看不到手表硬件。所有设备标识都只能是客户端自愿带上来 + 服务端白名单清洗：
 *   1. ?uid=  设备级匿名标识（谁在请求）
 *   2. ?id=   行情配置 ID（这块表用哪套行情，见 faceConfig）
 *   3. CF-Connecting-IP  出口 IP（同网段多设备会重合，不能代替 uid）
 *   4. request.cf.colo / country  边缘节点位置，与设备无关
 *
 * 白名单：只留字母数字、最长 64（与 PHP 端一致），空串 = 未提供。
 * 注意 uid 不进缓存键，也不影响响应内容，只用于日志 / 排障。
 */
function clientUid(url) {
  const raw = url.searchParams.get('uid') || '';

  return String(raw).replace(/[^A-Za-z0-9]/g, '').slice(0, 64);
}

/**
 * 请求日志（一行一条 JSON），对应 PHP 版 ttcoin_log_request()。
 *
 * Worker 没有可写的文件系统，落地方式是 `console.log`：
 *   npx wrangler tail                      # 实时看
 *   控制台 → Workers → 你的 Worker → Logs  # 按时间回看（保留期见套餐）
 * 日志里能直接看到 id / uid / IP / 缓存命中情况，PHP 版日志的第 3、4 列同款信息。
 *
 * 需要按 uid 长期统计（而不是翻日志）时，再挂一个 Analytics Engine 数据集，
 * 在 logRequest 里加一行 env.ANALYTICS.writeDataPoint({indexes:[uid],...}) 即可；
 * 那属于额外计费项，默认不启用。想彻底关掉日志：wrangler.toml 里
 * LOG_REQUESTS = "0"。
 */
function logRequest(request, info) {
  try {
    console.log(JSON.stringify({
      t: new Date().toISOString(),
      type: info.type || '',
      id: info.id || '',
      // 带了 id 但 FACE_IDS 里没登记 = 实际走默认一套（与 PHP 日志的 `!` 后缀同义）
      idKnown: !!info.idKnown,
      uid: info.uid || '',
      ip: request.headers.get('CF-Connecting-IP') || '',
      colo: (request.cf && request.cf.colo) || '',
      cc: (request.cf && request.cf.country) || '',
      cache: info.cache || '',
      src: info.sources || '',
      coin: info.coin || '',
      age: info.age || 0,
    }));
  } catch (e) {
    // 日志失败绝不影响响应
  }
}

function respond(body, meta) {
  const headers = {
    'Content-Type': 'application/json; charset=utf-8',
    // 手表 60 秒自己请求一次，中间层再缓存只会让"退出再进"看到旧价
    'Cache-Control': 'no-store',
    'X-TTCoin-Cache': meta.cache,
    'X-TTCoin-Source': meta.sources || 'none',
    'X-TTCoin-Age': String(meta.age || 0),
    'X-TTCoin-Degraded': meta.degraded ? '1' : '0',
  };

  // 设备标识回显（与 PHP 版 coin.php 一致）：手表带了 ?uid= 就原样回一份，
  // 本地 curl -i 就能确认链路有没有把参数带丢。
  if (meta.uid) {
    headers['X-TTCoin-Uid'] = meta.uid;
  }

  // face 请求把生效的配置回显出来，本地 curl / 浏览器直接能核对，
  // 不用上表、不用翻日志。表盘端不读这些头（它按数组条数自己滚）。
  if (meta.face) {
    if (meta.face.id !== '') {
      headers['X-TTCoin-Face-Id'] = meta.face.id;
    }

    headers['X-TTCoin-Face-Mode'] = meta.face.mode;
    headers['X-TTCoin-Face-Order'] = meta.face.order.join(',');

    if (meta.face.mode === 'fixed') {
      headers['X-TTCoin-Face-Fixed'] = meta.face.fixed;
    }

    if (meta.face.topN > 0) {
      headers['X-TTCoin-Face-Top-N'] = String(meta.face.topN);
    }
  }

  // 主币种（coin，三种出口都生效，见 faceConfig / trim）配了就回显：
  // 三端带同一个 id 时，从这个头一眼确认拿到的是同一套主币种优先级
  // （多币种时回显整个列表，如 "BTC,ETH"）。
  if (meta.coin) {
    headers['X-TTCoin-Coin'] = meta.coin;
  }

  // 涨幅口径回显：24h 是上游滚动 24 小时；tz 是以指定时区当日 00:00 开盘价
  // 为基准的自然日涨幅。curl -D - 一眼看出本次配置（缓存里那份按 mode 生成）。
  if (meta.change) {
    headers['X-TTCoin-Change-Mode'] = meta.change.mode;

    if (meta.change.mode === 'tz') {
      headers['X-TTCoin-Change-Tz'] = meta.change.tz.label;
      headers['X-TTCoin-Change-Day'] = meta.change.day;
    }
  }

  // 一行一条请求日志（只有真正对外响应时才记，?diag=1 不记），
  // LOG_REQUESTS = "0" 可整体关掉
  if (meta.req && envPick(meta.env, 'LOG_REQUESTS', '1') !== '0') {
    logRequest(meta.req, {
      type: meta.type,
      id: meta.id,
      idKnown: meta.idKnown,
      uid: meta.uid,
      cache: meta.cache,
      sources: meta.sources,
      coin: meta.coin,
      age: meta.age,
    });
  }

  return new Response(body, { headers });
}

/**
 * 现场体检：不读缓存、不写缓存，直接逐家打一次，把节点和真实状态码打出来。
 * 判断「是出口被拒还是代码有 bug」用这个，比看日志快。
 */
async function runDiag(request, env) {
  const cf = request.cf || {};
  const lines = [];

  lines.push('colo=' + (cf.colo || '?') + '  country=' + (cf.country || '?') + '  city=' + (cf.city || '?'));
  lines.push('time=' + new Date().toISOString());
  // 设备标识只能由客户端带上来：?uid= 是设备匿名 ID、?id= 是行情配置 ID，
  // 这里一并打出来，验证「客户端有没有把参数带对」最快
  lines.push(
    'uid=' + (clientUid(new URL(request.url)) || '-') +
    '  id=' + (new URL(request.url).searchParams.get('id') || '-') +
    '  ip=' + (request.headers.get('CF-Connecting-IP') || '-')
  );
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

  const fetched = await fetchAll(env);

  lines.push('聚合结果：' + (fetched.data ? fetched.data.length : 0) + '/' + BASES.length + ' 个币');
  lines.push('实际来源：' + (fetched.sources || 'none'));
  if (fetched.error) lines.push('失败明细：' + fetched.error);

  // 涨幅口径：把「24h / tz」和 tz 模式实际拿到的日开盘价一起打出来，
  // ?diag=1 就能确认东八区零点价抓到了几个、有没有回退到 24h。
  const change = changeConfig(env);
  lines.push('');
  lines.push('涨幅口径：mode=' + change.mode + '  tz=' + change.tz.label +
    '  day=' + change.day + '  当地 00:00 UTC=' + new Date(change.startMs).toISOString());
  if (change.mode === 'tz' && fetched.data) {
    lines.push('自然日涨幅：' + fetched.data.map((x) => x.base + ' ' + x.change).join(' | '));
  }

  // 顺带把 face 配置和「按这个配置真实过一遍出口」的结果打出来，
  // 本地 wrangler dev + ?diag=1 就能验证滚屏 / 固定 / 独立排序是否生效。
  // 默认一套之外，FACE_IDS 里登记的每个 id 也各打一遍，按 id 覆盖一眼可验。
  const face = faceConfig(env);
  const fullText = JSON.stringify(fetched.data || []);

  let ids = null;
  try {
    ids = JSON.parse(String(envPick(env, 'FACE_IDS', FACE_IDS)));
  } catch (e) {
    ids = null;
  }
  const idKeys = ids && typeof ids === 'object' ? Object.keys(ids) : [];

  lines.push('');

  lines.push('face 配置：mode=' + face.mode + '  fixed=' + face.fixed + '  topN=' + face.topN + '  coin=' + (face.coin || '-'));
  lines.push('face 顺序：' + face.order.join(','));
  lines.push('face 出口：' + faceOutput(fullText, face));

  for (const key of idKeys) {
    const cfg = faceConfig(env, key);
    lines.push('');
    lines.push('face#' + key + '：mode=' + cfg.mode + '  fixed=' + cfg.fixed + '  topN=' + cfg.topN + '  coin=' + (cfg.coin || '-'));
    lines.push('face#' + key + ' 顺序：' + cfg.order.join(','));
    lines.push('face#' + key + ' 出口：' + faceOutput(fullText, cfg));
  }

  return new Response(lines.join('\n') + '\n', {
    headers: {
      'Content-Type': 'text/plain; charset=utf-8',
      'Cache-Control': 'no-store',
      'X-Robots-Tag': 'noindex',
    },
  });
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (url.searchParams.get('diag') === '1') return runDiag(request, env);

    // 三态白名单：face 是表盘版，出口有自己的滚屏 / 固定 + 币种顺序配置。
    // 请求可带 ?id=（手机端「行情配置 ID」）：FACE_IDS 登记了就按登记覆盖，
    // 没登记 / 没带 id 走默认一套。缓存键按 type 分开、不按 id 分 —— 缓存里
    // 存的是**完整列表**，重排 / 裁剪只在出口发生，跟 app / widget 同一套规矩。
    const rawType = url.searchParams.get('type');
    const type = rawType === 'widget' ? 'widget' : rawType === 'face' ? 'face' : 'app';
    // 三种形态都解析 id：face 用整套配置，app / widget 至少要用 coin 把主币种排前
    const cfg = faceConfig(env, url.searchParams.get('id'));
    const face = type === 'face' ? cfg : null;
    // 设备匿名标识（?uid=）：只进响应头 + 日志，不进缓存键（见 clientUid 注释）
    const uid = clientUid(url);
    const change = changeConfig(env);
    const cache = caches.default;

    // 每个出口都要带上的「谁在请求」信息：响应头回显 uid、日志记 id / uid / IP
    const who = { req: request, env, type, id: cfg.id, idKnown: cfg.idKnown, uid };

    const freshKey = cacheKey('data', type, false);
    const staleKey = cacheKey('data', type, true);

    const hit = await cache.match(freshKey);

    if (hit) {
      const text = await hit.text();
      const age = Number(hit.headers.get('X-TTCoin-Stored') || 0);
      const seconds = age > 0 ? Math.max(0, Math.round(Date.now() / 1000 - age)) : 0;
      return respond(trim(text, type, cfg), {
        ...who,
        cache: 'HIT',
        face,
        coin: cfg.coin,
        change,
        age: seconds,
        sources: hit.headers.get('X-TTCoin-Source') || 'cache',
      });
    }

    const fetched = await fetchAll(env);

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

      return respond(trim(full, type, cfg), {
        ...who,
        cache: 'MISS',
        face,
        coin: cfg.coin,
        change,
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
      return respond(trim(text, type, cfg), {
        ...who,
        cache: 'STALE',
        face,
        coin: cfg.coin,
        change,
        age,
        sources: staleHit.headers.get('X-TTCoin-Source') || 'cache',
        degraded: true,
      });
    }

    // 上游全挂且没有冷备：503 也要留一条日志（否则「一起挂了」在日志里是空白）
    if (envPick(env, 'LOG_REQUESTS', '1') !== '0') {
      logRequest(request, {
        type,
        id: cfg.id,
        idKnown: cfg.idKnown,
        uid,
        cache: 'EMPTY',
        sources: 'none',
      });
    }

    return new Response(JSON.stringify({ error: fetched.error }), {
      status: 503,
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
        'Cache-Control': 'no-store',
        'X-TTCoin-Source': 'none',
        ...(uid ? { 'X-TTCoin-Uid': uid } : {}),
      },
    });
  },
};

/**
 * 出口整形，与 PHP 版 ttcoin_filter_output() 一致：**缓存里始终是完整列表**，
 * 裁剪 / 重排只发生在出口，所以三种形态混访不会触发二次抓取。
 *
 *   face    → faceOutput()：按独立顺序重排；fixed 只回一条，scroll 回前 N 条
 *   widget  → 主币种排前（coin 配了才动）→ 只回前 WIDGET_TOP_N 项
 *   app     → 主币种排前（coin 配了才动），其余原样
 *   其他    → 原样
 */
function trim(text, type, cfg) {
  if (type === 'face') return faceOutput(text, cfg || faceConfig());

  // app / widget：登记的 id 配了 coin 时，把主币种排到最前（没配 = 原样）
  let out = coinFirst(text, cfg ? cfg.coin : '');

  if (type !== 'widget') return out;

  try {
    const list = JSON.parse(out);
    if (!Array.isArray(list) || list.length <= WIDGET_TOP_N) return out;
    return JSON.stringify(list.slice(0, WIDGET_TOP_N));
  } catch (e) {
    return out;
  }
}
