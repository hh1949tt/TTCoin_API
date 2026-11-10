<?php
/**
 * TTCoin 服务端共享逻辑
 * ------------------------------------------------------------------
 * coin.php（手表接口）与 update_price.php（cron 预热）原本各有约 90%
 * 重复的抓取 / 解析 / 校验 / 写缓存代码，改一次币种要动三个地方。
 * 现在公共部分全部收在这里，两个入口只保留各自的差异：
 * 一个输出 JSON，一个写一行日志。
 */

/**
 * 币种表：Binance 符号 => 手表上显示的简称。
 *
 * 这里是**候选池**：服务端把池子里全部币种都返回，显示哪些、按什么顺序
 * 由手表端（菜单勾选 / 手机端设置）决定。增删候选只需改这一处，
 * 但注意手表端 source/TTCoinDelegate.mc 里也有一份相同的候选表
 * （用于菜单展示），两边要同步。
 */

// 日志等时间戳用哪个时区。PHP 默认跟随时区配置（date.timezone / php.ini），
// Docker 容器里通常是 UTC，date() 会比北京时间慢 8 小时。
// 想改回 UTC（或其他时区）就在 config.php 里：
//   define('TTCOIN_TIMEZONE', 'UTC');
if (!defined('TTCOIN_TIMEZONE')) {
    define('TTCOIN_TIMEZONE', 'Asia/Shanghai');
}

if (!@date_default_timezone_set(TTCOIN_TIMEZONE)) {
    date_default_timezone_set('UTC');   // 时区名写错了别让整个接口挂掉
}

function ttcoin_symbols()
{
    return [
        'BTCUSDT'  => 'BTC',
        'ETHUSDT'  => 'ETH',
        'DOGEUSDT' => 'DOGE',
        'SHIBUSDT' => 'SHIB',		
        'SOLUSDT'  => 'SOL',
        'BNBUSDT'  => 'BNB',
        'XRPUSDT'  => 'XRP',
        'ADAUSDT'  => 'ADA',
        'LINKUSDT' => 'LINK',
        'LTCUSDT'  => 'LTC',
        'AVAXUSDT' => 'AVAX',
    ];
}

// 24hr 行情端点：比 ticker/price 多返回 24 小时涨跌（priceChangePercent）等字段，
// 响应体积约大 1KB/币种，10 个候选约 10KB，对手表经手机转发的链路没有压力。
define('TTCOIN_API_URL', 'https://data-api.binance.vision/api/v3/ticker/24hr');
define('TTCOIN_USER_AGENT', 'TTCryptoGarmin/1.0');

/**
 * 备用上游（同一套 API，只是域名不同）。
 *
 * 为什么要这个：data-api.binance.vision 在国内网络里经常"能解析但连不上"，
 * curl 报 `HTTP 0 Connection timed out`，表现为接口一直走缓存。
 * Binance 的几个公开域名解析到的边缘节点不完全相同，某一个连不通时换一个
 * 往往就通了，因此按顺序失败重试。契约完全一致，解析代码不用改。
 *
 * 首个成功的域名会被记到 <数据目录>/cache/upstream.txt，下次优先用它，
 * 避免每次刷新都先去撞已经死掉的域名。
 */
define('TTCOIN_API_FALLBACKS', [
    'https://api.binance.com/api/v3/ticker/24hr',
    'https://api1.binance.com/api/v3/ticker/24hr',
    'https://api2.binance.com/api/v3/ticker/24hr',
    'https://api3.binance.com/api/v3/ticker/24hr',
    'https://api4.binance.com/api/v3/ticker/24hr',
]);

// 可选：HTTP 代理（如 'http://proxy.example:7890'）。留空 = 直连。
// 服务器到 Binance 全域名都不通时，这是唯一的解法（见 README 排查章节）。
if (!defined('TTCOIN_PROXY')) {
    define('TTCOIN_PROXY', '');
}

// 可选：把域名钉到指定 IP，绕开 DNS 污染。
// 格式 'data-api.binance.vision:443:1.2.3.4;api.binance.com:443:5.6.7.8'
if (!defined('TTCOIN_RESOLVE')) {
    define('TTCOIN_RESOLVE', '');
}

// 缓存有效期（秒）。
// 手表 60 秒才请求一次，10 秒足够吸收多设备并发与突发重试；
// 若用 cron 预热（update_price.php），cron 间隔应 <= 此值，
// 让手表请求基本都命中缓存、完全不触发上游调用。
//
// 允许 config.php / 环境变量覆盖（admin.php 后台就是这么改的），
// 所以这里用 defined 保护——否则 define 会重复，警告文本会混进 JSON 响应。
if (!defined('TTCOIN_CACHE_TTL')) {
    define('TTCOIN_CACHE_TTL', 10);
}

// 过期缓存最多还能"顶着用"多久（秒）。
//
// 场景：上游（Binance）从服务器出发很慢或被墙时，阻塞式抓取要 5~30 秒，
// 手表的请求超时远小于这个值，表现就是"一直无网络"。所以在 TTL 之外
// 再给一个更宽的窗口：只要缓存没超过这个年龄，就先把旧值返回，
// 响应结束（fastcgi_finish_request）之后再在后台刷新。
// 手表拿到的是最多 N 秒前的价格，但请求永远秒回。
if (!defined('TTCOIN_STALE_SERVE')) {
    define('TTCOIN_STALE_SERVE', 600);
}

// 最多显示几位小数。上游给几位就显示几位（去掉尾部 0），
// 只有超过这个位数才四舍五入。
define('TTCOIN_MAX_DECIMALS', 4);

// 小额价格（整数部分为 0、小数位前面都是 0，如 0.00001826）：
// 按 4 位小数四舍五入会变成 "0"，屏幕上就没信息了，放宽到最多 7 位。
// 不再做旧版的放大换算（1000*SHIB 这种倍数前缀已移除），
// 币种名照常显示原始简称。
define('TTCOIN_MAX_DECIMALS_SMALL', 7);

// Widget 精简输出开关。
//
// Widget 版一屏只有 3 行（PAGE_ROWS = 3），给多了它也翻不到：Widget 显示几秒
// 就被系统回收，8 秒/页的轮播基本转不起来，多出来的条目只是白占响应体积和
// 手表端的解析时间。
//
// 开启（默认）= type=widget 的请求只回前 TTCOIN_WIDGET_TOP_N 项；
// 关闭         = 回全部，与 App 完全一致。
//
// 覆盖方式（二选一，都不用改本文件）：
//   1. 站点目录的 config.php：define('TTCOIN_WIDGET_LIMIT', false);
//   2. 环境变量 TTCOIN_WIDGET_LIMIT=0
//
// 注意：裁剪只发生在**出口**，缓存里始终是完整列表（见 ttcoin_filter_output()），
// 所以开关随时切换、App 与 Widget 混访，都不会触发重新抓上游。
if (!defined('TTCOIN_WIDGET_LIMIT')) {
    $ttcoinWidgetLimit = getenv('TTCOIN_WIDGET_LIMIT');
    define(
        'TTCOIN_WIDGET_LIMIT',
        $ttcoinWidgetLimit === false
            ? true
            : !in_array(strtolower(trim($ttcoinWidgetLimit)), ['0', 'false', 'off', 'no'], true)
    );
    unset($ttcoinWidgetLimit);
}

// 精简模式给 Widget 的条数。默认 3，与 Widget 的 PAGE_ROWS 对齐。
if (!defined('TTCOIN_WIDGET_TOP_N')) {
    define('TTCOIN_WIDGET_TOP_N', 3);
}

