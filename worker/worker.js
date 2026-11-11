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
const BASES = ['BTC', 'ETH', 'DOGE', 'SOL', 'BNB', 'XRP', 'ADA', 'LINK', 'LTC', 'AVAX', 'SHIB'];

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
const FACE_ORDER = 'DOGE,BTC,ETH,SOL,BNB,XRP,ADA,LINK,LTC,AVAX,SHIB';
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
//          FACE_IDS 里每行还能单独写 "change" 和 "tz"（如 {"change":"tz","tz":"-5"}），
//          没写就继承全局；每个时区各自一份日开盘价缓存，多时区并存互不覆盖。
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

/**
 * 按固定偏移算出「当地自然日」三件套：{ tz, day, startMs }。
 * day = 当地日期（YYYY-MM-DD）；startMs = 当地 00:00 对应的 UTC 毫秒。
 * 用整数天取整，避免负偏移算出小数。
 */
export function tzClock(tz, nowMs = Date.now()) {
  const shifted = nowMs + tz.offsetSec * 1000;

  return {
    tz,
    day: new Date(shifted).toISOString().slice(0, 10),
    startMs: Math.floor(shifted / 86400000) * 86400000 - tz.offsetSec * 1000,
  };
}

/** 全局涨幅配置（CHANGE_MODE / CHANGE_TZ）。nowMs 可注入，方便单测跨日边界。 */
export function changeConfig(env = {}, nowMs = Date.now()) {
  const raw = String(envPick(env, 'CHANGE_MODE', CHANGE_MODE)).toLowerCase().trim();
  const mode = (raw === 'tz' || raw === 'timezone' || raw === 'day') ? 'tz' : '24h';
  const tz = normalizeTz(envPick(env, 'CHANGE_TZ', CHANGE_TZ)) || normalizeTz('8');

  return Object.assign({ mode }, tzClock(tz, nowMs));
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

/**
 * 把「每个币的行情」拼成手表要的数组，顺序固定为 BASES。
 *
 * day 字段是**按需多时区**的映射：{ "8": "1.23", "-5": "0.98" }——
 * 全局和每个 id 可以各用各的时区算自然日涨幅，共用同一份缓存互不覆盖；
 * opensByTz 的键是 tz.param（normalizeTz 的输出），tzList 是本次要算的时区。
 * 没有一个时区抓到开盘价时 entry.day = {}，出口按口径挑不到就保留 24h。
 */
export function buildPayload(quotes, opensByTz = {}, tzList = []) {
  const result = [];

  for (const base of BASES) {
    const item = quotes[base];
    if (!item) continue;

    // 小额价格由 formatPrice() 内部放宽到最多 7 位小数；
    // 不再放大换算，symbol 与 base 相同、不带倍数前缀。
    const price = formatPrice(item.lastPrice);
    if (price === null) continue;

    // change 永远是上游的滚动 24 小时值（不烘焙口径）；要 tz 口径时另算一份
    // 自然日涨幅放进 day 字段，出口按「本次请求生效的口径 + 时区」挑选
    // （见 applyChange）。这样全局口径和按 id 口径可以混用同一份缓存。
    const entry = {
      symbol: base,
      base: base,
      price: price,
      change: changeOf(item),
    };

    if (tzList.length > 0) {
      const days = {};

      for (const clock of tzList) {
        const param = clock.tz.param;
        const open = opensByTz[param] && opensByTz[param][base];

        if (!open) continue;

        const day = dayChangeOf(item, open);

        if (day !== null) days[param] = day;
      }

      entry.day = days;
    }

    result.push(entry);
  }

  return result;
}

/** FACE_IDS 里是否有 id 把涨幅口径单独设成了 tz（决定要不要多抓一轮日开盘价）。 */
export function faceIdsNeedTz(env = {}) {
  let ids = null;

  try {
    ids = JSON.parse(String(envPick(env, 'FACE_IDS', FACE_IDS)));
  } catch (e) {
    return false;
  }

  if (!ids || typeof ids !== 'object') return false;

  return Object.values(ids).some((one) => one && typeof one === 'object'
    && String(one.change || '').trim().toLowerCase() === 'tz');
}

/**
 * 本次「需要抓日开盘价」的时区列表（去重，元素是 normalizeTz 的结果）。
 *
 * 谁会用到 tz 口径：
 *   - 全局 CHANGE_MODE = tz  → 全局时区
 *   - 某个 id 的 change = tz → 该 id 自己的 tz，没填就继承全局时区
 *   - 全局已经是 tz，某个 id 又单独填了 tz → 也要算这个 id 的时区
 * 一个都没有就返回空数组，一次 K 线都不抓。
 */
export function changeTzList(env = {}, change) {
  const out = [];
  const add = (tz) => {
    if (tz && !out.some((x) => x.param === tz.param)) out.push(tz);
  };

  if (change.mode === 'tz') add(change.tz);

  let ids = null;

  try {
    ids = JSON.parse(String(envPick(env, 'FACE_IDS', FACE_IDS)));
  } catch (e) {
    ids = null;
  }

  if (ids && typeof ids === 'object') {
    for (const one of Object.values(ids)) {
      if (!one || typeof one !== 'object') continue;

      const own = String(one.change === undefined || one.change === null ? '' : one.change)
        .trim().toLowerCase();
      const mode = (own === 'tz' || own === '24h') ? own : change.mode;

      if (mode !== 'tz') continue;

      add(normalizeTz(one.tz) || change.tz);
    }
  }

  return out;
}

/**
 * face 出口实际生效的涨幅配置：id 的 change / tz 优先，空 = 跟随全局。
 * 返回 { mode, tz, day, startMs }，形状与 changeConfig() 一致，可直接喂 respond。
 */
export function faceChange(change, cfg, type = 'face') {
  const useId = type === 'face' && cfg;

  const own = useId && cfg.chg ? cfg.chg : '';
  const mode = own || change.mode;

  let clock = { tz: change.tz, day: change.day, startMs: change.startMs };

  if (mode === 'tz' && useId && cfg.chgTz) {
    const ownTz = normalizeTz(cfg.chgTz);

    if (ownTz && ownTz.param !== change.tz.param) clock = tzClock(ownTz);
  }

  return { mode, tz: clock.tz, day: clock.day, startMs: clock.startMs };
}

/**
 * 出口涨幅口径挑选：tz 模式优先用缓存里的自然日涨幅（day 字段），
 * 没有（旧缓存 / 当天开盘价没抓到）就保留 24h 值，绝不让 change 空着。
 * 无论哪种口径都会把内部的 day 字段从出口剥掉，手表只认四件套。
 *
 * day 有两种形态：
 *   { "8": "1.23", "-5": "0.98" }  新缓存：按时区各算一份，按 tzParam 取
 *   "1.23"                          旧缓存：当时按全局时区算的，兜底直接用
 * 输入输出都是 JSON 字符串；解析失败原样返回。
 */
export function applyChange(text, mode, tzParam = '') {
  try {
    const data = JSON.parse(text);
    if (!Array.isArray(data) || data.length === 0) return text;

    let changed = false;
    const out = data.map((item) => {
      if (!item || typeof item !== 'object' || item.day === undefined) return item;

      changed = true;
      const copy = Object.assign({}, item);
      const day = copy.day;
      let value = null;

      if (typeof day === 'string' || typeof day === 'number') {
        value = String(day);                       // 旧缓存（含 day: null 之外的标量）
      } else if (day && typeof day === 'object') {
        if (tzParam && day[tzParam] !== undefined) {
          value = day[tzParam];
        } else if (!tzParam) {
          const keys = Object.keys(day);
          if (keys.length === 1) value = day[keys[0]];
        }
      }

      delete copy.day;

      if (mode === 'tz' && value !== null && value !== '') copy.change = value;

      return copy;
    });

    return changed ? JSON.stringify(out) : text;
  } catch (e) {
    return text;                              // 脏缓存原样返回
  }
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
    chg: '',                                 // 空 = 涨幅口径跟随全局默认
    chgTz: '',                               // 空 = 自然日时区跟随全局默认
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
      if (Object.prototype.hasOwnProperty.call(over, 'change')) {
        raw.chg = String(over.change).trim().toLowerCase();
      }
      if (Object.prototype.hasOwnProperty.call(over, 'tz')) {
        raw.chgTz = String(over.tz === undefined || over.tz === null ? '' : over.tz).trim();
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

  return {
    mode,
    order,
    fixed,
    topN,
    coin,
    // 涨幅口径按 id 独立设置：'' = 跟随全局默认（CHANGE_MODE）
    chg: (raw.chg === 'tz' || raw.chg === '24h') ? raw.chg : '',
    // 自然日时区：'' = 跟随全局（CHANGE_TZ）；存归一化后的参数（"8" / "-5" / "5:30"）
    chgTz: (function () {
      if (!raw.chgTz) return '';
      const tz = normalizeTz(raw.chgTz);

      return tz ? tz.param : '';
    })(),
    id,
    idKnown,
  };
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
  // 哪些时区要算自然日：全局 tz + 各 id 自己设的 tz（去重后通常 1 个，最多几个）。
  // 每个时区各自一份开盘价缓存（键里带 tz.param + 当地日期），互不干扰。
  const tzList = changeTzList(env, change);
  const opensByTz = {};

  if (tzList.length > 0) {
    const settled = await Promise.all(tzList.map((tz) => loadDayOpens(sourceOf, tzClock(tz))));

    tzList.forEach((tz, i) => {
      opensByTz[tz.param] = settled[i];
    });
  }

  const data = buildPayload(quotes, opensByTz, tzList);
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
function logRequest(request, info, env, ctx) {
  let record = null;

  try {
    record = {
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
    };

    console.log(JSON.stringify(record));
  } catch (e) {
    // 日志失败绝不影响响应
  }

  // 同时攒进 KV 队列（后台「请求日志」页用）。没有 KV 绑定 / LOG_KV=0 时静默跳过。
  if (record && env && envPick(env, 'LOG_REQUESTS', '1') !== '0') {
    try {
      queueLog(env, ctx, {
        t: Math.floor(Date.now() / 1000),
        y: record.type,
        i: record.id,
        k: record.idKnown ? 1 : 0,
        u: record.uid,
        p: record.ip,
        c: record.colo,
        h: record.cache,
        s: record.src,
        n: record.coin,
        a: record.age,
      });
    } catch (e) {
      // 同上：日志链路任何异常都不影响行情响应
    }
  }
}

// ---------------------------------------------------------------------------
// KV 请求日志：Worker 没有文件系统，`console.log` 只能 tail 实时看，
// 这里把最近 N 条写进 KV（键 request_log），后台页面直接读出来。
//
// 为什么不是「每个请求写一次 KV」：KV 免费额度只有 1000 次写/天，手表三端
// 60 秒一次就 4320 次/天，会直接打爆。所以按「隔离实例内存缓冲 + 定时/攒满刷盘」
// 聚合写入：默认每 120 秒或攒满 12 条写一次（≈720 次/天，留在免费额度内）。
// 代价有两点，都写进 README 了：
//   1. 实例被回收时缓冲区里未刷的条目会丢（诊断用途足够）；
//   2. KV 最终一致，写入后最多 ~60 秒才在其它节点读到。
// 想更实时就把 LOG_KV_GAP 调小（付费套餐下写次数不是问题）。
// ---------------------------------------------------------------------------
const LOG_KV_KEY = 'request_log';
const LOG_BUFFER_MAX = 12;          // 攒满这么多条立刻刷
const LOG_FLUSH_GAP = 120000;       // 否则最多每 120 秒刷一次
const LOG_BUFFER_CAP = 200;         // 缓冲上限，防止极端情况下内存膨胀
const LOG_ENTRY_TTL = 90 * 86400;   // KV 键本身的过期时间（兜底，正常由清理任务裁剪）

let logBuffer = [];
let logLastFlush = 0;
let logFlushing = false;

/** 日志保留天数（1~90，默认 7）。 */
function logKeepDays(env) {
  const n = Number.parseInt(envPick(env, 'LOG_KV_KEEP', '7'), 10);

  return Number.isInteger(n) && n >= 1 && n <= 90 ? n : 7;
}

/** 日志最多保留条数（20~1000，默认 200）。 */
function logMaxRows(env) {
  const n = Number.parseInt(envPick(env, 'LOG_KV_MAX', '200'), 10);

  return Number.isInteger(n) && n >= 20 && n <= 1000 ? n : 200;
}

/** 把一条日志放进缓冲，并按阈值触发刷盘（不阻塞响应）。 */
function queueLog(env, ctx, entry) {
  const kv = env && env.SETTINGS;

  if (!kv || typeof kv.put !== 'function') return;      // 没绑 KV：只留 console 日志
  if (envPick(env, 'LOG_KV', '1') === '0') return;      // 后台里显式关掉

  logBuffer.push(entry);

  if (logBuffer.length > LOG_BUFFER_CAP) {
    logBuffer = logBuffer.slice(-LOG_BUFFER_CAP);
  }

  const task = flushLogs(env, false);

  if (ctx && typeof ctx.waitUntil === 'function') {
    ctx.waitUntil(task);
  } else {
    task.catch(() => {});
  }
}

/**
 * 把缓冲区合并进 KV 里的数组（新→旧），同时按保留期/条数裁剪。
 * force=true 时忽略时间阈值（清空、后台刷新等场景）。
 */
async function flushLogs(env, force) {
  const kv = env && env.SETTINGS;

  if (!kv || typeof kv.put !== 'function') return;
  if (!logBuffer.length) return;
  if (logFlushing) return;

  const now = Date.now();
  const due = force || logBuffer.length >= LOG_BUFFER_MAX || now - logLastFlush >= LOG_FLUSH_GAP;

  if (!due) return;

  logFlushing = true;

  const batch = logBuffer.splice(0, logBuffer.length);

  try {
    let list = [];

    try {
      const raw = await kv.get(LOG_KV_KEY);
      list = raw ? JSON.parse(raw) : [];
    } catch (e) {
      list = [];      // 脏数据：直接重建，不让一条坏 JSON 卡死日志
    }

    if (!Array.isArray(list)) list = [];

    const cutoff = Math.floor(now / 1000) - logKeepDays(env) * 86400;
    const max = logMaxRows(env);

    list = list.concat(batch).filter(item => item && typeof item.t === 'number' && item.t >= cutoff);
    list = list.slice(-max);

    if (list.length) {
      await kv.put(LOG_KV_KEY, JSON.stringify(list), { expirationTtl: LOG_ENTRY_TTL });
    } else {
      await kv.delete(LOG_KV_KEY);
    }

    logLastFlush = Date.now();
  } catch (e) {
    // 写失败不影响行情；把没写进去的条目放回缓冲，下次再试（最多保留 CAP 条）
    logBuffer = batch.concat(logBuffer).slice(-LOG_BUFFER_CAP);
  } finally {
    logFlushing = false;
  }
}

/** 读取并裁剪日志（只读，不改 KV）。 */
async function readLogs(env, limit) {
  const kv = env && env.SETTINGS;

  if (!kv || typeof kv.get !== 'function') return { rows: [], total: 0 };

  let list = [];

  try {
    const raw = await kv.get(LOG_KV_KEY);
    list = raw ? JSON.parse(raw) : [];
  } catch (e) {
    list = [];
  }

  if (!Array.isArray(list)) list = [];

  const cutoff = Math.floor(Date.now() / 1000) - logKeepDays(env) * 86400;
  const alive = list.filter(item => item && typeof item.t === 'number' && item.t >= cutoff);
  const cap = Math.min(Math.max(Number.parseInt(limit, 10) || 200, 1), 1000);

  return { rows: alive.slice(-cap).reverse(), total: alive.length, dropped: list.length - alive.length };
}

/** 定期清理：Cron 触发或后台手动触发（KV 只能整体重写）。 */
async function pruneLogs(env) {
  const kv = env && env.SETTINGS;

  if (!kv || typeof kv.get !== 'function') return { ok: false, message: '未绑定 KV' };

  let list = [];

  try {
    const raw = await kv.get(LOG_KV_KEY);
    list = raw ? JSON.parse(raw) : [];
  } catch (e) {
    list = [];
  }

  if (!Array.isArray(list)) list = [];

  const cutoff = Math.floor(Date.now() / 1000) - logKeepDays(env) * 86400;
  const alive = list.filter(item => item && typeof item.t === 'number' && item.t >= cutoff).slice(-logMaxRows(env));

  if (!alive.length) {
    await kv.delete(LOG_KV_KEY);
  } else if (alive.length !== list.length) {
    await kv.put(LOG_KV_KEY, JSON.stringify(alive), { expirationTtl: LOG_ENTRY_TTL });
  }

  return { ok: true, before: list.length, after: alive.length };
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
    }, meta.env, meta.ctx);
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
  const tzList = changeTzList(env, change);
  lines.push('');
  lines.push('涨幅口径：mode=' + change.mode + '  全局 tz=' + change.tz.label +
    '  day=' + change.day + '  当地 00:00 UTC=' + new Date(change.startMs).toISOString());
  lines.push('算自然日的时区：' + (tzList.length
    ? tzList.map((x) => x.label + '(' + x.param + ')').join(' | ')
    : '无（全部走 24h）'));

  if (tzList.length && fetched.data) {
    lines.push('自然日涨幅（day 字段）：' + fetched.data.slice(0, 4).map((x) =>
      x.base + ' ' + tzList.map((tz) => tz.param + '=' +
        (x.day && x.day[tz.param] !== undefined ? x.day[tz.param] : '-')).join('/')
    ).join(' | '));
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
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    // 网页后台（/admin）在最前面分发：后台流量不进行情缓存、不写请求日志。
    // 设置存 KV（admin_settings 键），保存即生效，不用 wrangler deploy。
    if (url.pathname === '/owner' || url.pathname.startsWith('/owner/')) {
      return handleOwner(request, env, url);
    }

    if (url.pathname === '/admin' || url.pathname.startsWith('/admin/')) {
      return handleAdmin(request, env, url);
    }

    if (url.searchParams.get('diag') === '1') return runDiag(request, env);

    // 把 KV 里保存的后台设置合并进 env（隔离实例内存缓存 30 秒，改配置后
    // 最迟 30 秒全局生效）。之后 faceConfig / changeConfig / LOG_REQUESTS
    // 读到的都是「KV 覆盖 wrangler.toml [vars]」的结果，下游代码零改动。
    env = await effectiveEnv(env);

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
    // 涨幅口径 + 时区：face 按 id 独立设置优先，没设 / 其他形态跟随全局
    const chg = faceChange(change, cfg, type);
    const chgTz = chg.mode === 'tz' ? chg.tz.param : '';
    const changeEcho = { mode: chg.mode, tz: chg.tz, day: chg.day, startMs: chg.startMs };
    const cache = caches.default;

    // 每个出口都要带上的「谁在请求」信息：响应头回显 uid、日志记 id / uid / IP
    const who = { req: request, env, ctx, type, id: cfg.id, idKnown: cfg.idKnown, uid };

    const freshKey = cacheKey('data', type, false);
    const staleKey = cacheKey('data', type, true);

    const hit = await cache.match(freshKey);

    if (hit) {
      const text = await hit.text();
      const age = Number(hit.headers.get('X-TTCoin-Stored') || 0);
      const seconds = age > 0 ? Math.max(0, Math.round(Date.now() / 1000 - age)) : 0;
      return respond(trim(text, type, cfg, chg.mode, chgTz), {
        ...who,
        cache: 'HIT',
        face,
        coin: cfg.coin,
        change: changeEcho,
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

      return respond(trim(full, type, cfg, chg.mode, chgTz), {
        ...who,
        cache: 'MISS',
        face,
        coin: cfg.coin,
        change: changeEcho,
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
      return respond(trim(text, type, cfg, chg.mode, chgTz), {
        ...who,
        cache: 'STALE',
        face,
        coin: cfg.coin,
        change: changeEcho,
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
      }, env, ctx);
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

  /**
   * Cron Trigger（wrangler.toml 的 [triggers] 或控制台 Settings → Triggers）：
   * 每天清一次过期日志。没配 Cron 也不会无限增长——每次刷盘都会按保留期
   * 和条数上限裁剪，Cron 只是「没有请求时也能清」的兜底。
   */
  async scheduled(event, env, ctx) {
    // 注意要合并 KV 里的设置：保留天数 / 条数上限可能是在后台改过的，
    // 直接用原始 env 会退回 wrangler.toml 默认（清理力度不对）。
    ctx.waitUntil(effectiveEnv(env).then(merged => pruneLogs(merged)));
  },
};

/**
 * 出口整形，与 PHP 版 ttcoin_filter_output() 一致：**缓存里始终是完整列表**，
 * 裁剪 / 重排只发生在出口，所以三种形态混访不会触发二次抓取。
 *
 *   涨幅口径 → applyChange()：face 按 id 独立设置优先，没设 / 其他形态走全局
 *   face    → faceOutput()：按独立顺序重排；fixed 只回一条，scroll 回前 N 条
 *   widget  → 主币种排前（coin 配了才动）→ 只回前 WIDGET_TOP_N 项
 *   app     → 主币种排前（coin 配了才动），其余原样
 *   其他    → 原样
 */
function trim(text, type, cfg, chgMode, tzParam) {
  let out = applyChange(text, chgMode || '24h', tzParam || '');

  if (type === 'face') return faceOutput(out, cfg || faceConfig());

  // app / widget：登记的 id 配了 coin 时，把主币种排到最前（没配 = 原样）
  out = coinFirst(out, cfg ? cfg.coin : '');

  if (type !== 'widget') return out;

  try {
    const list = JSON.parse(out);
    if (!Array.isArray(list) || list.length <= WIDGET_TOP_N) return out;
    return JSON.stringify(list.slice(0, WIDGET_TOP_N));
  } catch (e) {
    return out;
  }
}

// ---------------------------------------------------------------------------
// 网页后台（/admin）：配置存 KV，保存即生效（隔离实例缓存 30 秒内全局生效，
// 无需 wrangler deploy）。一次性准备：
//   1) npx wrangler kv namespace create SETTINGS   → 把返回的 id 填进 wrangler.toml
//   2) npx wrangler secret put ADMIN_TOKEN         → 自定一个长口令（后台登录用）
// 不绑 KV / 不设口令时行情出口完全不受影响，/admin 只会显示设置说明。
// 设备管理码 + 设备用户后台（/owner，独立入口）：管理员在「设备管理码」卡里
// 给每个设备 ID 生成一个码（存 KV admin_ids），设备用户打开 /owner 用
// ID + 码登录，只能改自己那一行的独立配置（mode/fixed/coin/topN/order/change），
// 看不到全局配置和日志。/admin 不受理设备登录、/owner 不显示管理员口令，
// 两套 cookie（ttcoin_admin / ttcoin_owner）互不相干；
// 管理码行数很少，读写不占日志的聚合写额度。
// ---------------------------------------------------------------------------

const ADMIN_KV_KEY = 'admin_settings';
const ADMIN_COOKIE = 'ttcoin_admin';
const ADMIN_SESSION_TTL = 7 * 86400;      // 登录态 7 天
const ADMIN_KV_CACHE_TTL = 30000;         // 隔离实例内缓存 30 秒，控制 KV 读成本

// 设备管理码：{ "TCxxxx": { code, t } }，设备用户用 ID+管理码登录后只能改自己那行
const ADMIN_IDS_KEY = 'admin_ids';
const ADMIN_COOKIE_OWNER = 'ttcoin_owner';

/** KV 设置的隔离实例缓存：{ data: 对象或 null, t: 加载时间 } */
let adminKvCache = { data: null, t: 0 };

/** 预览用的样本行情（价格递减便于一眼看清顺序，不回源）。 */
const ADMIN_SAMPLE = JSON.stringify(
  BASES.map((base, i) => ({ symbol: base, base: base, price: String(100 - i), change: '1.0' }))
);

/**
 * 把 KV 里保存的设置合并进 env：返回新对象（不动原 env）。
 * KV 值的约定：值为空字符串 = 不覆盖（envPick 会退回默认），
 * 所以「恢复默认」只需要把对应键存成空串或删掉整个键。
 */
export async function effectiveEnv(env) {
  const kv = env && env.SETTINGS;

  if (!kv || typeof kv.get !== 'function') return env;

  const now = Date.now();

  if (adminKvCache.t && now - adminKvCache.t < ADMIN_KV_CACHE_TTL) {
    return adminKvCache.data ? { ...env, ...adminKvCache.data } : env;
  }

  let data = null;

  try {
    const raw = await kv.get(ADMIN_KV_KEY);

    if (raw) {
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) data = parsed;
    }
  } catch (e) {
    data = null;   // 脏 JSON：当没保存过处理，行情出口走 wrangler.toml 默认
  }

  adminKvCache = { data: data, t: now };

  return data ? { ...env, ...data } : env;
}

/** 后台登录口令（wrangler secret put ADMIN_TOKEN）。 */
function adminToken(env) {
  return String(envPick(env, 'ADMIN_TOKEN', '')).trim();
}

/** 登录态 = HMAC(口令) 截断值，口令本身不落 cookie。 */
async function adminSig(token) {
  const key = await crypto.subtle.importKey(
    'raw', new TextEncoder().encode(token), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
  );
  const mac = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode('ttcoin-admin-v1'));

  return [...new Uint8Array(mac)].map(b => b.toString(16).padStart(2, '0')).join('').slice(0, 40);
}

/** 恒定时间比较，避免逐字节比较的时序侧信道。 */
function ctEqual(a, b) {
  if (a.length !== b.length) return false;

  let diff = 0;

  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);

  return diff === 0;
}

async function adminAuthed(request, env) {
  const token = adminToken(env);

  if (!token) return false;

  const match = /(?:^|;\s*)ttcoin_admin=([0-9a-f]{40})(?:;|$)/
    .exec(request.headers.get('Cookie') || '');

  return !!match && ctEqual(match[1], await adminSig(token));
}

// ---------------------------------------------------------------------------
// 设备管理码：管理员给每个设备 ID 发一个码，设备用户用「ID + 码」登录后
// 只能改自己那一行的独立配置（mode/fixed/coin/topN/order），看不到全局配置
// 与日志。码存 KV（admin_ids），登录态是与 ADMIN_TOKEN 绑定的 HMAC cookie。
// ---------------------------------------------------------------------------

/** 8 位无歧义字符随机码（去掉 0/O/1/I/L）。 */
function randomCode() {
  const alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
  const bytes = new Uint8Array(8);

  crypto.getRandomValues(bytes);

  return [...bytes].map(b => alphabet[b % alphabet.length]).join('');
}

/** 设备登录态签名：绑定 ADMIN_TOKEN 与具体 ID，换口令或换 ID 都会失效。 */
async function ownerSig(token, id) {
  const key = await crypto.subtle.importKey(
    'raw', new TextEncoder().encode(token + ':' + id), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
  );
  const mac = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode('ttcoin-owner-v1'));

  return [...new Uint8Array(mac)].map(b => b.toString(16).padStart(2, '0')).join('').slice(0, 40);
}

/** 校验设备用户登录态，返回设备 ID 或 null。 */
async function ownerAuthed(request, env) {
  const token = adminToken(env);

  if (!token) return null;

  const match = /(?:^|;\s*)ttcoin_owner=([A-Za-z0-9]{1,32})\.([0-9a-f]{40})(?:;|$)/
    .exec(request.headers.get('Cookie') || '');

  if (!match) return null;

  const ok = ctEqual(match[2], await ownerSig(token, match[1]));

  return ok ? match[1] : null;
}

async function loadIdCodes(env) {
  try {
    const raw = await env.SETTINGS.get(ADMIN_IDS_KEY);
    const parsed = raw ? JSON.parse(raw) : null;

    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
  } catch (e) {
    return {};
  }
}

async function saveIdCodes(env, codes) {
  if (Object.keys(codes).length) {
    await env.SETTINGS.put(ADMIN_IDS_KEY, JSON.stringify(codes));
  } else {
    await env.SETTINGS.delete(ADMIN_IDS_KEY);
  }
}

/** POST 一律校验 Origin（没有 Origin 头的算同源，如 curl 直调）。 */
function adminSameOrigin(request) {
  const origin = request.headers.get('Origin');

  if (!origin) return true;

  try {
    return new URL(origin).host === new URL(request.url).host;
  } catch (e) {
    return false;
  }
}

function adminJson(obj, status = 200, extra = {}) {
  return new Response(JSON.stringify(obj), {
    status: status,
    headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', ...extra },
  });
}

async function adminReadJson(request) {
  try {
    return await request.json();
  } catch (e) {
    return null;
  }
}

/** 逗号分隔币种列表 → 去重校验；不支持的币返回 { ok:false, bad }。 */
function adminCoinList(raw) {
  const parts = String(raw === undefined || raw === null ? '' : raw)
    .split(',').map(s => s.trim().toUpperCase()).filter(Boolean);
  const list = [];

  for (const part of parts) {
    if (!BASES.includes(part)) return { ok: false, bad: part };
    if (!list.includes(part)) list.push(part);
  }

  return { ok: true, list: list };
}

/**
 * 校验单个 ID 的覆盖项（mode / fixed / coin / topN / order / change），与 PHP 版
 * admin_clean_id_over() 同一套规则。错误 push 进 errors，返回清洗后的 one
 * （可能为空对象 = 全部继承默认，仍保留登记身份）。
 * 管理员保存（adminValidate）和设备用户保存（owner/save）共用。
 */
export function adminCleanIdOne(id, value, errors) {
  const one = {};
  let bad = false;
  const src = value && typeof value === 'object' && !Array.isArray(value) ? value : {};

  const m = String(src.mode || '').trim().toLowerCase();
  if (m && m !== 'scroll' && m !== 'fixed') {
    errors.push('ID ' + id + ' 的 mode 只能是 scroll / fixed'); bad = true;
  } else if (m) {
    one.mode = m;
  }

  const chg = String(src.change || '').trim().toLowerCase();
  if (chg && chg !== '24h' && chg !== 'tz') {
    errors.push('ID ' + id + ' 的 change 只能是 24h / tz'); bad = true;
  } else if (chg) {
    one.change = chg;
  }

  // 该 id 的自然日时区（只在 change=tz 或全局 tz 时生效）；空 = 继承全局
  const tzRaw = String(src.tz === undefined || src.tz === null ? '' : src.tz).trim();
  if (tzRaw) {
    const tz = normalizeTz(tzRaw);
    if (!tz) {
      errors.push('ID ' + id + ' 的涨幅时区写得不对（示例：8 / -5 / 5:30）'); bad = true;
    } else {
      one.tz = tz.param;
    }
  }

  const fx = String(src.fixed || '').trim().toUpperCase();
  if (fx && !BASES.includes(fx)) {
    errors.push('ID ' + id + ' 的 fixed 币种不支持：' + fx); bad = true;
  } else if (fx) {
    one.fixed = fx;
  }

  const cn = adminCoinList(src.coin);
  if (!cn.ok) {
    errors.push('ID ' + id + ' 的 coin 有不支持的币种：' + cn.bad); bad = true;
  } else if (cn.list.length) {
    one.coin = cn.list.join(',');
  }

  if (src.topN !== undefined && src.topN !== null && String(src.topN).trim() !== '') {
    const n = Number.parseInt(src.topN, 10);
    if (!Number.isInteger(n) || n < 0 || n > BASES.length) {
      errors.push('ID ' + id + ' 的 topN 必须是 0~' + BASES.length); bad = true;
    } else {
      one.topN = n;
    }
  }

  const od = adminCoinList(src.order);
  if (!od.ok) {
    errors.push('ID ' + id + ' 的 order 有不支持的币种：' + od.bad); bad = true;
  } else if (od.list.length) {
    one.order = od.list.join(',');
  }

  return bad ? null : one;
}

/**
 * 校验并清洗后台提交的设置。与 PHP 版 admin_validate() 同一套规则：
 * 只收「非空且合法」的键（空 = 不覆盖 = 走默认），FACE_IDS 存回 JSON 字符串。
 */
export function adminValidate(input) {
  const errors = [];
  const warnings = [];
  const clean = {};
  const src = input && typeof input === 'object' && !Array.isArray(input) ? input : {};
  const str = key => src[key] === undefined || src[key] === null ? '' : String(src[key]).trim();

  const mode = str('FACE_MODE').toLowerCase();
  if (mode && mode !== 'scroll' && mode !== 'fixed') {
    errors.push('FACE_MODE 只能是 scroll 或 fixed');
  } else if (mode) {
    clean.FACE_MODE = mode;
  }

  const order = adminCoinList(str('FACE_ORDER'));
  if (!order.ok) {
    errors.push('FACE_ORDER 里有不支持的币种：' + order.bad + '（支持：' + BASES.join(',') + '）');
  } else if (order.list.length) {
    clean.FACE_ORDER = order.list.join(',');
  }

  const fixed = str('FACE_FIXED').toUpperCase();
  if (fixed && !BASES.includes(fixed)) {
    errors.push('FACE_FIXED 必须是 ' + BASES.join('/') + ' 之一');
  } else if (fixed) {
    clean.FACE_FIXED = fixed;
  }

  const topN = str('FACE_TOP_N');
  if (topN !== '') {
    const n = Number.parseInt(topN, 10);
    if (!Number.isInteger(n) || n < 0 || n > BASES.length) {
      errors.push('FACE_TOP_N 必须是 0~' + BASES.length + ' 的整数（0 = 按 ORDER 全给）');
    } else {
      clean.FACE_TOP_N = String(n);
    }
  }

  const coin = adminCoinList(str('FACE_COIN'));
  if (!coin.ok) {
    errors.push('FACE_COIN（主币种）里有不支持的币种：' + coin.bad);
  } else if (coin.list.length) {
    clean.FACE_COIN = coin.list.join(',');
  }

  const changeMode = str('CHANGE_MODE').toLowerCase();
  if (changeMode && changeMode !== '24h' && changeMode !== 'tz') {
    errors.push('CHANGE_MODE 只能是 24h 或 tz');
  } else if (changeMode) {
    clean.CHANGE_MODE = changeMode;
  }

  const tz = str('CHANGE_TZ');
  if (tz && !normalizeTz(tz)) {
    errors.push('CHANGE_TZ 不是合法的 UTC 偏移（示例：8 / 0 / -5 / 5:30）');
  } else if (tz) {
    clean.CHANGE_TZ = tz;
  }

  const logReq = str('LOG_REQUESTS');
  if (logReq && logReq !== '0' && logReq !== '1') {
    errors.push('LOG_REQUESTS 只能是 0 或 1');
  } else if (logReq) {
    clean.LOG_REQUESTS = logReq;
  }

  // —— KV 请求日志：总开关 + 保留天数 + 最多条数 ——
  const logKv = str('LOG_KV');
  if (logKv && logKv !== '0' && logKv !== '1') {
    errors.push('LOG_KV 只能是 0 或 1');
  } else if (logKv) {
    clean.LOG_KV = logKv;
  }

  const logKeep = str('LOG_KV_KEEP');
  if (logKeep !== '') {
    const n = Number.parseInt(logKeep, 10);
    if (!Number.isInteger(n) || n < 1 || n > 90) {
      errors.push('日志保留天数必须是 1~90 的整数');
    } else {
      clean.LOG_KV_KEEP = String(n);
    }
  }

  const logMax = str('LOG_KV_MAX');
  if (logMax !== '') {
    const n = Number.parseInt(logMax, 10);
    if (!Number.isInteger(n) || n < 20 || n > 1000) {
      errors.push('日志最多条数必须是 20~1000 的整数');
    } else {
      clean.LOG_KV_MAX = String(n);
    }
  }

  // —— FACE_IDS：键限 32 位字母数字，行内 mode/fixed/coin/topN/order 逐项校验 ——
  let idsIn = src.FACE_IDS;

  if (typeof idsIn === 'string') {
    try {
      idsIn = JSON.parse(idsIn || '{}');
    } catch (e) {
      idsIn = { __bad: true };
    }
  }

  if (idsIn === null || idsIn === undefined || idsIn === '') idsIn = {};

  if (idsIn.__bad || Array.isArray(idsIn) || typeof idsIn !== 'object') {
    errors.push('FACE_IDS 不是合法的 JSON 对象');
  } else {
    const ids = {};

    for (const [key, value] of Object.entries(idsIn)) {
      const id = String(key).replace(/[^A-Za-z0-9]/g, '').slice(0, 32);

      if (!id) continue;   // 空 ID 行直接丢弃

      const one = adminCleanIdOne(id, value, errors);

      if (one && Object.keys(one).length) ids[id] = one;
    }

    if (Object.keys(ids).length) clean.FACE_IDS = JSON.stringify(ids);

    // 与 PHP 版同一个坑：全局主币种（coin）优先级高于行内 fixed
    if (clean.FACE_COIN) {
      for (const [id, one] of Object.entries(ids)) {
        if (one.fixed && !one.coin) {
          warnings.push(
            'ID ' + id + '：全局主币种（' + clean.FACE_COIN + '）优先于行内 fixed，' +
            '其 fixed=' + one.fixed + ' 会被顶掉；要固定 ' + one.fixed +
            ' 请给该行同时填 coin=' + one.fixed
          );
        }
      }
    }
  }

  return { clean: clean, errors: errors, warnings: warnings };
}

/** 后台各入口的分发。 */
/**
 * 设备用户后台（/owner）：与管理员后台（/admin）完全分开的两个入口。
 * /admin 不再受理设备登录，/owner 也不出现管理员口令；两套 cookie 独立
 * （ADMIN_COOKIE / ADMIN_COOKIE_OWNER），互不影响。
 *
 * 页面就是一张登录卡（设备 ID + 管理码），登录后只显示自己那一行的
 * 模式 / 固定币 / 主币种 / 条数 / 顺序 / 涨幅口径，可预览、保存。
 */
async function handleOwner(request, env, url) {
  const path = url.pathname.replace(/\/+$/, '') || '/owner';
  const token = adminToken(env);
  const hasKv = !!(env && env.SETTINGS && typeof env.SETTINGS.get === 'function');
  const method = request.method;

  if (method !== 'GET' && method !== 'POST') return adminJson({ ok: false, message: 'method' }, 405);
  if (method === 'POST' && !adminSameOrigin(request)) {
    return adminJson({ ok: false, message: 'Origin 校验失败' }, 403);
  }

  if (path === '/owner' && method === 'GET') {
    return new Response(ownerPage(), {
      headers: {
        'Content-Type': 'text/html; charset=utf-8',
        'Cache-Control': 'no-store',
        'X-Robots-Tag': 'noindex',
      },
    });
  }

  // —— 设备用户登录：ID + 管理码（不需要管理员口令）——
  if (path === '/owner/api/login' && method === 'POST') {
    if (!token) return adminJson({ ok: false, message: '服务端未设置 ADMIN_TOKEN，管理码登录不可用' }, 400);
    if (!hasKv) return adminJson({ ok: false, message: '未绑定 KV，管理码不可用' }, 400);

    const body = await adminReadJson(request);
    const id = String((body && body.id) || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 32);
    const code = String((body && body.code) || '').trim().toUpperCase();
    const codes = await loadIdCodes(env);
    const got = codes[id] && codes[id].code ? String(codes[id].code) : '';

    // 不区分「ID 不存在」和「码不对」，不给试探的人反馈哪一步错了
    if (!id || !got || !ctEqual(got, code)) {
      return adminJson({ ok: false, message: 'ID 或管理码不对' }, 401);
    }

    return adminJson({ ok: true, id: id }, 200, {
      'Set-Cookie': ADMIN_COOKIE_OWNER + '=' + id + '.' + (await ownerSig(token, id)) +
        '; HttpOnly; Secure; SameSite=Strict; Path=/; Max-Age=' + ADMIN_SESSION_TTL,
    });
  }

  if (path === '/owner/api/logout' && method === 'POST') {
    return adminJson({ ok: true }, 200, {
      'Set-Cookie': ADMIN_COOKIE_OWNER + '=; HttpOnly; Secure; SameSite=Strict; Path=/; Max-Age=0',
    });
  }

  // —— 以下 owner 接口只认设备登录态，与管理员登录互不相干 ——
  const ownerId = await ownerAuthed(request, env);

  if (path === '/owner/api/get' && method === 'GET') {
    if (!ownerId) return adminJson({ ok: false, message: '未登录' }, 401);

    const merged = await effectiveEnv(env);
    let ids = {};

    try {
      ids = JSON.parse(String(envPick(merged, 'FACE_IDS', FACE_IDS))) || {};
    } catch (e) {
      ids = {};
    }

    return adminJson({
      ok: true,
      id: ownerId,
      one: ids[ownerId] && typeof ids[ownerId] === 'object' ? ids[ownerId] : {},
    });
  }

  if (path === '/owner/api/save' && method === 'POST') {
    if (!ownerId) return adminJson({ ok: false, message: '未登录' }, 401);
    if (!hasKv) return adminJson({ ok: false, message: '未绑定 KV' }, 400);

    const body = await adminReadJson(request);
    const errors = [];
    const one = adminCleanIdOne(ownerId, body && body.over, errors);

    if (errors.length) {
      return adminJson({ ok: false, message: '有 ' + errors.length + ' 项没通过校验', errors: errors }, 422);
    }

    // 与 PHP 版同一策略：读原始 KV 设置，只改 FACE_IDS 自己那行，其它键原样保留
    // 显式标注类型：否则 TS 会从 `raw.FACE_IDS = ...` 反推出「必带 FACE_IDS」的形状，
    // 回头把 `raw = {}` 判成缺属性（ts2741），编辑器里一直飘红。
    /** @type {Record<string, any>} */
    let raw = {};

    try {
      const s = await env.SETTINGS.get(ADMIN_KV_KEY);

      if (s) raw = JSON.parse(s);
    } catch (e) {
      raw = {};
    }

    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) raw = {};

    let ids = {};

    if (raw.FACE_IDS) {
      try {
        ids = JSON.parse(raw.FACE_IDS) || {};
      } catch (e) {
        ids = {};
      }
    }

    ids[ownerId] = one;
    raw.FACE_IDS = JSON.stringify(ids);

    await env.SETTINGS.put(ADMIN_KV_KEY, JSON.stringify(raw));

    try {
      adminKvCache = { data: JSON.parse(await env.SETTINGS.get(ADMIN_KV_KEY)), t: Date.now() };
    } catch (e) {
      adminKvCache = { data: null, t: 0 };
    }

    // 与管理员保存同一条软提醒：全局 coin 优先于行内 fixed
    const warnings = [];
    const globalCoin = String(envPick(raw, 'FACE_COIN', '')).trim().toUpperCase();

    if (one.fixed && globalCoin) {
      const first = globalCoin.split(',')[0];
      const ownFirst = one.coin ? one.coin.split(',')[0] : '';

      if (!ownFirst && first !== one.fixed) {
        warnings.push('全局主币种是 ' + first + '（coin 优先，会覆盖固定币）；要固定 ' +
          one.fixed + '，请把主币种也填成 ' + one.fixed);
      } else if (ownFirst && ownFirst !== one.fixed) {
        warnings.push('固定币是 ' + one.fixed + '，但主币种第一项是 ' + ownFirst + '，实际会显示 ' + ownFirst);
      }
    }

    return adminJson({
      ok: true,
      message: '已保存，约 30 秒内全局生效',
      warnings: warnings,
    });
  }

  if (path === '/owner/api/preview' && method === 'POST') {
    if (!ownerId) return adminJson({ ok: false, message: '未登录' }, 401);

    const body = await adminReadJson(request);
    const errors = [];
    const one = adminCleanIdOne(ownerId, body && body.over, errors);

    if (errors.length) {
      return adminJson({ ok: false, message: '有 ' + errors.length + ' 项没通过校验', errors: errors }, 422);
    }

    const merged = await effectiveEnv(env);
    let ids = {};

    try {
      ids = JSON.parse(String(envPick(merged, 'FACE_IDS', FACE_IDS))) || {};
    } catch (e) {
      ids = {};
    }

    // 预览 = 已保存配置 + 本次覆盖合并（本次没填的项保留已保存值），
    // 否则保存过的涨幅口径之类会被预览悄悄丢掉，显示结果和保存后不一致
    const savedOne = ids[ownerId] && typeof ids[ownerId] === 'object' ? ids[ownerId] : {};

    ids[ownerId] = Object.assign({}, savedOne, one);

    // 候选要带全当前生效的全局值（effectiveEnv 合并后的），FACE_IDS 换成合并结果
    const cfg = faceConfig({
      FACE_MODE: envPick(merged, 'FACE_MODE', FACE_MODE),
      FACE_ORDER: envPick(merged, 'FACE_ORDER', FACE_ORDER),
      FACE_FIXED: envPick(merged, 'FACE_FIXED', FACE_FIXED),
      FACE_TOP_N: envPick(merged, 'FACE_TOP_N', FACE_TOP_N),
      FACE_COIN: envPick(merged, 'FACE_COIN', FACE_COIN),
      FACE_IDS: JSON.stringify(ids),
    }, ownerId);
    const out = JSON.parse(faceOutput(ADMIN_SAMPLE, cfg) || '[]');
    // 实际生效的涨幅口径（含 id 自己的时区），预览里一并回显
    const effChg = faceChange(changeConfig(merged), cfg, 'face');

    return adminJson({
      ok: true,
      id: ownerId,
      known: cfg.idKnown,
      config: {
        mode: cfg.mode,
        fixed: cfg.fixed,
        topN: cfg.topN,
        coin: cfg.coin,
        chg: cfg.chg,
        chgTz: cfg.chgTz,
        chgTzLabel: effChg.mode === 'tz' ? effChg.tz.label : '',
        order: cfg.order.join(','),
      },
      count: out.length,
      coins: out.map(item => item.symbol),
    });
  }

  return adminJson({ ok: false, message: 'not found' }, 404);
}

async function handleAdmin(request, env, url) {
  const path = url.pathname.replace(/\/+$/, '') || '/admin';
  const token = adminToken(env);
  const hasKv = !!(env && env.SETTINGS && typeof env.SETTINGS.get === 'function');
  const method = request.method;

  if (method !== 'GET' && method !== 'POST') return adminJson({ ok: false, message: 'method' }, 405);
  if (method === 'POST' && !adminSameOrigin(request)) {
    return adminJson({ ok: false, message: 'Origin 校验失败' }, 403);
  }

  if (path === '/admin' && method === 'GET') {
    return new Response(adminPage(), {
      headers: {
        'Content-Type': 'text/html; charset=utf-8',
        'Cache-Control': 'no-store',
        'X-Robots-Tag': 'noindex',
      },
    });
  }

  if (path === '/admin/api/state' && method === 'GET') {
    return adminJson({
      ok: true,
      tokenSet: !!token,
      kvBound: hasKv,
      authed: token ? await adminAuthed(request, env) : false,
    });
  }

  if (path === '/admin/api/login' && method === 'POST') {
    if (!token) return adminJson({ ok: false, message: '未设置 ADMIN_TOKEN（wrangler secret put ADMIN_TOKEN）' }, 400);

    const body = await adminReadJson(request);
    const input = String((body && body.token) || '').trim();

    if (!input || input !== token) return adminJson({ ok: false, message: '口令不对' }, 401);

    return adminJson({ ok: true }, 200, {
      'Set-Cookie': ADMIN_COOKIE + '=' + (await adminSig(token)) +
        '; HttpOnly; Secure; SameSite=Strict; Path=/; Max-Age=' + ADMIN_SESSION_TTL,
    });
  }

  if (path === '/admin/api/logout' && method === 'POST') {
    return adminJson({ ok: true }, 200, {
      'Set-Cookie': ADMIN_COOKIE + '=; HttpOnly; Secure; SameSite=Strict; Path=/; Max-Age=0',
    });
  }

  // —— 以下接口都要管理员登录（设备用户的接口全部在 /owner，见 handleOwner）——
  if (!token) return adminJson({ ok: false, message: '未设置 ADMIN_TOKEN' }, 400);
  if (!(await adminAuthed(request, env))) return adminJson({ ok: false, message: '未登录' }, 401);

  // —— 设备管理码维护（管理员）——
  if (path === '/admin/api/codes' && method === 'GET') {
    if (!hasKv) return adminJson({ ok: false, message: '未绑定 KV，管理码不可用' }, 400);

    return adminJson({ ok: true, codes: await loadIdCodes(env) });
  }

  if (path === '/admin/api/codes/set' && method === 'POST') {
    if (!hasKv) return adminJson({ ok: false, message: '未绑定 KV，管理码不可用' }, 400);

    const body = await adminReadJson(request);
    const id = String((body && body.id) || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 32);

    if (!id) return adminJson({ ok: false, message: 'ID 不能为空' }, 422);

    let code = String((body && body.code) || '').trim().toUpperCase();
    const codes = await loadIdCodes(env);

    if (!code) {
      delete codes[id];

      await saveIdCodes(env, codes);

      return adminJson({ ok: true, message: '已删除 ' + id + ' 的管理码' });
    }

    if (code === 'AUTO') {
      code = randomCode();
    } else if (code.length < 6 || code.length > 32) {
      return adminJson({ ok: false, message: '管理码长度要在 6 到 32 位之间（auto = 自动生成）' }, 422);
    }

    codes[id] = { code: code, t: Math.floor(Date.now() / 1000) };

    await saveIdCodes(env, codes);

    return adminJson({ ok: true, message: '已保存', code: code });
  }

  if (path === '/admin/api/settings' && method === 'GET') {
    const merged = await effectiveEnv(env);
    let saved = null;

    try {
      const raw = await env.SETTINGS.get(ADMIN_KV_KEY);
      if (raw) saved = JSON.parse(raw);
    } catch (e) {
      saved = null;
    }

    let ids = {};

    try {
      ids = JSON.parse(String(envPick(merged, 'FACE_IDS', FACE_IDS))) || {};
    } catch (e) {
      ids = {};
    }

    return adminJson({
      ok: true,
      values: {
        FACE_MODE: String(envPick(merged, 'FACE_MODE', FACE_MODE)).trim().toLowerCase(),
        FACE_ORDER: String(envPick(merged, 'FACE_ORDER', FACE_ORDER)),
        FACE_FIXED: String(envPick(merged, 'FACE_FIXED', FACE_FIXED)).trim().toUpperCase(),
        FACE_TOP_N: String(envPick(merged, 'FACE_TOP_N', FACE_TOP_N)),
        FACE_COIN: String(envPick(merged, 'FACE_COIN', FACE_COIN)).trim().toUpperCase(),
        CHANGE_MODE: String(envPick(merged, 'CHANGE_MODE', CHANGE_MODE)).trim().toLowerCase(),
        CHANGE_TZ: String(envPick(merged, 'CHANGE_TZ', CHANGE_TZ)),
        LOG_REQUESTS: String(envPick(merged, 'LOG_REQUESTS', '1')),
        LOG_KV: String(envPick(merged, 'LOG_KV', '1')),
        LOG_KV_KEEP: String(logKeepDays(merged)),
        LOG_KV_MAX: String(logMaxRows(merged)),
      },
      ids: ids,
      savedKeys: saved && typeof saved === 'object' ? Object.keys(saved) : [],
    });
  }

  if (path === '/admin/api/save' && method === 'POST') {
    if (!hasKv) return adminJson({ ok: false, message: '未绑定 KV（wrangler kv namespace create SETTINGS）' }, 400);

    const body = await adminReadJson(request);
    const result = adminValidate(body && body.settings);

    if (result.errors.length) {
      return adminJson({ ok: false, message: '有 ' + result.errors.length + ' 项没通过校验', errors: result.errors }, 400);
    }

    const kv = env.SETTINGS;

    if (Object.keys(result.clean).length) {
      await kv.put(ADMIN_KV_KEY, JSON.stringify(result.clean));
    } else {
      await kv.delete(ADMIN_KV_KEY);   // 全部清空 = 整体恢复 wrangler.toml 默认
    }

    try {
      const raw = await kv.get(ADMIN_KV_KEY);
      adminKvCache = { data: raw ? JSON.parse(raw) : null, t: Date.now() };
    } catch (e) {
      adminKvCache = { data: null, t: 0 };
    }

    return adminJson({
      ok: true,
      message: Object.keys(result.clean).length
        ? '已保存，约 30 秒内全局生效（无需重新部署）'
        : '设置已清空，全部恢复 wrangler.toml 默认',
      warnings: result.warnings,
    });
  }

  if (path === '/admin/api/reset' && method === 'POST') {
    if (hasKv) {
      await env.SETTINGS.delete(ADMIN_KV_KEY);
      adminKvCache = { data: null, t: Date.now() };
    }

    return adminJson({ ok: true, message: '已恢复 wrangler.toml 默认配置' });
  }

  if (path === '/admin/api/preview' && method === 'POST') {
    const body = await adminReadJson(request);
    let cand = null;
    let warnings = [];

    if (body && body.settings && typeof body.settings === 'object') {
      const result = adminValidate(body.settings);

      if (result.errors.length) {
        return adminJson({ ok: false, message: '有 ' + result.errors.length + ' 项没通过校验', errors: result.errors }, 400);
      }

      cand = result.clean;
      warnings = result.warnings;
    } else {
      const merged = await effectiveEnv(env);

      cand = {
        FACE_MODE: envPick(merged, 'FACE_MODE', FACE_MODE),
        FACE_ORDER: envPick(merged, 'FACE_ORDER', FACE_ORDER),
        FACE_FIXED: envPick(merged, 'FACE_FIXED', FACE_FIXED),
        FACE_TOP_N: envPick(merged, 'FACE_TOP_N', FACE_TOP_N),
        FACE_COIN: envPick(merged, 'FACE_COIN', FACE_COIN),
        FACE_IDS: envPick(merged, 'FACE_IDS', FACE_IDS),
      };
    }

    const id = String((body && body.id) || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 32);
    const cfg = faceConfig(cand, id);
    const out = JSON.parse(faceOutput(ADMIN_SAMPLE, cfg) || '[]');
    const host = url.host;

    return adminJson({
      ok: true,
      id: id,
      known: cfg.idKnown,
      config: { mode: cfg.mode, fixed: cfg.fixed, topN: cfg.topN, coin: cfg.coin, chg: cfg.chg, chgTz: cfg.chgTz, order: cfg.order.join(',') },
      count: out.length,
      coins: out.map(item => item.symbol),
      warnings: warnings,
      curl: 'curl -sD - "https://' + host + '/coin.php?type=face' + (id ? '&id=' + id : '') + '"',
    });
  }

  // —— 请求日志（KV 里最近 N 条）——
  if (path === '/admin/api/logs' && method === 'GET') {
    if (!hasKv) return adminJson({ ok: false, message: '未绑定 KV，日志不可用' }, 400);

    const mergedLog = await effectiveEnv(env);
    const limit = url.searchParams.get('limit') || '200';
    const got = await readLogs(mergedLog, limit);

    return adminJson({
      ok: true,
      rows: got.rows,
      total: got.total,
      dropped: got.dropped || 0,
      enabled: String(envPick(mergedLog, 'LOG_KV', '1')) !== '0',
      keepDays: logKeepDays(mergedLog),
      maxRows: logMaxRows(mergedLog),
    });
  }

  if (path === '/admin/api/logs/clear' && method === 'POST') {
    if (!hasKv) return adminJson({ ok: false, message: '未绑定 KV' }, 400);

    await env.SETTINGS.delete(LOG_KV_KEY);
    logBuffer = [];

    return adminJson({ ok: true, message: '日志已清空' });
  }

  if (path === '/admin/api/logs/prune' && method === 'POST') {
    if (!hasKv) return adminJson({ ok: false, message: '未绑定 KV' }, 400);

    const mergedLog = await effectiveEnv(env);
    const result = await pruneLogs(mergedLog);

    return adminJson({ ok: true, message: '已清理：' + result.before + ' → ' + result.after + ' 条' });
  }

  return adminJson({ ok: false, message: 'not found' }, 404);
}

/** 后台页面：骨架 + 前端脚本，状态由 /admin/api/state 驱动。 */
/**
 * 设备用户页面（/owner）：登录卡 + 自己那一行的配置面板。
 * 与 adminPage 完全分开：这里没有管理员口令入口，/admin 也没有设备登录。
 */
function ownerPage() {
  const state = JSON.stringify({ bases: BASES }).replace(/<\//g, '<\\/');

  return `<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TTCoin 设备管理</title>
<style>
:root{--bg:#f4f6f8;--card:#fff;--line:#e2e7ec;--text:#1c2733;--muted:#6b7a89;--accent:#0b7285;--ok:#0b5f59;--bad:#a61e4d;--warn:#946c00}
*{box-sizing:border-box}
html,body{max-width:100%;overflow-x:hidden}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;padding:24px 16px 60px}
.auth-wrap{max-width:380px;margin:8vh auto 0}
.wrap{max-width:640px;margin:0 auto;display:none}
h1{font-size:19px;margin:0 0 4px}
.sub{color:var(--muted);font-size:12.5px;margin-bottom:18px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px 18px;margin-bottom:14px}
.card h2{font-size:15px;margin:0 0 10px}
label{display:block;font-size:12.5px;color:var(--muted);margin:10px 0 4px}
input[type=text],input[type=number],select{width:100%;padding:7px 10px;border:1px solid var(--line);border-radius:7px;font:inherit;background:#fff;color:var(--text)}
input[type=number]{max-width:110px}
.mono{font-family:ui-monospace,Consolas,monospace}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.hint{font-size:12px;color:var(--muted);margin-top:6px}
button{font:inherit;border:1px solid var(--line);background:#fff;color:var(--text);border-radius:7px;padding:7px 14px;cursor:pointer}
button:hover{border-color:var(--accent);color:var(--accent)}
button.primary{background:var(--accent);border-color:var(--accent);color:#fff}
button.primary:hover{opacity:.9;color:#fff}
button:disabled{opacity:.5;cursor:default}
.msg{padding:9px 12px;border-radius:8px;font-size:13px;margin:10px 0;display:none}
.msg.ok{background:#e6f4f1;color:var(--ok);display:block}
.msg.bad{background:#fbe9ef;color:var(--bad);display:block}
.msg.warn{background:#fdf6e3;color:var(--warn);display:block;white-space:pre-line}
.preview{background:#10222b;color:#d8e6ee;border-radius:8px;padding:10px 12px;font:12px/1.7 ui-monospace,Consolas,monospace;white-space:pre-wrap;word-break:break-all;display:none}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px}
@media(max-width:480px){body{padding:14px 10px 40px}.card{padding:13px 12px}input[type=number]{max-width:none;width:100%}}
</style>
</head>
<body>
<div id="login" class="auth-wrap">
  <div class="card">
    <h1>TTCoin 设备管理</h1>
    <div class="sub">输入自己的设备 ID 和管理员发的管理码</div>
    <label>设备 ID</label>
    <input type="text" id="ownerIdIn" class="mono" maxlength="32" placeholder="TC…" autocomplete="off">
    <label>管理码</label>
    <input type="text" id="ownerCodeIn" class="mono" maxlength="32" placeholder="管理员发给你那个" autocomplete="off">
    <div class="row" style="margin-top:14px"><button class="primary" id="ownerLoginBtn" style="width:100%">登录</button></div>
    <div class="hint">只能改自己设备的显示配置，看不到全局设置和日志；管理码找管理员要。管理员后台在 /admin，两套登录互不相干。</div>
  </div>
</div>

<div id="panel" class="wrap">
  <h1>我的行情配置 · 设备 <span id="ownerTag" class="mono"></span></h1>
  <div class="sub">改动只影响这台设备<span class="saved"></span></div>
  <div id="msg" class="msg"></div>
  <div class="card">
    <label>模式</label>
    <select id="oMode"><option value="">跟随默认</option><option value="scroll">scroll 滚屏</option><option value="fixed">fixed 固定</option></select>
    <label>固定币（fixed 模式生效）</label>
    <select id="oFixed"></select>
    <label>主币种（排最前，可多个，逗号分隔）</label>
    <input type="text" id="oCoin" placeholder="留空 = 继承默认">
    <div class="row" style="margin-top:10px">
      <div><label>条数上限（scroll 模式）</label><input type="number" id="oTopn" min="0" max="11" placeholder="留空 = 继承" style="max-width:110px"></div>
    </div>
    <label>顺序（可选，逗号分隔币种）</label>
    <input type="text" id="oOrder" placeholder="留空 = 继承默认顺序">
    <label>涨幅口径</label>
    <select id="oChg">
      <option value="">跟随默认</option>
      <option value="24h">24h（滚动 24 小时）</option>
      <option value="tz">自然日（按时区）</option>
    </select>
    <div class="row" style="margin-top:10px">
      <div><label>自然日时区偏移</label><input type="text" id="oTz" placeholder="留空 = 跟随默认（8 / -5 / 5:30）" style="max-width:220px"></div>
    </div>
    <div class="hint">自然日：以「该时区当日 00:00」开盘价为基准重算涨幅；时区只在这台选自然日（或全局默认就是自然日）时生效。留空的项 = 继承管理员的全局默认；主币种（coin）优先于固定币（fixed）。</div>
    <div class="actions">
      <button class="primary" id="oSave">保存并生效</button>
      <button id="oPreview">预览</button>
      <button id="oLogout">退出</button>
    </div>
    <div class="preview" id="oPvOut"></div>
  </div>
</div>
<script>
const CFG = ${state};
const API = '/owner/api/';
const $ = id => document.getElementById(id);

function show(el, on){ el.style.display = on ? '' : 'none'; }
function msg(text, cls){
  const el = $('msg');
  el.textContent = text || '';
  el.className = 'msg' + (cls ? ' ' + cls : '');
  if (!text) el.className = 'msg';
}
async function post(name, body, method){
  const m = method || 'POST';
  const init = { method: m, headers: { 'Content-Type': 'application/json' } };
  if (m !== 'GET' && body !== undefined && body !== null) init.body = JSON.stringify(body);
  const r = await fetch(API + name, init);
  let j = null;
  try { j = await r.json(); } catch (e) { j = { ok: false, message: 'HTTP ' + r.status }; }
  return j;
}
function baseOptions(sel, placeholder){
  sel.innerHTML = '<option value="">' + placeholder + '</option>' +
    CFG.bases.map(b => '<option value="' + b + '">' + b + '</option>').join('');
}

async function ownerBoot(){
  const j = await post('get', null, 'GET');
  if (!j.ok) { show($('login'), true); show($('panel'), false); return; }
  show($('login'), false);
  show($('panel'), true);
  $('ownerTag').textContent = j.id;
  const one = j.one || {};
  $('oMode').value = one.mode || '';
  baseOptions($('oFixed'), '（留空 = 继承默认）');
  $('oFixed').value = one.fixed || '';
  $('oCoin').value = one.coin || '';
  $('oTopn').value = (one.topN === undefined || one.topN === null) ? '' : one.topN;
  $('oOrder').value = one.order || '';
  $('oChg').value = one.change || '';
  $('oTz').value = one.tz || '';
}
function ownerCollect(){
  const over = {};
  if ($('oMode').value) over.mode = $('oMode').value;
  if ($('oFixed').value) over.fixed = $('oFixed').value;
  const cn = $('oCoin').value.trim(); if (cn) over.coin = cn;
  const tn = $('oTopn').value.trim(); if (tn !== '') over.topN = parseInt(tn, 10);
  const od = $('oOrder').value.trim(); if (od) over.order = od;
  const chg = $('oChg').value; if (chg) over.change = chg;
  const tz = $('oTz').value.trim(); if (tz) over.tz = tz;
  return over;
}
$('ownerLoginBtn').onclick = async () => {
  const j = await post('login', { id: $('ownerIdIn').value.trim(), code: $('ownerCodeIn').value.trim() });
  if (j.ok) ownerBoot(); else msg(j.message || '登录失败', 'bad');
};
$('ownerCodeIn').addEventListener('keydown', e => { if (e.key === 'Enter') $('ownerLoginBtn').click(); });
$('oSave').onclick = async () => {
  const btn = $('oSave');
  btn.disabled = true;
  const j = await post('save', { over: ownerCollect() });
  btn.disabled = false;
  if (j.ok) msg(j.message + ((j.warnings || []).length ? '\\n⚠ ' + j.warnings.join('\\n⚠ ') : ''), j.warnings && j.warnings.length ? 'warn' : 'ok');
  else msg((j.errors || [j.message]).join('\\n'), 'bad');
};
$('oPreview').onclick = async () => {
  const out = $('oPvOut');
  out.style.display = 'block';
  try {
    const j = await post('preview', { over: ownerCollect() });
    if (!j.ok) { out.textContent = (j.errors || [j.message || '预览失败']).join('\\n'); return; }
    out.textContent = [
      'mode=' + j.config.mode + '  fixed=' + (j.config.fixed || '-') + '  topN=' + j.config.topN + '  coin=' + (j.config.coin || '-'),
      '涨幅口径=' + (j.config.chg ? (j.config.chg === 'tz' ? '自然日' : '24h') : '跟随全局默认') +
        (j.config.chgTzLabel ? '  时区=' + j.config.chgTzLabel : ''),
      'order=' + j.config.order,
      '表盘会收到 ' + j.count + ' 条：' + j.coins.join(' → '),
    ].join('\\n');
  } catch (e) {
    out.textContent = '预览请求失败：' + (e && e.message ? e.message : e);
  }
};
$('oLogout').onclick = async () => {
  await post('logout');
  show($('panel'), false);
  show($('login'), true);
};
ownerBoot();
</script>
</body>
</html>`;
}

function adminPage() {
  const state = JSON.stringify({ bases: BASES }).replace(/<\//g, '<\\/');

  return `<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TTCoin Worker 后台</title>
<style>
:root{--bg:#f4f6f8;--card:#fff;--line:#e2e7ec;--text:#1c2733;--muted:#6b7a89;--accent:#0b7285;--ok:#0b5f59;--bad:#a61e4d;--warn:#946c00}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;padding:24px 16px 80px}
.wrap{max-width:900px;margin:0 auto}
h1{font-size:19px;margin:0 0 4px}
.sub{color:var(--muted);font-size:12.5px;margin-bottom:18px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px 18px;margin-bottom:14px}
.card h2{font-size:15px;margin:0 0 10px}
label{display:block;font-size:12.5px;color:var(--muted);margin:10px 0 4px}
input[type=text],input[type=password],input[type=number],select{width:100%;max-width:340px;padding:7px 10px;border:1px solid var(--line);border-radius:7px;font:inherit;background:#fff;color:var(--text)}
input[type=number]{max-width:110px}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.hint{font-size:12px;color:var(--muted);margin-top:6px}
code{background:#eef2f5;padding:1px 5px;border-radius:4px;font-size:12px}
button{font:inherit;border:1px solid var(--line);background:#fff;color:var(--text);border-radius:7px;padding:7px 14px;cursor:pointer}
button:hover{border-color:var(--accent);color:var(--accent)}
button.primary{background:var(--accent);border-color:var(--accent);color:#fff}
button.primary:hover{opacity:.9;color:#fff}
button.tiny{padding:2px 8px;font-size:12px}
button:disabled{opacity:.5;cursor:default}
.chips{display:flex;flex-wrap:wrap;gap:6px}
.chip{border:1px solid var(--line);border-radius:999px;padding:3px 12px;font-size:13px;cursor:pointer;user-select:none;background:#fff}
.chip.on{background:var(--accent);border-color:var(--accent);color:#fff}
.chip .ord{font-size:11px;opacity:.85;margin-right:4px}
table{border-collapse:collapse;width:100%}
th,td{border-bottom:1px solid var(--line);padding:6px 6px;font-size:12.5px;text-align:left;vertical-align:middle}
th{color:var(--muted);font-weight:600;white-space:nowrap}
td input,td select{max-width:none;padding:5px 8px}
#idsTable td input,#idsTable td select{width:100%}
#idsTable td:last-child{white-space:nowrap;text-align:right}
td .num{max-width:70px}
/* 按 ID 表新加的「时区」列：窄一点，别把整张表撑宽 */
#idsTable .i-tz{max-width:78px}
/* 9 列的按 ID 表在窄一点的桌面窗口里可能超出卡片：给个横向滚动兜底 */
.table-scroll{overflow-x:auto}
.msg{padding:9px 12px;border-radius:8px;font-size:13px;margin:10px 0;display:none}
.msg.ok{background:#e6f4f1;color:var(--ok);display:block}
.msg.bad{background:#fbe9ef;color:var(--bad);display:block}
.msg.warn{background:#fdf6e3;color:var(--warn);display:block;white-space:pre-line}
ul{margin:6px 0 0;padding-left:18px}
.preview{background:#10222b;color:#d8e6ee;border-radius:8px;padding:10px 12px;font:12px/1.7 ui-monospace,Consolas,monospace;white-space:pre-wrap;word-break:break-all;display:none}
.saved{font-size:12px;color:var(--muted)}
.log-wrap{max-height:420px;overflow:auto;border:1px solid var(--line);border-radius:8px;margin-top:10px}
.log-wrap table{margin:0}
.log-wrap th{position:sticky;top:0;background:#f7f9fa;z-index:1}
.log-wrap td{font:12px/1.5 ui-monospace,Consolas,monospace;white-space:nowrap}
/* UID 是长串：给个上限宽度 + 任意断点折行，完整显示又不把表格撑爆 */
.log-wrap td.uid{max-width:180px;white-space:normal;word-break:break-all;line-height:1.4}
.badge{display:inline-block;padding:0 6px;border-radius:4px;font-size:11px;border:1px solid var(--line);width:max-content;max-width:100%}
.badge.bad{color:var(--bad);border-color:#e8c3d1;background:#fbe9ef}
.badge.ok{color:var(--ok);border-color:#bfe0da;background:#e6f4f1}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px}

/* —— 窄屏：输入全宽、表格转卡片（手机上一行一卡，不再横向溢出） —— */
@media(max-width:700px){
  body{padding:14px 10px 24px}
  h1{font-size:17px}
  .sub{margin-bottom:14px}
  .card{padding:13px 12px;margin-bottom:12px}
  input[type=text],input[type=password],input[type=number],select{max-width:none;width:100%}
  .row>div{flex:1 1 100%;white-space:normal!important;min-width:0}
  .actions{position:sticky;bottom:0;background:var(--bg);padding:8px 0 2px;display:grid;grid-template-columns:1fr 1fr;gap:8px;z-index:2}
  .actions button{margin:0!important;width:100%;white-space:normal;line-height:1.35;padding:9px 6px}
  .actions #saveBtn{grid-column:1/-1}
  /* 按 ID 表格 / 设备管理码表 → 一行一张卡，每个字段带小标签，输入框占满剩余宽度 */
  #idsTable,#idsTable tbody,#codesTable,#codesTable tbody{display:block}
  #idsTable thead,#codesTable thead{display:none}
  #idsTable tr,#codesTable tr{display:block;border:1px solid var(--line);border-radius:9px;padding:8px 10px 4px;margin-bottom:10px;background:#fbfcfd}
  #idsTable td,#codesTable td{display:grid;grid-template-columns:62px 1fr;gap:8px;align-items:center;border:0;padding:5px 0;font-size:13px;white-space:normal;min-width:0}
  #idsTable td::before,#codesTable td::before{content:attr(data-label);font-size:12px;color:var(--muted)}
  #idsTable td:last-child,#codesTable td:last-child{display:flex;justify-content:flex-end;gap:8px;padding:8px 0 4px;border-top:1px dashed var(--line);margin-top:4px}
  #idsTable td:last-child::before,#codesTable td:last-child::before{content:''}
  #idsTable td input,#idsTable td select,#codesTable td input,#codesTable td select{width:100%;max-width:none;font-size:13px;padding:7px 9px}
  /* 请求日志 → 紧凑的「字段:值」流式布局（一行一卡，但不逐个字段占一行） */
  .log-wrap{max-height:none;border:0;overflow:visible}
  #logTable,#logTable tbody{display:block}
  #logTable thead{display:none}
  #logTable tr{display:flex;flex-wrap:wrap;gap:4px 12px;align-items:baseline;border:1px solid var(--line);border-radius:9px;padding:7px 10px;margin-bottom:8px;background:#fbfcfd}
  #logTable td{display:inline-flex;gap:4px;border:0;padding:0;font-size:12px;white-space:normal;word-break:break-all;min-width:0}
  #logTable td::before{content:attr(data-label);font-size:11px;color:var(--muted);flex:none}
  /* 手机上 UID 不设宽度上限，独占一行完整折行 */
  #logTable td.uid{max-width:none;flex:1 1 100%;align-items:baseline}
}
</style>
</head>
<body>
<div class="wrap">
  <h1>TTCoin Worker 后台</h1>
  <div class="sub">配置存 Cloudflare KV，保存后约 30 秒全局生效，无需 wrangler deploy<span id="savedInfo" class="saved"></span></div>

  <div id="msg" class="msg"></div>

  <div id="setup" class="card" style="display:none">
    <h2>首次设置（一次性）</h2>
    <div id="setupBody"></div>
  </div>

  <div id="login" class="card" style="display:none">
    <h2>管理员登录</h2>
    <label>管理口令（ADMIN_TOKEN）</label>
    <input type="password" id="pass" placeholder="wrangler secret put ADMIN_TOKEN 时设置的那个">
    <div class="row" style="margin-top:12px"><button class="primary" id="loginBtn">登录</button></div>
    <div class="hint">设备用户不走这里：入口是 <code>/owner</code>（设备 ID + 管理码登录，与管理员后台分开）。</div>
  </div>

  <div id="main" style="display:none">
    <div class="card">
      <h2>行情默认配置（三端共用）</h2>
      <label>模式</label>
      <div class="row">
        <label class="chip" style="display:inline-flex;align-items:center;gap:6px;margin:0"><input type="radio" name="mode" value="scroll">scroll 滚屏</label>
        <label class="chip" style="display:inline-flex;align-items:center;gap:6px;margin:0"><input type="radio" name="mode" value="fixed">fixed 固定</label>
      </div>
      <label>固定币（fixed 模式生效）</label>
      <select id="fixed"></select>
      <label>滚屏顺序（点选顺序 = 轮播顺序，再点一次取消）</label>
      <div class="chips" id="orderChips"></div>
      <div class="hint">全不勾 = 不覆盖，走 wrangler.toml 的 FACE_ORDER</div>
      <div class="row" style="margin-top:8px">
        <div><label>条数上限（scroll）</label><input type="number" id="topn" min="0" max="11" placeholder="0" style="max-width:110px"><span class="hint" style="display:block;margin-top:4px">0 = 按 ORDER 全给</span></div>
      </div>
      <label>主币种 coin（三端共用，逗号分隔多币优先级，如 BTC,ETH；留空 = 默认币种）</label>
      <input type="text" id="coin" placeholder="留空 = 不覆盖">
      <div class="hint">注意：coin 优先于行内 fixed——全局配了 coin，按 ID 行里只写 fixed 会被顶掉</div>
    </div>

    <div class="card">
      <h2>涨幅与日志</h2>
      <label>涨幅口径</label>
      <div class="row">
        <label style="display:inline-flex;align-items:center;gap:6px;margin:0"><input type="radio" name="chg" value="24h">24h（滚动 24 小时）</label>
        <label style="display:inline-flex;align-items:center;gap:6px;margin:0"><input type="radio" name="chg" value="tz">tz（指定时区自然日）</label>
      </div>
      <div class="row"><div><label>时区偏移（tz 模式）</label><input type="text" id="tz" placeholder="8 / 0 / -5 / 5:30" style="max-width:140px"></div>
      <div><label>请求日志</label><select id="logreq" style="max-width:120px"><option value="1">开</option><option value="0">关</option></select></div></div>
      <div class="hint">「开」= 写 <code>wrangler tail</code> 日志 + 写入下方 KV 日志页</div>

      <div class="row" style="margin-top:12px">
        <div><label>KV 日志存储</label><select id="logkv" style="max-width:120px"><option value="1">开</option><option value="0">关</option></select></div>
        <div><label>保留天数</label><input type="number" id="logkeep" min="1" max="90" placeholder="7" style="max-width:110px"></div>
        <div><label>最多条数</label><input type="number" id="logmax" min="20" max="1000" placeholder="200" style="max-width:110px"></div>
      </div>
      <div class="hint">关闭后只保留实时日志（wrangler tail），不占 KV 写入额度</div>
    </div>

    <div class="card">
      <h2>按 ID 配置（?id= 登记了才覆盖默认一套）</h2>
      <div class="table-scroll"><table id="idsTable"><thead><tr>
        <th style="width:20%">ID（≤32 位）</th><th style="width:9%">模式</th><th style="width:9%">固定币</th><th style="width:14%">主币种</th><th style="width:7%">条数</th><th style="width:13%">顺序</th><th style="width:10%">涨幅口径</th><th style="width:9%">时区</th><th style="width:72px"></th>
      </tr></thead><tbody></tbody></table></div>
      <div class="row" style="margin-top:10px"><button id="addId">+ 添加 ID</button></div>
      <div class="hint">留空的项 = 不覆盖；整行都空 = 只做登记（日志里不再标 unknown）。涨幅口径单独设了就用这台的，没设跟随上面的全局口径；「时区」只在自然日口径下生效（8 / -5 / 5:30），留空 = 跟随全局时区。</div>
    </div>

    <div class="card">
      <h2>设备管理码</h2>
      <div class="row" style="margin-top:6px">
        <select id="codePick" style="max-width:340px"></select>
        <button id="codeGen">为选中 ID 生成管理码</button>
      </div>
      <div class="log-wrap">
        <table id="codesTable"><thead><tr><th>ID</th><th>管理码</th><th>生成时间</th><th></th></tr></thead><tbody></tbody></table>
      </div>
      <div class="hint">设备用户打开 <code>/owner</code>（与管理员后台分开的独立入口）输入 ID + 管理码，只能改自己那一行的独立配置（模式/固定币/主币种/条数/顺序/涨幅口径/时区），看不到全局配置和日志。下拉列表来自上面「按 ID 配置」里登记的 ID；重新生成会立刻作废旧码。</div>
    </div>

    <div class="card">
      <h2>预览（保存前可先试算）</h2>
      <div class="row">
        <input type="text" id="pvId" placeholder="行情配置 ID（留空 = 默认一套）" style="max-width:280px">
        <button id="pvCur">预览当前生效</button>
        <button id="pvDraft">预览表单草稿</button>
      </div>
      <div class="preview" id="pvOut"></div>
    </div>

    <div class="card">
      <h2>请求日志（KV 里最近 200 条）</h2>
      <div class="row" style="margin-bottom:2px">
        <button id="logRefresh">刷新</button>
        <button id="logPrune">清理过期</button>
        <button id="logClear">清空</button>
      </div>
      <div class="log-wrap">
        <table id="logTable"><thead><tr>
          <th>时间</th><th>类型</th><th>ID</th><th>UID</th><th>IP</th><th>缓存</th><th>币种</th>
        </tr></thead><tbody></tbody></table>
      </div>
      <div class="hint" id="logMeta" style="margin-top:8px">正在读取…</div>
      <div class="hint">每 2 分钟或攒满 12 条写一次 KV（免费额度 1000 写/天）；写入后约 1 分钟才在其它节点可见。ID 带红底感叹号 = 没在「按 ID 配置」里登记，走的是默认一套。</div>
    </div>

    <div class="actions">
      <button class="primary" id="saveBtn">保存</button>
      <button id="resetBtn">恢复 wrangler.toml 默认</button>
      <button id="logoutBtn">退出登录</button>
    </div>
  </div>
</div>
<script>
const CFG = ${state};
const API = location.pathname.replace(/\\/+$/, '') + '/api/';
const $ = id => document.getElementById(id);
let state = null;

function show(el, on){ el.style.display = on ? '' : 'none'; }
function msg(text, cls){
  const el = $('msg');
  el.textContent = text || '';
  el.className = 'msg' + (cls ? ' ' + cls : '');
  if (!text) el.className = 'msg';
}
async function post(name, body, method){
  const m = method || 'POST';
  const init = { method: m, headers: { 'Content-Type': 'application/json' } };
  // GET 不允许带 body（浏览器会直接抛 TypeError），只有非 GET 才序列化
  if (m !== 'GET' && body !== undefined && body !== null) init.body = JSON.stringify(body);
  const r = await fetch(API + name, init);
  let j = null;
  try { j = await r.json(); } catch (e) { j = { ok: false, message: 'HTTP ' + r.status }; }
  return j;
}
function baseOptions(sel, placeholder){
  sel.innerHTML = '<option value="">' + placeholder + '</option>' +
    CFG.bases.map(b => '<option value="' + b + '">' + b + '</option>').join('');
}
function fixedSel(){ return document.querySelector('input[name=mode]:checked').value; }

// —— 顺序 chips：**点选顺序即轮播顺序**（先点的排前面），再点一次取消 ——
// 注意：已选的 chip 必须按「已选顺序」排在最前，否则读回来永远是 BASES 顺序
function renderOrderChips(selected){
  const box = $('orderChips');
  box.innerHTML = '';
  const ordered = selected.filter(b => CFG.bases.includes(b));
  const rest = CFG.bases.filter(b => !ordered.includes(b));

  const addChip = (base, idx) => {
    const chip = document.createElement('span');
    chip.className = 'chip' + (idx ? ' on' : '');
    chip.innerHTML = (idx ? '<span class="ord">' + idx + '</span>' : '') + base;
    chip.onclick = () => {
      if (idx) renderOrderChips(ordered.filter(b => b !== base));
      else renderOrderChips(ordered.concat([base]));
    };
    box.appendChild(chip);
  };

  ordered.forEach((base, i) => addChip(base, i + 1));
  rest.forEach(base => addChip(base, 0));
}
function currentOrder(){
  const out = [];
  document.querySelectorAll('#orderChips .chip.on').forEach(chip => {
    out.push(chip.textContent.replace(/^\\d+/, ''));
  });
  return out;
}


function idRow(one){
  const tr = document.createElement('tr');
  const opt = (list, cur) => list.map(b => '<option value="' + b + '"' + (b === cur ? ' selected' : '') + '>' + b + '</option>').join('');
  const sel = (cur) => '<option value=""></option><option value="scroll"' + (cur === 'scroll' ? ' selected' : '') + '>scroll</option><option value="fixed"' + (cur === 'fixed' ? ' selected' : '') + '>fixed</option>';
  const chg = (cur) => '<option value=""></option><option value="24h"' + (cur === '24h' ? ' selected' : '') + '>24h</option><option value="tz"' + (cur === 'tz' ? ' selected' : '') + '>自然日</option>';
  const fx = one.fixed || '', cn = one.coin || '', od = one.order || '';
  tr.innerHTML =
    '<td data-label="ID"><input type="text" class="i-id" value="' + (one.__id || '') + '" maxlength="32" placeholder="TC…"></td>' +
    '<td data-label="模式"><select class="i-mode">' + sel(one.mode) + '</select></td>' +
    '<td data-label="固定币"><select class="i-fixed"><option value=""></option>' + opt(CFG.bases, fx) + '</select></td>' +
    '<td data-label="主币种"><input type="text" class="i-coin" value="' + cn + '" placeholder="BTC,ETH"></td>' +
    '<td data-label="条数"><input type="number" class="i-topn num" min="0" max="11" value="' + (one.topN === undefined || one.topN === null ? '' : one.topN) + '"></td>' +
    '<td data-label="顺序"><input type="text" class="i-order" value="' + od + '" placeholder="留空=继承"></td>' +
    '<td data-label="涨幅"><select class="i-chg">' + chg(one.change) + '</select></td>' +
    '<td data-label="时区"><input type="text" class="i-tz" value="' + (one.tz || '') + '" placeholder="继承" title="自然日时区偏移：8 / -5 / 5:30，留空 = 跟随全局"></td>' +
    '<td style="white-space:nowrap"><button type="button" class="tiny pv">预览</button> <button type="button" class="tiny del">×</button></td>';
  tr.querySelector('.del').onclick = () => tr.remove();
  tr.querySelector('.pv').onclick = () => preview({ settings: collect(), id: tr.querySelector('.i-id').value });
  return tr;
}
function renderIds(ids){
  const tbody = $('idsTable').querySelector('tbody');
  tbody.innerHTML = '';
  Object.entries(ids || {}).forEach(([id, one]) => tbody.appendChild(idRow(Object.assign({}, one, { __id: id }))));
  // 「设备管理码」下拉来自这里登记的 ID，登记变化后联动刷新
  if (document.querySelector('#codesTable')) renderCodes();
}
function collectIds(){
  const ids = {};
  document.querySelectorAll('#idsTable tbody tr').forEach(tr => {
    const id = tr.querySelector('.i-id').value.replace(/[^A-Za-z0-9]/g, '').slice(0, 32);
    if (!id) return;
    const one = {};
    const mode = tr.querySelector('.i-mode').value, fx = tr.querySelector('.i-fixed').value;
    const cn = tr.querySelector('.i-coin').value.trim(), od = tr.querySelector('.i-order').value.trim();
    const tn = tr.querySelector('.i-topn').value.trim();
    const chg = tr.querySelector('.i-chg') ? tr.querySelector('.i-chg').value : '';
    const tzEl = tr.querySelector('.i-tz');
    const tz = tzEl ? tzEl.value.trim() : '';
    if (mode) one.mode = mode;
    if (fx) one.fixed = fx;
    if (cn) one.coin = cn;
    if (od) one.order = od;
    if (tn !== '') one.topN = parseInt(tn, 10);
    if (chg) one.change = chg;
    if (tz) one.tz = tz;
    if (Object.keys(one).length) ids[id] = one;
  });
  return ids;
}
function collect(){
  const order = currentOrder();
  return {
    FACE_MODE: fixedSel(),
    FACE_FIXED: $('fixed').value,
    FACE_ORDER: order.join(','),
    FACE_TOP_N: $('topn').value.trim(),
    FACE_COIN: $('coin').value.trim(),
    CHANGE_MODE: document.querySelector('input[name=chg]:checked').value,
    CHANGE_TZ: $('tz').value.trim(),
    LOG_REQUESTS: $('logreq').value,
    LOG_KV: $('logkv').value,
    LOG_KV_KEEP: $('logkeep').value.trim(),
    LOG_KV_MAX: $('logmax').value.trim(),
    FACE_IDS: collectIds(),
  };
}
function fill(values, ids){
  document.querySelectorAll('input[name=mode]').forEach(r => r.checked = r.value === values.FACE_MODE);
  baseOptions($('fixed'), '（留空 = ORDER 第一项）');
  $('fixed').value = values.FACE_FIXED || '';
  renderOrderChips(String(values.FACE_ORDER || '').split(',').map(s => s.trim()).filter(Boolean));
  $('topn').value = values.FACE_TOP_N || '';
  $('coin').value = values.FACE_COIN || '';
  document.querySelectorAll('input[name=chg]').forEach(r => r.checked = r.value === values.CHANGE_MODE);
  $('tz').value = values.CHANGE_TZ || '';
  $('logreq').value = values.LOG_REQUESTS === '0' ? '0' : '1';
  $('logkv').value = values.LOG_KV === '0' ? '0' : '1';
  $('logkeep').value = values.LOG_KV_KEEP || '';
  $('logmax').value = values.LOG_KV_MAX || '';
  renderIds(ids || {});
}

async function preview(body){
  const out = $('pvOut');
  // 注意：.preview 类在样式表里是 display:none，必须用行内样式 'block' 覆盖，
  // 不能用 show(out, true)（它清空行内样式，会回落回 CSS 的 none）
  out.style.display = 'block';
  try {
    const j = await post('preview', body || {});
    if (!j.ok) { out.textContent = (j.errors || [j.message || '预览失败']).join('\\n'); return; }
    const lines = [];
    lines.push('id=' + (j.id || '(默认一套)') + '  known=' + (j.known ? '已登记' : '未登记(走默认)'));
    lines.push('mode=' + j.config.mode + '  fixed=' + (j.config.fixed || '-') + '  topN=' + j.config.topN + '  coin=' + (j.config.coin || '-'));
    lines.push('涨幅口径=' + (j.config.chg ? (j.config.chg === 'tz' ? '自然日' : '24h') : '跟随全局默认') + (j.config.chgTz ? '  时区=' + j.config.chgTz : ''));
    lines.push('order=' + j.config.order);
    lines.push('输出 ' + j.count + ' 条：' + j.coins.join(' → '));
    if (j.warnings && j.warnings.length) lines.push('⚠ ' + j.warnings.join('\\n⚠ '));
    lines.push(j.curl);
    out.textContent = lines.join('\\n');
  } catch (e) {
    out.textContent = '预览请求失败：' + (e && e.message ? e.message : e);
  }
}

// —— 请求日志（KV）——
function esc(v){
  return String(v === undefined || v === null ? '' : v).replace(/[&<>"']/g, function(c){
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
  });
}
function fmtTime(sec){
  const d = new Date(sec * 1000), p = function(n){ return String(n).padStart(2, '0'); };
  return p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
}
function renderLogs(j){
  const tb = $('logTable').querySelector('tbody');
  tb.innerHTML = '';
  if (!j.rows.length) {
    const empty = document.createElement('tr');
    empty.innerHTML = '<td colspan="7" style="color:var(--muted);text-align:center;padding:14px">暂无记录（有请求后最多 2 分钟出现）</td>';
    tb.appendChild(empty);
  }
  j.rows.forEach(function(r){
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td data-label="时间">' + fmtTime(r.t) + '</td>' +
      '<td data-label="类型">' + esc(r.y) + '</td>' +
      '<td data-label="ID">' + (r.i ? '<span class="badge ' + (r.k ? 'ok' : 'bad') + '">' + esc(r.i) + (r.k ? '' : ' !') + '</span>' : '—') + '</td>' +
      // UID 完整显示（不再截断成 10 位），靠 .uid 的 break-all 折行自适应；
      // 长按/悬停还能看 title 里的原值，方便复制排查
      '<td data-label="UID" class="uid"' + (r.u ? ' title="' + esc(r.u) + '"' : '') + '>' +
        (r.u ? esc(String(r.u)) : '—') + '</td>' +
      '<td data-label="IP">' + (esc(r.p) || '—') + (r.c ? ' <span class="hint">' + esc(r.c) + '</span>' : '') + '</td>' +
      '<td data-label="缓存">' + (esc(r.h) || '—') + '</td>' +
      '<td data-label="币种">' + (esc(r.n) || '—') + '</td>';
    tb.appendChild(tr);
  });
  $('logMeta').textContent = '共 ' + j.total + ' 条 · 保留 ' + j.keepDays + ' 天 · 上限 ' + j.maxRows + ' 条' + (j.enabled ? '' : ' · KV 写入已关闭');
}
async function loadLogs(){
  try {
    const j = await post('logs?limit=200', null, 'GET');
    if (!j.ok) { $('logMeta').textContent = j.message || '读取失败'; return; }
    renderLogs(j);
  } catch (e) {
    $('logMeta').textContent = '读取失败：' + (e && e.message ? e.message : e);
  }
}

async function boot(){
  const s = await post('state', null, 'GET');
  if (!s.ok) { msg('状态读取失败', 'bad'); return; }
  state = s;
  show($('setup'), !s.tokenSet || !s.kvBound);
  if (!s.tokenSet || !s.kvBound) {
    const steps = [];
    if (!s.kvBound) steps.push('① 绑定 KV：<code>npx wrangler kv namespace create SETTINGS</code>，把输出的 id 填进 wrangler.toml 的 <code>[[kv_namespaces]]</code>，再 <code>npx wrangler deploy</code>');
    if (!s.tokenSet) steps.push('② 设置登录口令：<code>npx wrangler secret put ADMIN_TOKEN</code>（输入一个长口令）');
    $('setupBody').innerHTML = '<ul><li style="margin:6px 0">' + steps.join('</li><li style="margin:6px 0">') + '</li></ul><div class="hint">没设置完之前行情出口不受影响（走 wrangler.toml 默认）。</div>';
    return;
  }
  show($('login'), !s.authed);
  show($('main'), s.authed);
  if (!s.authed) return;
  const cfg = await post('settings', null, 'GET');
  if (cfg.ok) {
    fill(cfg.values, cfg.ids);
    $('savedInfo').textContent = cfg.savedKeys.length ? ' · 已覆盖 ' + cfg.savedKeys.length + ' 项' : ' · 当前全部走 wrangler.toml 默认';
  }
  baseOptions($('fixed'), '（留空 = ORDER 第一项）');
  await loadCodes();
  await loadLogs();
}

// —— 设备管理码（管理员）——
let CODES = {};
async function loadCodes(){
  const j = await post('codes', null, 'GET');
  CODES = j.ok ? (j.codes || {}) : {};
  renderCodes();
}
function renderCodes(){
  // 下拉：上面表格里已填的 ID ∪ 已有码的 ID
  const pick = $('codePick');
  const cur = pick.value;
  const names = {};
  document.querySelectorAll('#idsTable tbody tr .i-id').forEach(el => {
    const v = el.value.trim().replace(/[^A-Za-z0-9]/g, '');
    if (v) names[v] = 1;
  });
  Object.keys(CODES).forEach(k => { names[k] = 1; });
  pick.innerHTML = '<option value="">— 选一个设备 ID —</option>' +
    Object.keys(names).sort().map(id =>
      '<option value="' + esc(id) + '">' + esc(id) + (CODES[id] ? '（已有码）' : '') + '</option>'
    ).join('');
  if (cur && names[cur]) pick.value = cur;

  const tb = document.querySelector('#codesTable tbody');
  const keys = Object.keys(CODES).sort();
  if (!keys.length) {
    tb.innerHTML = '<tr><td colspan="4" style="color:var(--muted);text-align:center;padding:10px">还没有生成过管理码</td></tr>';
    return;
  }
  tb.innerHTML = '';
  keys.forEach(id => {
    const c = CODES[id] || {};
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td data-label="ID">' + esc(id) + '</td>' +
      '<td data-label="管理码"><b>' + esc(c.code || '') + '</b></td>' +
      '<td data-label="生成时间">' + (c.t ? fmtTime(c.t) : '—') + '</td>' +
      '<td data-label="操作"><button type="button" class="tiny cp">复制</button> <button type="button" class="tiny rg">重新生成</button> <button type="button" class="tiny dl">×</button></td>';
    tr.querySelector('.cp').onclick = () => {
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(c.code || '');
      msg('已复制：' + (c.code || ''), 'ok');
    };
    tr.querySelector('.rg').onclick = () => codeSet(id, 'auto');
    tr.querySelector('.dl').onclick = async () => {
      if (!confirm('删除 ' + id + ' 的管理码？对方将无法再登录。')) return;
      codeSet(id, '');
    };
    tb.appendChild(tr);
  });
}
async function codeSet(id, code){
  const j = await post('codes/set', { id: id, code: code });
  if (j.ok) {
    if (code) {
      CODES[id] = { code: j.code, t: Math.floor(Date.now() / 1000) };
      msg('管理码已生成：' + j.code + '（把 ID 和码一起发给对方）', 'ok');
    } else {
      delete CODES[id];
      msg(j.message || '已删除', 'ok');
    }
    renderCodes();
  } else {
    msg(j.message || '操作失败', 'bad');
  }
}
$('codeGen').onclick = () => {
  const id = $('codePick').value.trim();
  if (!id) { msg('先从下拉里选一个设备 ID（来自「按 ID 配置」里登记的 ID）', 'bad'); return; }
  codeSet(id, 'auto');
};

$('loginBtn').onclick = async () => {
  const j = await post('login', { token: $('pass').value });
  if (j.ok) { msg('已登录', 'ok'); boot(); } else msg(j.message || '登录失败', 'bad');
};
$('pass').addEventListener('keydown', e => { if (e.key === 'Enter') $('loginBtn').click(); });

$('addId').onclick = () => document.querySelector('#idsTable tbody').appendChild(idRow({}));

$('pvCur').onclick = () => preview({ id: $('pvId').value });
$('pvDraft').onclick = () => preview({ settings: collect(), id: $('pvId').value });

$('logRefresh').onclick = loadLogs;
$('logPrune').onclick = async () => {
  const j = await post('logs/prune');
  msg(j.message || (j.ok ? '已清理' : '清理失败'), j.ok ? 'ok' : 'bad');
  loadLogs();
};
$('logClear').onclick = async () => {
  if (!confirm('清空 KV 里的全部请求日志？')) return;
  const j = await post('logs/clear');
  msg(j.message || (j.ok ? '已清空' : '清空失败'), j.ok ? 'ok' : 'bad');
  loadLogs();
};

$('saveBtn').onclick = async () => {
  const btn = $('saveBtn');
  btn.disabled = true;
  const j = await post('save', { settings: collect() });
  btn.disabled = false;
  if (j.ok) {
    msg(j.message + ((j.warnings || []).length ? '\\n⚠ ' + j.warnings.join('\\n⚠ ') : ''), j.warnings && j.warnings.length ? 'warn' : 'ok');
    boot();
  } else {
    msg((j.errors || [j.message]).map(e => '· ' + e).join('\\n'), 'bad');
  }
};
$('resetBtn').onclick = async () => {
  if (!confirm('清空 KV 里保存的全部设置，恢复 wrangler.toml 默认？')) return;
  const j = await post('reset');
  if (j.ok) { msg(j.message, 'ok'); boot(); } else msg(j.message || '失败', 'bad');
};
$('logoutBtn').onclick = async () => { await post('logout'); show($('main'), false); show($('login'), true); msg('已退出', 'ok'); };

// 首次加载失败也要给出可见提示，避免只看到一个标题的白页
boot().catch(e => msg('初始化失败：' + (e && e.message ? e.message : e), 'bad'));
</script>
</body>
</html>`;
}
