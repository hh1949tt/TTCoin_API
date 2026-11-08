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

// 可选：HTTP 代理（如 'http://192.168.1.10:7890'）。留空 = 直连。
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
define('TTCOIN_CACHE_TTL', 10);

// 过期缓存最多还能"顶着用"多久（秒）。
//
// 场景：上游（Binance）从服务器出发很慢或被墙时，阻塞式抓取要 5~30 秒，
// 手表的请求超时远小于这个值，表现就是"一直无网络"。所以在 TTL 之外
// 再给一个更宽的窗口：只要缓存没超过这个年龄，就先把旧值返回，
// 响应结束（fastcgi_finish_request）之后再在后台刷新。
// 手表拿到的是最多 N 秒前的价格，但请求永远秒回。
define('TTCOIN_STALE_SERVE', 600);

// 最多显示几位小数。上游给几位就显示几位（去掉尾部 0），
// 只有超过这个位数才四舍五入。
define('TTCOIN_MAX_DECIMALS', 4);

// 小额价格放大显示（见 ttcoin_scale_price()）：
// 卫星币价格形如 0.00001826，按 4 位小数四舍五入后会变成 "0"，屏幕上就没信息了。
// 规则：小数部分前导 0 达到 TTCOIN_SCALE_MIN_ZEROS 个时，把价格乘以 10^(n-1)，
// 让第一个有效数字落到小数点后第 2 位（0.0xxx），并在币种名前加 "10^(n-1)*"。
//   n=3 → ×100    → 100*SHIB
//   n=4 → ×1000   → 1000*SHIB
//   n=5 → ×10000  → 10000*SHIB
// 放大后允许显示到 5 位小数（比常规多一位，不然等于白放大）。
define('TTCOIN_SCALE_MIN_ZEROS', 3);
define('TTCOIN_MAX_DECIMALS_SCALED', 5);

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
 * 用来在日志里区分同一块表上的两种安装形态。
 * 白名单只认这两个值，其他一律按空串处理（日志里显示 "-"）。
 */