// ---------------------------------------------------------------------------
// 表盘（?type=face）专属显示配置
// ---------------------------------------------------------------------------
//
// 只影响 type=face 的**出口**：缓存里始终存完整列表，app / widget 完全不受影响。
// 这也是为什么开关随时能改，不会触发重新抓上游。
//
// 为什么「滚屏 / 固定」是用数组条数下发、而不是新加一个 JSON 字段：
// TTCoin_Face 的后台服务只认 [{symbol, base, price, change}, ...] 这个数组
// （source/TTCoinFaceService.mc），前台 TTCoinFaceView.mc 的 rotate() 逐条轮播。
// 给它多条 = 滚屏；只给一条 = 轮播索引恒为 0，也就是固定显示。
// 输出契约一个字都不用改，表盘端零改动就能切换。
//
// 覆盖方式（与 Widget 开关一致）：
//   1. 站点目录 config.php 里 define(...)；
//   2. 环境变量同名（TTCOIN_FACE_MODE 等）。
// config.php 的常量优先；都没给就用下面的默认值。
//
//   TTCOIN_FACE_MODE    scroll（滚屏） / fixed（固定）。非法值一律回落 scroll，
//                       避免一个拼写错误就让表盘拿到空数组。
//   TTCOIN_FACE_ORDER   独立的币种轮播顺序，逗号分隔，按 base 匹配
//                       （symbol 与 base 在当前版本内容相同；旧缓存里可能还有
//                       "1000*SHIB" 这种带倍数前缀的 symbol）。没列进去的币
//                       不会出现在 face 的响应里；默认把 DOGE 放第一，与表盘
//                       版式示例一致。留空 = 回落到候选池顺序。
//   TTCOIN_FACE_FIXED   fixed 模式固定显示哪个币；留空或写错 = 用 ORDER 第一项。
//   TTCOIN_FACE_TOP_N   scroll 模式最多回几条，0 = 按 ORDER 全给。
//   TTCOIN_FACE_COIN    全局「主币种」：默认一套也生效。三种出口都会用——
//                       face fixed 模式把它当 fixed、scroll 模式把它排到最前；
//                       app / widget 把它在返回列表里排到最前（其余顺序不动）。
//                       支持逗号分隔的多币种优先级列表（如 "BTC,ETH"）：按给定
//                       顺序排到最前，fixed 模式取列表第一个。
//                       留空 = 不重排，走候选池默认顺序（默认币种）。
//   TTCOIN_FACE_IDS     按 ID 覆盖的一套配置（JSON 对象，键是表盘端设置里填的
//                       行情配置 ID，随请求 ?id= 上来——表盘 / App / Widget
//                       三个客户端都带，同一个 id 三端看到同一套行情）。
//                       键值对里登记哪个键就覆盖哪个键，没登记的键继承上面
//                       的默认值；mode / order / fixed / topN / coin 五个键都可覆盖：
//                         {"11111111":{"mode":"fixed","fixed":"DOGE"},
//                          "22222222":{"mode":"scroll"},
//                          "33333333":{"coin":"BTC"},
//                          "44444444":{"coin":"BTC,ETH"}}
//                       同一个 id 里 coin 比 fixed 优先（coin 是主币种，可写
//                       多个，fixed 模式下把固定币换成列表第一个）。
//                       没带 id、或 id 没登记 = 全部走默认一套（默认币种）。
function ttcoin_face_define($name, $default)
{
    if (defined($name)) {
        return;
    }

    $env = getenv($name);

    define($name, $env === false ? $default : $env);
}

ttcoin_face_define('TTCOIN_FACE_MODE',   'scroll');
ttcoin_face_define('TTCOIN_FACE_ORDER',  'DOGE,BTC,ETH,SHIB,SOL,BNB,XRP,ADA,LINK,LTC,AVAX');
ttcoin_face_define('TTCOIN_FACE_FIXED',  '');
ttcoin_face_define('TTCOIN_FACE_TOP_N',  0);
ttcoin_face_define('TTCOIN_FACE_COIN',   '');
ttcoin_face_define('TTCOIN_FACE_IDS',    '{}');

// ---------------------------------------------------------------------------
// 涨幅口径（change 字段）
// ---------------------------------------------------------------------------
//   24h  （默认）上游给的滚动 24 小时涨跌幅，零改动、零额外请求。
//   tz    指定时区的「自然日涨幅」：以该时区当日 00:00 的开盘价为基准，
//         (现价 - 开盘价) / 开盘价 × 100。跨过当地 00:00 自动归零重算。
//         日开盘价按「时区 + 当地日期」缓存，一天最多抓一次；抓不到就
//         该币退回 24h，绝不让接口失败。
//
// TTCOIN_CHANGE_TZ 是**固定的 UTC 偏移**（不做夏令时换算），东八区写 8：
//   8 / +8 / UTC+8 → 东八区   0 / Z → UTC   -5 → 美东标准时   5:30 / 5.5 → 印度
// Binance klines 的 timeZone 参数支持整点和 H:MM，不支持小数，这里统一转换。
// ---------------------------------------------------------------------------
ttcoin_face_define('TTCOIN_CHANGE_MODE', '24h');
ttcoin_face_define('TTCOIN_CHANGE_TZ',   '8');

/**
 * 解析固定 UTC 偏移。接受 8 / +8 / -5 / UTC+8 / 5:30 / 5.5 / Z（=0）。
 *
 * @return array{offset:int,param:string,label:string}|null 写错返回 null
 */
function ttcoin_tz_parse($raw)
{
    $text = strtoupper(trim((string) $raw));
    $text = preg_replace('/^(UTC|GMT)\s*/', '', $text);
    $text = str_replace(' ', '', $text);

    if ($text === '' || $text === 'Z') {
        $text = '0';
    }

    // 两种写法：H:MM 是「时:分」，H.M 是「小时的小数」（5.5 = 5:30）。
    $sign = 1;
    $mins = 0;

    if (strpos($text, '.') !== false) {
        if (!preg_match('/^([+-]?)(\d{1,2})\.(\d{1,2})$/', $text, $m)) {
            return null;
        }

        $hours = (int) $m[2];
        $mins  = (int) round((float) ('0.' . $m[3]) * 60);
        $sign  = ($m[1] === '-') ? -1 : 1;
    } else {
        if (!preg_match('/^([+-]?)(\d{1,2})(?::(\d{1,2}))?$/', $text, $m)) {
            return null;
        }

        $hours = (int) $m[2];
        $mins  = (isset($m[3]) && $m[3] !== '') ? (int) $m[3] : 0;
        $sign  = ($m[1] === '-') ? -1 : 1;
    }

    // 分只允许 0…59；小数小时写法算出来最多 59 分（0.99h → 59 分）
    if ($mins > 59 || $hours > 14) {
        return null;
    }

    $offset = $sign * ($hours * 3600 + $mins * 60);

    // Binance klines timeZone 只认 ^[-+]?[0-9]{1,2}(:[0-9]{1,2})?$
    $param = ($mins === 0)
        ? (string) ($sign * $hours)
        : (($sign < 0 ? '-' : '') . $hours . ':' . str_pad((string) $mins, 2, '0', STR_PAD_LEFT));

    $label = ($offset === 0)
        ? 'UTC'
        : 'UTC' . ($sign > 0 ? '+' : '-') . $hours . ($mins === 0 ? '' : ':' . str_pad((string) $mins, 2, '0', STR_PAD_LEFT));

    return ['offset' => $offset, 'param' => $param, 'label' => $label];
}

/** 本次进程生效的涨幅配置（常量在 require 时固化，环境变量也能覆盖）。 */
function ttcoin_change_config()
{
    $mode = strtolower(trim((string) TTCOIN_CHANGE_MODE));
    $mode = ($mode === 'tz' || $mode === 'timezone' || $mode === 'day') ? 'tz' : '24h';

    $tz = ttcoin_tz_parse(TTCOIN_CHANGE_TZ);

    if ($tz === null) {
        $tz = ttcoin_tz_parse('8');       // 写错时区不猜：明确回落到东八区
    }

    return [
        'mode' => $mode,
        'tz'   => $tz,
        'day'  => gmdate('Y-m-d', time() + $tz['offset']),
    ];
}

/** 涨幅百分比 → 与 24h 同款的可显示文本：最多 3 位小数、去尾零、-0 归 0。 */
function ttcoin_change_text($pct)
{
    if (!is_float($pct) && !is_int($pct)) {
        return null;
    }

    if (!is_finite((float) $pct)) {
        return null;
    }

    $text = rtrim(rtrim(number_format((float) $pct, 3, '.', ''), '0'), '.');

    if ($text === '' || $text === '-' || $text === '-0') {
        $text = '0';
    }

    return $text;
}

/** 自然日涨幅 = (现价 - 当地 00:00 开盘价) / 开盘价 × 100。 */
function ttcoin_day_change($last, $open)
{
    $l = (float) $last;
    $o = (float) $open;

    if (!is_finite($l) || !is_finite($o) || $o <= 0 || $l <= 0) {
        return null;
    }

    return ttcoin_change_text(($l - $o) / $o * 100);
}

/**
 * 请求里的行情配置 ID（可选参数 ?id=）。
 *
 * 表盘端手机设置里填的 faceId 随请求带上来；只留字母数字、限长 32，
 * 没带 / 全是非法字符 = ''（走默认配置）。
 */
function ttcoin_face_client_id()
{
    static $id = null;

    if ($id !== null) {
        return $id;
    }

    $raw = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';

    $id = (string) preg_replace('/[^A-Za-z0-9]/', '', trim($raw));

    if (strlen($id) > 32) {
        $id = substr($id, 0, 32);
    }

    return $id;
}

/**
 * 这个 id 在 TTCOIN_FACE_IDS 里登记过没有。
 *
 * 只给日志用（不参与任何出口计算）：客户端把 id 带上来、服务端却没登记时，
 * 配置其实走的是默认一套 —— 这是「我填了 id 怎么没生效」最常见的原因。
 * 日志里给这种 id 加一个 `!` 后缀（见 ttcoin_log_request()），
 * 一眼就能分出「带了但没登记」和「登记过」。
 */
function ttcoin_face_id_known($id)
{
    $id = (string) $id;

    if ($id === '') {
        return false;
    }

    $ids = json_decode((string) TTCOIN_FACE_IDS, true);

    return is_array($ids) && isset($ids[$id]);
}

/**
 * 把配置清洗成运行时用的结构，按 id 分别算一次。
 *
 * 先算默认一套（TTCOIN_FACE_*），再如果请求带了 id 且 TTCOIN_FACE_IDS
 * 里登记了它，就按登记的键逐个覆盖（键值里写哪个键覆盖哪个键），
 * 最后统一清洗。所以不管默认还是按 id，清洗规则完全一致。
 *
 * @param string|null $id 行情配置 ID；null = 从 ?id= 里取
 * @return array{mode:string,order:array,fixed:string,topN:int,coin:string,id:string}
 */
function ttcoin_face_config($id = null)
{
    if ($id === null) {
        $id = ttcoin_face_client_id();
    }

    $id = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) $id);

    if (strlen($id) > 32) {
        $id = substr($id, 0, 32);
    }

    static $cache = [];

    if (isset($cache[$id])) {
        return $cache[$id];
    }

    // —— 默认一套的原始值 ——
    $raw = [
        'mode'  => strtolower(trim((string) TTCOIN_FACE_MODE)),
        'order' => TTCOIN_FACE_ORDER,
        'fixed' => strtoupper(trim((string) TTCOIN_FACE_FIXED)),
        'topN'  => (int) TTCOIN_FACE_TOP_N,
        'coin'  => strtoupper(trim((string) TTCOIN_FACE_COIN)),
    ];

    // —— 按 id 覆盖：登记了哪个键就覆盖哪个键 ——
    if ($id !== '') {
        $ids = json_decode((string) TTCOIN_FACE_IDS, true);

        if (is_array($ids) && isset($ids[$id]) && is_array($ids[$id])) {
            $over = $ids[$id];

            if (array_key_exists('mode', $over)) {
                $raw['mode'] = strtolower(trim((string) $over['mode']));
            }

            if (array_key_exists('order', $over)) {
                $raw['order'] = $over['order'];
            }

            if (array_key_exists('fixed', $over)) {
                $raw['fixed'] = strtoupper(trim((string) $over['fixed']));
            }

            if (array_key_exists('topN', $over)) {
                $n = (int) $over['topN'];
                $raw['topN'] = $n >= 0 ? $n : 0;
            }

            if (array_key_exists('coin', $over)) {
                $raw['coin'] = strtoupper(trim((string) $over['coin']));
            }
        }
    }

    // —— 统一清洗（默认与按 id 走同一套规则）——
    $mode = $raw['mode'] === 'fixed' ? 'fixed' : 'scroll';

    $list = is_array($raw['order']) ? $raw['order'] : explode(',', (string) $raw['order']);

    $order = [];

    foreach ($list as $base) {
        if (!is_string($base)) {
            continue;
        }

        $base = strtoupper(trim($base));
        $base = (string) preg_replace('/[^A-Z0-9]/', '', $base);

        if ($base !== '' && !in_array($base, $order, true)) {
            $order[] = $base;
        }
    }

    if (empty($order)) {
        $order = array_map('strtoupper', array_values(ttcoin_symbols()));
    }

    $fixed = (string) preg_replace('/[^A-Z0-9]/', '', $raw['fixed']);

    if ($fixed === '' || !in_array($fixed, $order, true)) {
        $fixed = $order[0];
    }

    $topN = $raw['topN'] >= 0 ? $raw['topN'] : 0;

    // —— 主币种（coin）：比 fixed 优先 ——
    // 支持逗号分隔的多币种优先级列表（如 "BTC,ETH"）：去重后按给定顺序
    // 排到 order 最前（order 里没有的币也会补进来，与单币种行为一致），
    // 列表外的币保持原相对顺序；fixed 模式下固定币换成列表第一个——
    // fixed 只回一条，多币种对它无意义，取第一个即可。
    $coinList = ttcoin_coin_list($raw['coin']);

    // 倒序逐个提到最前：正序遍历会把 "BTC,ETH" 摆成 ETH 在前
    for ($i = count($coinList) - 1; $i >= 0; $i--) {
        $base = $coinList[$i];
        $pos = array_search($base, $order, true);

        if ($pos !== false && $pos !== 0) {
            unset($order[$pos]);
            $order = array_values($order);
        }

        if ($order[0] !== $base) {
            array_unshift($order, $base);
        }
    }

    $coin = implode(',', $coinList);            // "BTC,ETH"；单币种时就是 "BTC"

    if (!empty($coinList) && $mode === 'fixed') {
        $fixed = $coinList[0];
    }

    $config = [
        'mode'  => $mode,
        'order' => $order,
        'fixed' => $fixed,
        'topN'  => $topN,
        'coin'  => $coin,
        'id'    => $id,
    ];

    $cache[$id] = $config;

    return $config;
}

/**
 * 取一条行情的 base（用于跟 FACE_ORDER 匹配）。
 *
 * 当前版本 symbol 与 base 相同（不再加倍数前缀）。历史缓存可能没有 base
 * 字段，这时退回 symbol；更早版本的缓存里 symbol 可能是 "1000*SHIB"
 * 这种带倍数前缀的写法，取星号后面那段兜底。
 */
function ttcoin_face_base($item)
{
    if (!is_array($item)) {
        return '';
    }

    $base = isset($item['base']) && is_string($item['base']) ? $item['base'] : '';

    if ($base === '' && isset($item['symbol']) && is_string($item['symbol'])) {
        $parts = explode('*', $item['symbol']);
        $base  = $parts[count($parts) - 1];
    }

    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $base));
}