function ttcoin_client_type()
{
    $raw = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';

    return in_array($raw, ['app', 'widget'], true) ? $raw : '';
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
 * 制表符分隔的 6 列，方便 grep / awk / 导表格：
 *
 *     2026-11-08 07:05:49  app  ac915d42...  203.0.113.7  hit
 *     └─ 时间              └─形态 └─ uid(-空)  └─ 客户端 IP  └─事件
 *
 * type 取值：app / widget，客户端未携带或非法时为 "-"。
 * event 取值：
 *   hit     命中新缓存直接返回（上游零调用）
 *   fresh   缓存过期，重新拉上游成功
 *   stale   上游拉取失败，返回了旧缓存
 *   error   上游失败且无旧缓存（502）或编码失败（500）
 *
 * 隐私说明：uid 是应用级匿名设备标识（关联不到 Garmin 账号），
 * IP 仅用于排障，日志文件不对公网开放（.htaccess / 部署目录约定）。
 * 单文件超过 2MB 自动滚动为 coin.log.old，防止无限增长。
 */
function ttcoin_log_request($type, $uid, $event, $detail = '')
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

    $line = sprintf(
        "%s\t%s\t%s\t%s\t%s\t%s\n",
        date('Y-m-d H:i:s'),
        $type === '' ? '-' : $type,
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
 * 按客户端形态裁剪输出。
 *
 * 缓存文件里**永远存完整列表**，App 与 Widget 共用同一份；只在返回前按需截断。
 * 这样做的三个好处：
 *   1. 开关随时开关，缓存不用作废、不会触发一次多余的上游抓取；
 *   2. App 与 Widget 交替请求时，先来的那个不会把缓存"写窄"；
 *   3. cron 预热（update_price.php）写进去的始终是完整数据。
 *
 * @param string $json 完整列表的 JSON 字符串
 * @param string $type 客户端形态（ttcoin_client_type() 的返回值）
 * @return string 裁剪后的 JSON；未开启、非 widget、条数不足、解析失败时原样返回
 */
function ttcoin_filter_output($json, $type)
{
    if (!TTCOIN_WIDGET_LIMIT || $type !== 'widget' || !is_string($json)) {
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
 *     75701.02000000 → 75701.02
 *     2235.51000000  → 2235.51
 *     0.07161000     → 0.0716      5 位，四舍五入到 4 位
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
 * 小额价格放大：把 0.00001826 这类"小数点后一长串 0"的报价换算成 0.0xxx 显示。
 *
 * 阈值与倍数都由小数部分的**前导 0 个数 n** 决定：
 *
 *     n=3  0.000123    ×100     → 0.0123    显示 100*XXX
 *     n=4  0.00001826  ×1000    → 0.01826   显示 1000*XXX
 *     n=5  0.00000715  ×10000   → 0.0715    显示 10000*XXX
 *     n=6  0.00000012  ×100000  → 0.012     显示 100000*XXX
 *
 * 也就是"乘到第一个有效数字落在小数点后第 2 位"（0.0xxx 这个量级），
 * 读起来的量级和 0.07 / 0.18 这些常规币种一致，也不会再出现 0.0000
 * 被四舍五入成 "0" 的情况。
 *
 * 不放大：整数部分非 0；负数 / 科学计数法 / 脏数据（交给普通格式化）；
 * 前导 0 少于 3 个（0.07、0.001 本来就看得清）；去掉尾部 0 后本来就在
 * 4 位小数以内（如 0.0001，原样显示就行）。
 *
 * @param mixed $raw 上游给的 lastPrice
 * @return array{price:string|null, factor:int} factor=1 表示没放大
 */
function ttcoin_scale_price($raw)
{
    $plain = ['price' => ttcoin_format_price($raw), 'factor' => 1];

    if (!is_string($raw)) {
        return $plain;                       // 数字型上游：不做字符串移位
    }

    $text = trim($raw);

    if (preg_match('/^(\d+)\.(\d+)$/', $text, $m) !== 1) {
        return $plain;                       // 负号 / 科学计数法 / 非数字
    }

    if (ltrim($m[1], '0') !== '') {
        return $plain;                       // 整数部分非 0：正常价格
    }

    $frac = rtrim($m[2], '0');

    if ($frac === '' || strlen($frac) <= TTCOIN_MAX_DECIMALS) {
        return $plain;                       // 价格为 0，或本就显示得下（0.0001）
    }

    $zeros = strspn($frac, '0');

    if ($zeros < TTCOIN_SCALE_MIN_ZEROS) {
        return $plain;                       // 0.07 / 0.001 这类不需要放大
    }

    // 直接移位小数串，不经过 float：0.00001826 → "0.0" + "1826"
    $price = ttcoin_format_price('0.0' . substr($frac, $zeros), TTCOIN_MAX_DECIMALS_SCALED);

    if ($price === null || $price === '0') {
        return $plain;                       // 兜底：放大后反而没东西可显示
    }

    return ['price' => $price, 'factor' => (int)pow(10, $zeros - 1)];
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
 * 从 Binance 拉取一次行情，转换成手表要的定长数组。
 *
 * 每条数据的形状：
 *
 *     {
 *       "symbol": "1000*SHIB",   // 表端直接显示的文本（放大过的带倍数前缀）
 *       "base":   "SHIB",        // 原始简称，菜单勾选 / 缓存按它匹配
 *       "price":  "0.01826",     // 已格式化的字符串，客户端不再做数值格式化
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
        // 小额币种（SHIB 0.00001826）在这里一并放大：按 4 位小数格式化会变成 "0"，
        // 放大后价格与倍数成对返回，展示时币种名前会加 "1000*" 这样的前缀。
        $scaled = ttcoin_scale_price($item['lastPrice']);

        if ($scaled['price'] === null) {
            continue;
        }

        // 涨跌幅：Binance 给的是带符号字符串（如 "2.345" / "-1.230"），
        // 复用价格格式化去尾零（"0.000" → "0"），最多 4 位足够。
        $change = isset($item['priceChangePercent'])
            ? ttcoin_format_price($item['priceChangePercent'])
            : null;

        $prices[$item['symbol']] = [
            'price'  => $scaled['price'],
            'factor' => $scaled['factor'],
            'change' => $change,
        ];
    }

    // 候选池里哪个币种失败（下架 / 上游缺失）就跳过哪个，
    // 只要还有至少一个有效条目就算成功——10 个候选时没必要一损俱损。
    $result = [];

    foreach (ttcoin_symbols() as $apiSymbol => $label) {

        if (!isset($prices[$apiSymbol])) {
            continue;
        }

        $quote  = $prices[$apiSymbol];
        $factor = $quote['factor'];

        // 放大过的价格，币种名前面加倍数（1000*SHIB），提醒这不是原始报价。
        // base 始终是原始简称：手表端的菜单勾选、缓存里都用它做匹配，
        // symbol 只负责"显示成什么"。
        $result[] = [
            'symbol' => $factor > 1 ? $factor . '*' . $label : $label,
            'base'   => $label,
            'price'  => $quote['price'],
            'change' => $quote['change'],
        ];
    }

    if (empty($result)) {
        return ['ok' => false, 'data' => null, 'error' => 'no valid price data'];
    }

    return ['ok' => true, 'data' => $result, 'error' => ''];
}