/**
 * 按 face 配置重排 / 裁剪完整列表。
 *
 * 顺序只认 FACE_ORDER 里列出的币，没列到的不返回 —— 这就是「独立排序」：
 * 想让表盘先看 DOGE 再看 BTC，改配置即可，不用动候选池顺序（那个是 App 用的）。
 */
function ttcoin_face_output($data, $config)
{
    $byBase = [];

    foreach ($data as $item) {
        $base = ttcoin_face_base($item);

        if ($base !== '' && !isset($byBase[$base])) {
            $byBase[$base] = $item;
        }
    }

    $ordered = [];

    foreach ($config['order'] as $base) {
        if (isset($byBase[$base])) {
            $ordered[] = $byBase[$base];
        }
    }

    if (empty($ordered)) {
        // 配置里的币一个都没拿到（上游缺币 / 配置写错 / 历史脏缓存）：
        // 宁可退回完整列表，也不能让表盘收到空数组（它会显示 "--"）。
        $ordered = $data;
    }

    if ($config['mode'] === 'fixed') {
        $picked = isset($byBase[$config['fixed']])
            ? $byBase[$config['fixed']]
            : $ordered[0];

        return [$picked];
    }

    if ($config['topN'] > 0) {
        $ordered = array_slice($ordered, 0, $config['topN']);
    }

    return $ordered;
}

/**
 * 请求日志第 6 列的备注：一眼看出这次 face 请求用了什么模式、哪几个币。
 * 带配置 ID 时写成 face#ID:mode:…，方便区分默认一套和按 id 的覆盖。
 */
function ttcoin_face_note($type)
{
    if ($type !== 'face') {
        return '';
    }

    $config = ttcoin_face_config();

    if ($config['mode'] === 'fixed') {
        $shown = [$config['fixed']];
    } else {
        $limit = $config['topN'] > 0 ? $config['topN'] : 3;
        $shown = array_slice($config['order'], 0, $limit);
    }

    $prefix = 'face';

    if ($config['id'] !== '') {
        $prefix .= '#' . $config['id'];
    }

    return $prefix . ':' . $config['mode'] . ':' . implode(',', $shown);
}

/**
 * 客户端唯一标识（可选参数 ?uid=）。
 *
 * 手表端从 System.getDeviceSettings().uniqueIdentifier 取值随请求带上：
 * 同一设备 + 本应用取值恒定（卸载重装不变），不同应用之间取值不同，
 * 只能当匿名的设备区分符，关联不到其他应用或 Garmin 账号。
 *
 * 这里统一做白名单清洗后返回（只留字母数字、最长 64），空串表示未提供。
 * 目前只用于响应头回显（方便端到端验证），将来做按设备统计 / 限流时，
 * 直接取本函数的返回值即可，不必再碰 $_GET。
 */
function ttcoin_client_uid()
{
    $raw = isset($_GET['uid']) && is_string($_GET['uid']) ? $_GET['uid'] : '';
    return substr(preg_replace('/[^A-Za-z0-9]/', '', $raw), 0, 64);
}

/**
 * 客户端形态标记（可选参数 ?type=）。
 *
 * 手表端固定携带：Watch App 版发 "app"，Widget 版发 "widget"，
 * TTCoin_Face（表盘版）发 "face"，用来在日志里区分同一块表上的几种安装形态。
 * 白名单只认这三个值，其他一律按空串处理（日志里显示 "-"）。
 *
 * face 不只是个日志标签：它会走一套独立的出口配置（滚屏 / 固定 + 币种排序），
 * 见下面 ttcoin_face_config() 与 ttcoin_filter_output()。
 */
function ttcoin_client_type()
{
    $raw = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';

    return in_array($raw, ['app', 'widget', 'face'], true) ? $raw : '';
}


/**
 * 数据目录：缓存与日志的父目录（下面再分 cache/ 与 logs/）。
 *
 * 解析顺序（前一个可用就不再往后看）：
 *   1. 常量 TTCOIN_DATA_DIR —— 想让数据落在别处时，站点目录下放一个 config.php：
 *          <?php define('TTCOIN_DATA_DIR', '/some/where/outside/docroot');
 *      coin.php 与 update_price.php 都会自动 require 它（存在才 require）。
 *   2. 环境变量 TTCOIN_DATA_DIR —— 容器环境变量（docker-compose environment:）、
 *      php-fpm 的 env[TTCOIN_DATA_DIR]、Nginx fastcgi_param 均可。
 *   3. dirname(__DIR__) —— 默认：站点目录的上一级，通常已在 docroot 之外。
 *   4. __DIR__ —— 上一级不可写时退回站点目录本身（防护弱一档，见 README）。
 *
 * 之所以要这一层，是因为"站点目录"和"数据目录"的相对关系取决于 docroot
 * 绑到哪一级：绑到站点目录时上一级天然安全；绑到再上一级时就得显式指定。
 */
function ttcoin_data_dir()
{
    static $dir = null;

    if ($dir !== null) {
        return $dir;
    }

    $candidates = [];

    if (defined('TTCOIN_DATA_DIR') && is_string(TTCOIN_DATA_DIR) && TTCOIN_DATA_DIR !== '') {
        $candidates[] = TTCOIN_DATA_DIR;
    }

    $env = getenv('TTCOIN_DATA_DIR');

    if (is_string($env) && $env !== '') {
        $candidates[] = $env;
    }

    $candidates[] = dirname(__DIR__);
    $candidates[] = __DIR__;

    foreach ($candidates as $candidate) {
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');

        if ($candidate === '') {
            continue;
        }

        if (!is_dir($candidate)) {
            @mkdir($candidate, 0755, true);
        }

        if (is_dir($candidate) && is_writable($candidate)) {
            $dir = $candidate;

            return $dir;
        }
    }

    // 全部不可写：返回 __DIR__，让调用方走"与接口同级"的最终兜底
    $dir = __DIR__;

    return $dir;
}

/**
 * 生成 <数据目录>/<子目录>/<文件名>，子目录建不出来时退回站点目录下的平铺文件名。
 *
 * 平铺名只在极端情况（数据目录与站点目录都不允许建子目录）出现，
 * 由站点根 .htaccess 的 FilesMatch 兜底拒绝外部访问。
 */
function ttcoin_storage_path($subdir, $filename, $flatName)
{
    $dir = ttcoin_data_dir() . '/' . $subdir;

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    if (is_dir($dir) && is_writable($dir)) {
        return $dir . '/' . $filename;
    }

    return __DIR__ . '/' . $flatName;
}


/**
 * 请求日志文件路径（一行 = 一次请求）。
 *
 * 与缓存同理：优先写在 <数据目录>/logs/coin.log，不可写时退回本目录
 * （由 .htaccess 拒绝外部访问）。
 * 日志是纯运维数据，与接口输出无关，任何写失败都静默处理。
 */
function ttcoin_log_file()
{
    static $file = null;

    if ($file !== null) {
        return $file;
    }

    $file = ttcoin_storage_path('logs', 'coin.log', 'coin.log');

    return $file;
}

/**
 * 按设备记一行请求日志。
 *
 * 每次手表请求 coin.php 都在这里落一行（含命中缓存的请求），格式为
 * 制表符分隔的 7 列，方便 grep / awk / 导表格：
 *
 *     2026-11-10 11:20:03  face  11111111  ac915d42...  203.0.113.7  hit   face#11111111:fixed:DOGE
 *     └─ 时间              └─形态 └─ id     └─ uid        └─ 客户端 IP  └─事件 └─备注
 *
 * type 取值：app / widget / face，客户端未携带或非法时为 "-"。
 * id 是客户端 ?id= 带来的行情配置 ID（自动生成或手填），没带时为 "-"；
 * 带了但 TTCOIN_FACE_IDS 里没登记，会写成 `xxxx!`（感叹号 = 没登记，
 * 实际走的是默认一套配置）。
 * event 取值：
 *   hit     命中新缓存直接返回（上游零调用）
 *   fresh   缓存过期，重新拉上游成功
 *   stale   上游拉取失败，返回了旧缓存
 *   error   上游失败且无旧缓存（502）或编码失败（500）
 *
 * 隐私说明：id 由客户端本地生成（`TC` + 设备 uniqueIdentifier，或手填），
 * uid 是应用级匿名设备标识（关联不到 Garmin 账号），IP 仅用于排障，
 * 日志文件不对公网开放（.htaccess / 部署目录约定）。
 * 单文件超过 2MB 自动滚动为 coin.log.old，防止无限增长。
 *
 * @param string      $type   客户端形态（app / widget / face，'' = 未知）
 * @param string      $uid    设备匿名标识（见 ttcoin_client_uid()）
 * @param string      $event  hit / async / fresh / stale / error
 * @param string      $detail 备注（失败原因 / age=N / top3 / face:...）
 * @param string|null $id     行情配置 ID；null = 自己从 ?id= 取
 */
function ttcoin_log_request($type, $uid, $event, $detail = '', $id = null)
{
    $file = ttcoin_log_file();

    // 粗粒度滚动：超 2MB 把当前日志改名为 .old（覆盖上一代），再从头写
    $size = @filesize($file);

    if ($size !== false && $size > 2 * 1024 * 1024) {
        @rename($file, $file . '.old');
        $size = @filesize($file);
    }

    // detail 可能来自上游报错文本，先压掉其中的换行/制表符，避免一条拆成多行或多列
    $detail = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string) $detail);

    if ($id === null) {
        $id = ttcoin_face_client_id();
    }

    // id 列：没带 -> "-"；带了没登记 -> 末尾加 "!"（配置走的是默认一套）
    if ($id === '') {
        $idCol = '-';
    } else {
        $idCol = ttcoin_face_id_known($id) ? $id : $id . '!';
    }

    $line = sprintf(
        "%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
        date('Y-m-d H:i:s'),
        $type === '' ? '-' : $type,
        $idCol,
        $uid === '' ? '-' : $uid,
        isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-',
        $event,
        $detail
    );

    // 兼容历史坏日志：旧版本把行尾 \n 一起替换成了空格，文件末尾没有换行，
    // 直接追加会让第一条新记录粘在上一行尾巴上。补一个换行再写。
    $prefix = '';

    if ($size !== false && $size > 0) {
        $fh = @fopen($file, 'rb');

        if ($fh !== false) {
            if (@fseek($fh, -1, SEEK_END) === 0 && @fread($fh, 1) !== "\n") {
                $prefix = "\n";
            }

            @fclose($fh);
        }
    }

    // 注意：换行符在 sprintf 之后不能再做 str_replace，否则行尾的 \n 会被吃掉，
    // 整个日志文件会粘成一行（历史 bug）。清洗只针对字段内容，见 detail 那一步。
    return @file_put_contents($file, $prefix . $line, FILE_APPEND | LOCK_EX) !== false;
}

/**
 * 缓存文件路径。
 *
 * 落在 <数据目录>/cache/coin.json（数据目录解析见 ttcoin_data_dir()）。
 * 默认是站点目录的上一级，通常已在 docroot 之外；用 TTCOIN_DATA_DIR
 * 可以改到任意位置。原来与 coin.php 同级，可被外网直接 GET：内容虽不含
 * 敏感信息，但绕过了接口逻辑，还把抓取节奏变成了公开信息。
 *
 * 极端情况（数据目录建不出子目录）退回 __DIR__/coin_cache.json，
 * 由站点根 .htaccess 或用户自己的 Nginx 规则兜底拒绝外部访问。
 */
function ttcoin_cache_file()
{
    static $file = null;

    if ($file !== null) {
        return $file;
    }

    $file = ttcoin_storage_path('cache', 'coin.json', 'coin_cache.json');

    return $file;
}

/**
 * 缓存年龄（秒）。文件不存在 / 取不到 mtime 时返回 PHP_INT_MAX。
 */
function ttcoin_cache_age()
{
    $mtime = @filemtime(ttcoin_cache_file());

    return $mtime === false ? PHP_INT_MAX : max(0, time() - $mtime);
}

/**
 * 后台刷新缓存，配合 fastcgi_finish_request() 使用。
 *
 * 此时响应已经发给手表，这里再慢也不会被客户端感知。
 * 用 flock 做单飞：同一时刻来了 10 个请求，也只有一个真的去拉上游，
 * 其余的加锁失败立刻放弃（它们本身的响应早就发出去了）。
 */
function ttcoin_refresh_background()
{
    $lock = @fopen(ttcoin_cache_file() . '.lock', 'c');

    if ($lock === false) {
        return;
    }

    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        @fclose($lock);
        return;                            // 已有进程在刷新，这次跳过
    }

    @ignore_user_abort(true);
    @set_time_limit(30);

    // 响应早已发出，这里不怕慢：给 15 秒预算，够把六个候选域名都试一遍
    $res = ttcoin_fetch(15, 4);

    if ($res['ok']) {
        $json = ttcoin_encode($res['data']);

        if ($json !== null) {
            ttcoin_cache_write($json);
        }
    }

    @flock($lock, LOCK_UN);
    @fclose($lock);
}

/**
 * 读缓存。
 *
 * @param int|null $maxAge 最大存活秒数；传 null 表示不检查年龄（旧值兜底用）
 * @return string|null 原始 JSON 字符串；不存在 / 过期 / 读失败返回 null
 */
function ttcoin_cache_read($maxAge = null)
{
    $file = ttcoin_cache_file();

    if (!is_file($file)) {
        return null;
    }

    if ($maxAge !== null) {
        $mtime = @filemtime($file);

        if ($mtime === false || (time() - $mtime) >= $maxAge) {
            return null;
        }
    }

    $raw = @file_get_contents($file);

    return ($raw === false || $raw === '') ? null : $raw;
}

/**
 * 写缓存。失败静默：缓存只是锦上添花，不该让接口报 500。
 */
function ttcoin_cache_write($json)
{
    $file = ttcoin_cache_file();
    $tmp  = $file . '.tmp';

    // 先写临时文件再 rename：rename 在同一目录内是原子的，
    // 读请求只会看到"旧的完整内容"或"新的完整内容"，
    // 不会读到被截断的半截 JSON（后台刷新与读取并发时尤其重要）。
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * 统一的 JSON 编码方式。失败返回 null，由调用方决定怎么报错。
 */
function ttcoin_encode($data)
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return $json === false ? null : $json;
}

/**
 * 把 coin 配置清洗成去重后的币种数组。
 *
 * coin 支持逗号分隔的多币种优先级列表（如 "BTC,ETH"），也兼容单个币种
 * （"BTC"）。每段清洗成大写字母数字、去空段、按出现顺序去重。
 *
 * @param  string $coin 配置里的 coin 原始值（可能含逗号）
 * @return string[] 清洗后的币种数组；全是非法字符时为空数组
 */
function ttcoin_coin_list($coin)
{
    $list = [];

    foreach (explode(',', strtoupper((string) $coin)) as $part) {
        $base = (string) preg_replace('/[^A-Z0-9]/', '', $part);

        if ($base !== '' && !in_array($base, $list, true)) {
            $list[] = $base;
        }
    }

    return $list;
}

/**
 * 把主币种排到完整列表最前（其余币的相对顺序不动）。
 *
 * 只在配置里 coin 非空时调用：app / widget 不做 face 那套重排裁剪，但
 * 「这个 id 想先看到某个币」的诉求是共通的——列表第一个就是主币种。
 * coin 支持逗号分隔的多币种列表（"BTC,ETH"）：去重后按给定顺序把列表里
 * 的币提到最前（一条行情只被挑一次），列表外的币保持原相对顺序。
 * 列表里的币一条都没匹配上（上游缺币 / 配置写错）时原样返回，绝不动列表。
 *
 * @param array  $data 完整行情列表
 * @param string $coin 主币种，或逗号分隔的优先级列表（已清洗成大写字母数字）
 * @return array 重排后的列表；没匹配上就是原列表
 */
function ttcoin_coin_first($data, $coin)
{
    $coins = ttcoin_coin_list($coin);

    if (empty($coins) || !is_array($data)) {
        return $data;
    }

    $used = [];
    $picked = [];

    foreach ($coins as $want) {
        foreach ($data as $i => $item) {
            if (isset($used[$i])) {
                continue;
            }

            if (ttcoin_face_base($item) === $want) {
                $picked[] = $item;
                $used[$i] = true;
                break;
            }
        }
    }

    if (empty($picked)) {
        return $data;                           // 列表里的币一个都没匹配上
    }

    foreach ($data as $i => $item) {
        if (!isset($used[$i])) {
            $picked[] = $item;
        }
    }

    return $picked;
}

/**
 * 按客户端形态裁剪 / 重排输出。
 *
 * 缓存文件里**永远存完整列表**，app / widget / face 共用同一份；只在返回前按需处理。
 * 这样做的三个好处：
 *   1. 开关随时开关，缓存不用作废、不会触发一次多余的上游抓取；
 *   2. 三种形态交替请求时，先来的那个不会把缓存"写窄"；
 *   3. cron 预热（update_price.php）写进去的始终是完整数据。
 *
 * 四种出口：
 *   face    → ttcoin_face_output()：按独立顺序重排；fixed 只回一条，scroll 回前 N 条
 *   widget  → 主币种排前（coin 配了才动）→ 候选池顺序的前 TTCOIN_WIDGET_TOP_N 项
 *   app     → 主币种排前（coin 配了才动），其余原样
 *   其他    → 原样（不带 type）
 *
 * @param string $json 完整列表的 JSON 字符串
 * @param string $type 客户端形态（ttcoin_client_type() 的返回值）
 * @return string 处理后的 JSON；解析失败 / 无数据时原样返回
 */
function ttcoin_filter_output($json, $type)
{
    if (!is_string($json) || $json === '' || $type === '') {
        return $json;
    }

    if ($type === 'face') {
        $data = json_decode($json, true);

        if (!is_array($data) || empty($data)) {
            return $json;
        }

        $shaped = ttcoin_encode(ttcoin_face_output($data, ttcoin_face_config()));

        return $shaped === null ? $json : $shaped;
    }

    // app / widget：登记的 id 配了 coin 时，把主币种排到最前。
    // 没配 coin（默认）这里一个字节都不动，返回的还是原始 JSON。
    $config = ttcoin_face_config();

    if ($config['coin'] !== '') {
        $data = json_decode($json, true);

        if (is_array($data) && !empty($data)) {
            $moved = ttcoin_coin_first($data, $config['coin']);

            if ($moved !== $data) {
                $shaped = ttcoin_encode($moved);

                if ($shaped !== null) {
                    $json = $shaped;
                }
            }
        }
    }

    if (!TTCOIN_WIDGET_LIMIT || $type !== 'widget') {
        return $json;
    }

    $data = json_decode($json, true);

    // 解不出来（历史脏缓存 / 上游异常）就原样返回：宁可多给，也不给空
    if (!is_array($data) || count($data) <= TTCOIN_WIDGET_TOP_N) {
        return $json;
    }

    $trimmed = ttcoin_encode(array_slice($data, 0, TTCOIN_WIDGET_TOP_N));

    return $trimmed === null ? $json : $trimmed;
}

/**
 * 把上游的价格格式化成"直接可显示"的文本。
 *
 * 规则：上游给几位小数就保留几位，只有超过 TTCOIN_MAX_DECIMALS 位才
 * 四舍五入（去掉尾部多余的 0，整数不留小数点）。
 *
 * 例外：小额价格——整数部分为 0 且小数位前面都是 0（如 0.00001826）——
 * 按 4 位四舍五入会变成 "0"，这类值放宽到最多 TTCOIN_MAX_DECIMALS_SMALL 位。
 *
 *     75701.02000000 → 75701.02
 *     2235.51000000  → 2235.51
 *     0.07161000     → 0.07161     小额价格，最多 7 位
 *     0.00001826000  → 0.0000183   小额价格，最多 7 位（第 8 位 6 → 进位）
 *     67000.00000000 → 67000
 *
 * 为什么不在手表端格式化：
 *   Monkey C 的 Float 是 32 位，75678.78 存进去实际是 75678.78125，
 *   再 format("%0.4f") 就会画出 75678.7812 —— 末尾两位是精度噪声，
 *   跟真实价格无关。字符串运算没有这个问题，所以位数在服务端就定死。
 *
 * @param mixed $raw 上游给的 price（Binance 是字符串，可能是数字）
 * @param int   $maxDecimals 最多保留几位小数（默认 TTCOIN_MAX_DECIMALS）
 * @return string|null 可显示文本；非法值返回 null
 */
function ttcoin_format_price($raw, $maxDecimals = TTCOIN_MAX_DECIMALS)
{
    if (is_string($raw)) {
        $text = trim($raw);
    } elseif (is_int($raw) || is_float($raw)) {
        $text = (string)$raw;
    } else {
        return null;
    }

    if ($text === '') {
        return null;
    }

    // 小额价格：整数部分为 0、小数位前面都是 0（0.00001826）时放宽位数，
    // 否则按常规位数四舍五入会直接变成 "0"。两种写法分支共用同一条规则。
    if (preg_match('/^-?0\.0\d/', $text) === 1) {
        $maxDecimals = TTCOIN_MAX_DECIMALS_SMALL;
    }

    // 常规十进制字面量：按字符串逐位处理，不经过 float
    if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $text, $m) === 1) {

        $sign    = $m[1];
        $intPart = ltrim($m[2], '0');

        if ($intPart === '') {
            $intPart = '0';
        }

        $decimals = isset($m[3]) ? rtrim($m[3], '0') : '';

        if (strlen($decimals) > $maxDecimals) {

            // 截断位之后的第一位决定进位（$m[3] 是去零之前的原始小数串）
            $roundUp  = ((int)$m[3][$maxDecimals]) >= 5;
            $decimals = substr($decimals, 0, $maxDecimals);

            if ($roundUp) {

                // 整数部分 + 保留的小数位拼成一个整数串，末位 +1 处理进位
                $digits = str_split($intPart . $decimals);
                $i = count($digits) - 1;

                while ($i >= 0) {
                    if ($digits[$i] === '9') {
                        $digits[$i] = '0';
                        $i--;
                        continue;
                    }
                    $digits[$i] = (string)((int)$digits[$i] + 1);
                    break;
                }

                if ($i < 0) {
                    array_unshift($digits, '1');   // 999.99999 → 1000.0000
                }

                $all = implode('', $digits);
                $cut = strlen($all) - $maxDecimals;

                $intPart  = ltrim(substr($all, 0, $cut), '0');
                $decimals = substr($all, $cut);

                if ($intPart === '') {
                    $intPart = '0';
                }
            }
        }

        $decimals = rtrim($decimals, '0');

        return $sign . $intPart . ($decimals === '' ? '' : '.' . $decimals);
    }

    // 科学计数法（1e-5）等罕见写法：退回浮点兜底，位数同样受 $maxDecimals 限制
    if (!is_numeric($text)) {
        return null;
    }

    $value = (float)$text;

    if (!is_finite($value)) {
        return null;
    }

    $fixed = number_format($value, $maxDecimals, '.', '');
    $fixed = rtrim(rtrim($fixed, '0'), '.');

    return $fixed === '' || $fixed === '-' ? null : $fixed;
}

/**
 * 上游候选列表：上次成功的域名 → 主域名 → 备用域名，去重后返回。
 */
function ttcoin_upstream_candidates()
{
    $list = [];
    $memory = @file_get_contents(ttcoin_storage_path('cache', 'upstream.txt', 'coin_upstream.txt'));

    if (is_string($memory)) {
        $memory = trim($memory);

        // 只信任 https 开头的值，防止缓存文件被写脏后把请求带到别处
        if ($memory !== '' && strpos($memory, 'https://') === 0) {
            $list[] = $memory;
        }
    }

    $list[] = TTCOIN_API_URL;

    if (defined('TTCOIN_API_FALLBACKS') && is_array(TTCOIN_API_FALLBACKS)) {
        foreach (TTCOIN_API_FALLBACKS as $url) {
            if (is_string($url) && strpos($url, 'https://') === 0) {
                $list[] = $url;
            }
        }
    }

    return array_values(array_unique($list));
}

/**
 * 记住这次成功的域名，下次优先用。
 *
 * 写失败无所谓：只是少了"优先命中"的优化，候选顺序会退化成默认顺序。
 */
function ttcoin_upstream_remember($url)
{
    $file = ttcoin_storage_path('cache', 'upstream.txt', 'coin_upstream.txt');

    // 内容没变就不写：这个函数每次刷新都会被调用，每 10 秒写一次文件没必要
    if (@file_get_contents($file) === $url) {
        return;
    }

    @file_put_contents($file, $url, LOCK_EX);
}

/**
 * 解析 TTCOIN_RESOLVE（'域名:端口:IP;域名:端口:IP'）成 CURLOPT_RESOLVE 的格式。
 */
function ttcoin_resolve_list()
{
    if (!defined('TTCOIN_RESOLVE') || TTCOIN_RESOLVE === '') {
        return [];
    }

    $out = [];

    foreach (explode(';', TTCOIN_RESOLVE) as $item) {
        $parts = explode(':', trim($item));

        if (count($parts) === 3 && $parts[0] !== '' && $parts[2] !== '') {
            $out[] = $parts[0] . ':' . $parts[1] . ':' . $parts[2];
        }
    }

    return $out;
}

/**
 * 取 URL 的 host，用于把错误信息按域名分开记。
 */
function ttcoin_host_of($url)
{
    $host = parse_url($url, PHP_URL_HOST);

    return is_string($host) && $host !== '' ? $host : $url;
}

/**
 * 发一次 GET，不跟随跳转、不做重试（重试由调用方换域名完成）。
 *
 * @return array{ok:bool,body:string,error:string}
 */
function ttcoin_http_get($url, $connectTimeout, $timeout)
{
    $ch = curl_init();

    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(1, (int)ceil($timeout)),
        CURLOPT_CONNECTTIMEOUT => max(1, (int)ceil($connectTimeout)),
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: ' . TTCOIN_USER_AGENT
        ],
    ];

    if (defined('TTCOIN_PROXY') && TTCOIN_PROXY !== '') {
        $opts[CURLOPT_PROXY] = TTCOIN_PROXY;
    }

    $resolve = ttcoin_resolve_list();

    if ($resolve) {
        $opts[CURLOPT_RESOLVE] = $resolve;
    }

    curl_setopt_array($ch, $opts);

    $body  = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);

    curl_close($ch);

    if ($body === false || $code !== 200) {
        // errno 比错误文本更有用：28 = 超时，6 = DNS 解析失败，7 = 连接被拒，
        // 35 = TLS 握手失败。排查时先看这个数字就知道卡在哪一层。
        $msg = 'HTTP ' . $code;

        if ($errno) {
            $msg .= ' (errno ' . $errno . ')';
        }

        if ($error !== '') {
            $msg .= ' ' . $error;
        }

        return ['ok' => false, 'body' => '', 'error' => $msg];
    }

    return ['ok' => true, 'body' => $body, 'error' => ''];
}

/**
 * 把 ticker/24hr 的候选域名换成 klines 端点。二者是同一批 Binance 公开域名，
 * 连通性一致；记住的优先域名（upstream.txt）也照样复用。
 */
function ttcoin_kline_bases()
{
    $out = [];

    foreach (ttcoin_upstream_candidates() as $url) {
        $base = preg_replace('#/api/v3/ticker/24hr$#', '/api/v3/klines', $url);

        if ($base !== null && $base !== $url && strpos($base, 'https://') === 0) {
            $out[] = $base;
        }
    }

    return array_values(array_unique($out));
}

/**
 * 并行 GET 一批 URL，返回 [键 => 响应体|null]。
 * 日开盘价要按币种各打一次 klines，串行 11 次会把刷新拖到十几秒，
 * 所以走 curl_multi 一次发出去；单个失败只影响那一个币。
 */
function ttcoin_http_get_multi($urls, $connectTimeout, $timeout)
{
    $out = array_fill_keys(array_keys($urls), null);

    if (!function_exists('curl_multi_init')) {
        return $out;
    }

    $mh = curl_multi_init();
    $handles = [];

    $resolve = ttcoin_resolve_list();

    foreach ($urls as $key => $url) {
        $ch = curl_init();

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(1, (int) ceil($timeout)),
            CURLOPT_CONNECTTIMEOUT => max(1, (int) ceil($connectTimeout)),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: ' . TTCOIN_USER_AGENT
            ],
        ];

        if (defined('TTCOIN_PROXY') && TTCOIN_PROXY !== '') {
            $opts[CURLOPT_PROXY] = TTCOIN_PROXY;
        }

        if ($resolve) {
            $opts[CURLOPT_RESOLVE] = $resolve;
        }

        curl_setopt_array($ch, $opts);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    $deadline = microtime(true) + $timeout;

    do {
        $status = curl_multi_exec($mh, $active);

        if ($active) {
            curl_multi_select($mh, 0.1);
        }
    } while ($active && $status === CURLM_OK && microtime(true) < $deadline);

    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code === 200 && is_string($body) && $body !== '') {
            $out[$key] = $body;
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);

    return $out;
}

/**
 * 抓一批币在「指定时区当日 00:00」的开盘价。
 *
 * Binance 的 /api/v3/klines 支持 timeZone 参数：interval=1d + limit=1 返回的
 * 就是**当地当日这根（未走完的）日 K**，它的 [1] 开盘价即 00:00 价，
 * 一整天不变。失败 / 无该币时跳过，由调用方退回 24h。
 */
function ttcoin_fetch_day_opens($symbols, $cfg, $budget)
{
    $found  = [];
    $bases  = ttcoin_kline_bases();
    $deadline = microtime(true) + max(1, (float) $budget);

    foreach ($bases as $base) {
        $missing = array_values(array_diff($symbols, array_keys($found)));

        if (empty($missing)) {
            break;
        }

        $left = $deadline - microtime(true);

        if ($left < 0.5) {
            break;
        }

        $urls = [];

        foreach ($missing as $symbol) {
            $urls[$symbol] = $base . '?' . http_build_query([
                'symbol'   => $symbol,
                'interval' => '1d',
                'timeZone' => $cfg['tz']['param'],
                'limit'    => 1,
            ]);
        }

        $responses = ttcoin_http_get_multi($urls, 2, min(3, $left));

        foreach ($responses as $symbol => $body) {
            if (!is_string($body)) {
                continue;
            }

            $klines = json_decode($body, true);

            if (is_array($klines) && isset($klines[0][1])
                && is_numeric($klines[0][1]) && (float) $klines[0][1] > 0) {
                $found[$symbol] = (string) $klines[0][1];
            }
        }
    }

    return $found;
}

/**
 * 带「按当地日期缓存」的日开盘价表。
 *
 * cache/dayopen.json = {day, tz, opens:{SYMBOL:价格}, missed:{SYMBOL:上次失败时间}}。
 * 跨过当地 00:00 后 day 不匹配，整份作废重抓；抓不到的币记 missed，
 * 5 分钟内不重试，避免某个币上游缺数据时每 10 秒都白打一次。
 * 单文件 + flock 单飞，接口热路径上最多一次 file_get_contents。
 */
function ttcoin_day_opens($symbols, $cfg, $budget = 4)
{
    $file = ttcoin_storage_path('cache', 'dayopen.json', 'coin_dayopen.json');

    $readState = function () use ($file, $cfg) {
        $raw = @file_get_contents($file);
        $state = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($state) || ($state['day'] ?? '') !== $cfg['day']
            || ($state['tz'] ?? '') !== $cfg['tz']['param']) {
            return [
                'day'    => $cfg['day'],
                'tz'     => $cfg['tz']['param'],
                'opens'  => [],
                'missed' => [],
            ];
        }

        $state['opens']  = is_array($state['opens'] ?? null) ? $state['opens'] : [];
        $state['missed'] = is_array($state['missed'] ?? null) ? $state['missed'] : [];

        return $state;
    };

    $state = $readState();
    $opens = $state['opens'];

    $need = [];

    foreach ($symbols as $symbol) {
        if (isset($opens[$symbol])) {
            continue;
        }

        $last = (int) ($state['missed'][$symbol] ?? 0);

        if ($last > 0 && time() - $last < 300) {
            continue;
        }

        $need[] = $symbol;
    }

    if (empty($need)) {
        return $opens;
    }

    $lock = @fopen($file . '.lock', 'c');

    if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock !== false) {
            @fclose($lock);
        }

        return $opens;                      // 另有进程在抓，这次先用已有值
    }

    $state = $readState();                  // 拿到锁后重读，别覆盖别的进程刚写的
    $opens = $state['opens'];
    $need  = [];

    foreach ($symbols as $symbol) {
        if (!isset($opens[$symbol]) && ((int) ($state['missed'][$symbol] ?? 0) === 0
            || time() - (int) $state['missed'][$symbol] >= 300)) {
            $need[] = $symbol;
        }
    }

    if (!empty($need)) {
        $found = ttcoin_fetch_day_opens($need, $cfg, $budget);

        foreach ($need as $symbol) {
            if (isset($found[$symbol])) {
                $opens[$symbol] = $found[$symbol];
                unset($state['missed'][$symbol]);
            } else {
                $state['missed'][$symbol] = time();
            }
        }

        $state['opens'] = $opens;
        $tmp = $file . '.tmp';

        if (@file_put_contents($tmp, ttcoin_encode($state), LOCK_EX) !== false) {
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
            }
        }
    }

    @flock($lock, LOCK_UN);
    @fclose($lock);

    return $opens;
}

/**
 * 从 Binance 拉取一次行情，转换成手表要的定长数组。
 *
 * 每条数据的形状：
 *
 *     {
 *       "symbol": "SHIB",        // 表端直接显示的文本（与 base 相同）
 *       "base":   "SHIB",        // 原始简称，菜单勾选 / 缓存按它匹配
 *       "price":  "0.0000183",   // 已格式化的字符串，客户端不再做数值格式化
 *       "change": "-1.319"       // 24h 涨跌幅（百分比，不带 % 号）
 *     }
 *
 * @param int $timeout        整体超时（秒）
 * @param int $connectTimeout 连接超时（秒）
 * @return array{ok:bool,data:array|null,error:string}
 */
function ttcoin_fetch($timeout = 10, $connectTimeout = 5)
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'data' => null, 'error' => 'PHP curl extension missing'];
    }

    $query = '?' . http_build_query([
        'symbols' => json_encode(array_keys(ttcoin_symbols()))
    ]);

    // 依次尝试候选域名，任一成功即用。总耗时不超过 $timeout，
    // 避免"6 个域名各等 3 秒"把 PHP-FPM 的进程占满。
    $started  = microtime(true);
    $response = false;
    $errors   = [];

    foreach (ttcoin_upstream_candidates() as $base) {
        $left = $timeout - (microtime(true) - $started);

        if ($left <= 0.5) {
            $errors[] = 'gave up: ' . $timeout . 's budget used up';
            break;
        }

        $attempt = ttcoin_http_get($base . $query, min($connectTimeout, 3), min($left, $timeout));

        if ($attempt['ok']) {
            $response = $attempt['body'];
            ttcoin_upstream_remember($base);
            break;
        }

        $errors[] = ttcoin_host_of($base) . ': ' . $attempt['error'];
    }

    if ($response === false || $response === '') {
        return [
            'ok'    => false,
            'data'  => null,
            'error' => $errors ? implode(' | ', $errors) : 'no upstream configured'
        ];
    }

    $raw = json_decode($response, true);

    if (!is_array($raw)) {
        return ['ok' => false, 'data' => null, 'error' => 'invalid Binance JSON'];
    }

    $prices = [];

    foreach ($raw as $item) {

        // 24hr 端点的价格字段叫 lastPrice（ticker/price 叫 price）
        if (!is_array($item) || !isset($item['symbol']) || !isset($item['lastPrice'])) {
            continue;
        }

        if (!is_string($item['symbol'])) {
            continue;
        }

        // 不信任上游：价格必须是合法数字，且最终能格式化成可显示文本。
        // 这一步同时挡掉 'NaN' / 'INF' / '' / '1e400' 这类值——它们会一路走到
        // json_encode()，让编码返回 false，最后把空内容写进缓存文件。
        // 注意保留上游的字符串形态，不要先转 float，否则小数位就丢了。
        //
        // 小额币种（SHIB 0.00001826）由 ttcoin_format_price() 内部放宽到
        // 最多 7 位小数；不再做放大换算，也不加倍数前缀。
        $price = ttcoin_format_price($item['lastPrice']);

        if ($price === null) {
            continue;
        }

        // 涨跌幅：Binance 给的是带符号字符串（如 "2.345" / "-1.230"），
        // 复用价格格式化去尾零（"0.000" → "0"），最多 4 位足够。
        $change = isset($item['priceChangePercent'])
            ? ttcoin_format_price($item['priceChangePercent'])
            : null;

        $prices[$item['symbol']] = [
            'price'  => $price,
            'change' => $change,
            'last'   => (string) $item['lastPrice'],   // 原始现价，算自然日涨幅用
        ];
    }

    // —— 涨幅口径 ——
    // 24h（默认）：上面 priceChangePercent 原样用，一个字节都不动。
    // tz：拿「指定时区当日 00:00」的开盘价重算。日开盘价按当地日期缓存，
    // 一天最多抓一轮；某个币没抓到就保留它的 24h 值，绝不让整接口失败。
    $changeCfg = ttcoin_change_config();

    if ($changeCfg['mode'] === 'tz') {
        $opens = ttcoin_day_opens(array_keys($prices), $changeCfg, 4);

        foreach ($prices as $symbol => $row) {
            if (!isset($opens[$symbol])) {
                continue;
            }

            $dayChange = ttcoin_day_change($row['last'], $opens[$symbol]);

            if ($dayChange !== null) {
                $prices[$symbol]['change'] = $dayChange;
            }
        }
    }

    // 候选池里哪个币种失败（下架 / 上游缺失）就跳过哪个，
    // 只要还有至少一个有效条目就算成功——10 个候选时没必要一损俱损。
    $result = [];

    foreach (ttcoin_symbols() as $apiSymbol => $label) {

        if (!isset($prices[$apiSymbol])) {
            continue;
        }

        // symbol 与 base 相同：表端显示什么就匹配什么，不再有倍数前缀。
        $result[] = [
            'symbol' => $label,
            'base'   => $label,
            'price'  => $prices[$apiSymbol]['price'],
            'change' => $prices[$apiSymbol]['change'],
        ];
    }

    if (empty($result)) {
        return ['ok' => false, 'data' => null, 'error' => 'no valid price data'];
    }

    return ['ok' => true, 'data' => $result, 'error' => ''];
}
