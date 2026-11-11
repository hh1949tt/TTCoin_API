<?php
/**
 * TTCoin 后端后台（单文件）
 * ===========================================================================
 *
 * 给 coin.php / lib.php 的所有可配置项做一个网页界面，保存即生效。
 *
 * 访问：https://<站点>/admin.php
 * 首次打开会让你设置一个管理密码（存 admin_pass.php，password_hash）。
 *
 * ---------------------------------------------------------------------------
 * 存储结构
 * ---------------------------------------------------------------------------
 *   admin_settings.json   真正的配置数据（人可读，FACE_IDS 是嵌套对象）
 *   config.php            由本页生成的桥接文件，只做一件事：读 JSON 后 define
 *                         所以 coin.php 里那句 require config.php 完全不用改
 *   admin_pass.php        管理密码的 hash（直接访问不输出任何内容）
 *   admin_keys.json       每个设备 ID 的管理码（设备用户登录用，管理员可生成/删除）
 *   config.php.bak.*      第一次接管旧 config.php 时自动备份
 *
 * 设备管理登录：管理员给某台设备的 ID 生成一个管理码，设备用户打开
 * owner.php（独立入口，本页是管理员专用）用「ID + 管理码」登录，只能改自己
 * 那个 ID 的独立配置（模式/固定币/主币种/条数/顺序/涨幅口径），看不到全局
 * 配置、日志和其它设备。两套登录完全分开：本页不受理设备登录，
 * owner.php 也不显示管理员登录。
 *
 * 为什么要多一层 JSON：直接让网页去改写 PHP 源码既容易写坏，也没法可靠地
 * 区分「用户手写的 define」和「后台托管的项」。JSON 做数据源、config.php 做
 * 桥接，两边职责清楚；类型（bool / int / string）也能原样传下去。
 *
 * ---------------------------------------------------------------------------
 * 安全提醒（重要）
 * ---------------------------------------------------------------------------
 * 这个文件是**给你自己用**的：本地、局域网、或加了访问限制的内网环境。
 * 如果要放到公网服务器上，至少做到：
 *   1. 设置一个足够长的密码（本页强制 >= 8 位，建议 16 位随机）；
 *   2. 把文件名改成别人猜不到的名字（如 admin-7f3a9c.php）；
 *   3. 服务器层再加一层保护（Nginx `allow/deny`、Basic Auth、或只监听内网）；
 *   4. 别把 admin_settings.json、admin_pass.php 提交到任何仓库（.gitignore 已列）。
 * 登录失败 5 次会锁 5 分钟，但那只是防手滑，挡不住真正的爆破。
 *
 * ---------------------------------------------------------------------------
 * 兼容性
 * ---------------------------------------------------------------------------
 * 语法按 PHP 7.2+ 写（不用箭头函数 / match / 类型属性），老旧 NAS 也能跑。
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

define('ADMIN_VERSION', '1.1.0');
define('ADMIN_DIR', __DIR__);
define('ADMIN_PASS_FILE', ADMIN_DIR . '/admin_pass.php');
define('ADMIN_SETTINGS_FILE', ADMIN_DIR . '/admin_settings.json');
define('ADMIN_KEYS_FILE', ADMIN_DIR . '/admin_keys.json');
define('ADMIN_CONFIG_FILE', ADMIN_DIR . '/config.php');
define('ADMIN_MARK', 'ADMIN-GENERATED');

// ---------------------------------------------------------------- 会话
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('ttcoin_admin');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/', '', false, true);
    }

    session_start();
}

// ---------------------------------------------------------------- 小工具

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_body()
{
    $raw = file_get_contents('php://input');

    if (is_string($raw) && $raw !== '') {
        $data = json_decode($raw, true);

        if (is_array($data)) {
            return $data;
        }
    }

    return $_POST;
}

function admin_csrf()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf'];
}

function admin_csrf_ok($token)
{
    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

function admin_logged_in()
{
    return !empty($_SESSION['ok']);
}

function admin_owner_logged_in()
{
    return !empty($_SESSION['owner_id']);
}

// ---------------------------------------------------------------- 设备管理码

/** 读设备管理码表：{ "TCxxxx": { "code": "AB2C4D5F", "time": 1699... } }。 */
function admin_load_keys()
{
    $raw = json_decode((string) @file_get_contents(ADMIN_KEYS_FILE), true);

    return is_array($raw) ? $raw : [];
}

/** 原子写设备管理码表。 */
function admin_save_keys($keys)
{
    $json = json_encode($keys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    if (!is_string($json)) {
        return false;
    }

    $tmp = ADMIN_KEYS_FILE . '.tmp';

    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, ADMIN_KEYS_FILE);
}

/** 生成 8 位无歧义字符（去掉 0/O/1/I/L）的随机管理码。 */
function admin_gen_code()
{
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $code = '';

    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $code;
}

function admin_pass_hash()
{
    if (!is_file(ADMIN_PASS_FILE)) {
        return null;
    }

    $hash = include ADMIN_PASS_FILE;

    return is_string($hash) && $hash !== '' ? $hash : null;
}

function admin_write_pass($password)
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $code = "<?php\n// TTCoin 后台管理密码（password_hash）。直接访问本文件不输出任何内容。\nreturn "
        . var_export($hash, true) . ";\n";

    return @file_put_contents(ADMIN_PASS_FILE, $code, LOCK_EX) !== false;
}

/** 配置项清单：后台托管、可写进 admin_settings.json 的全部键。 */
function admin_config_keys()
{
    return [
        'TTCOIN_DATA_DIR',
        'TTCOIN_FACE_MODE',
        'TTCOIN_FACE_ORDER',
        'TTCOIN_FACE_FIXED',
        'TTCOIN_FACE_TOP_N',
        'TTCOIN_FACE_COIN',
        'TTCOIN_FACE_IDS',
        'TTCOIN_WIDGET_LIMIT',
        'TTCOIN_WIDGET_TOP_N',
        'TTCOIN_CHANGE_MODE',
        'TTCOIN_CHANGE_TZ',
        'TTCOIN_TIMEZONE',
        'TTCOIN_CACHE_TTL',
        'TTCOIN_STALE_SERVE',
        'TTCOIN_PROXY',
        'TTCOIN_RESOLVE',
        'TTCOIN_DIAG_TOKEN',
    ];
}

/** 当前**真实生效**的值（已含 config.php / 环境变量 / lib 默认值）。 */
function admin_effective_settings()
{
    $out = [];

    foreach (admin_config_keys() as $key) {
        if (!defined($key)) {
            continue;
        }

        $value = constant($key);

        if ($key === 'TTCOIN_FACE_IDS') {
            $decoded = json_decode((string) $value, true);
            $out[$key] = is_array($decoded) ? $decoded : [];
        } else {
            $out[$key] = $value;
        }
    }

    return $out;
}

/** 读配置：JSON 里有就用，没有就退回当前生效值。 */
function admin_load_settings()
{
    $settings = admin_effective_settings();

    if (!is_file(ADMIN_SETTINGS_FILE)) {
        return $settings;
    }

    $raw = json_decode((string) @file_get_contents(ADMIN_SETTINGS_FILE), true);

    if (!is_array($raw)) {
        return $settings;
    }

    foreach (admin_config_keys() as $key) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }

        if ($key === 'TTCOIN_FACE_IDS') {
            $settings[$key] = is_array($raw[$key]) ? $raw[$key] : [];
        } else {
            $settings[$key] = $raw[$key];
        }
    }

    return $settings;
}

/**
 * 生成 config.php 桥接文件。
 *
 * 只做 define，不做别的 —— 常量名白名单限定 TTCOIN_ 开头，避免 JSON 里塞进
 * 奇怪的东西把 define 玩坏。已定义的（如环境变量先定义的）不覆盖，
 * 保持「config.php 优先于环境变量」的既有约定。
 */
function admin_write_config()
{
    $code = "<?php\n"
        . "/**\n"
        . " * " . ADMIN_MARK . " —— 由 admin.php 生成，请勿手改。\n"
        . " *\n"
        . " * 真正的配置数据在 admin_settings.json，本文件只负责把它 define 出来，\n"
        . " * 让 coin.php / update_price.php 里原有的 require config.php 照常工作。\n"
        . " * 生成时间：" . date('Y-m-d H:i:s') . "\n"
        . " */\n"
        . "\$__ttcoin_file = __DIR__ . '/admin_settings.json';\n"
        . "\n"
        . "if (is_file(\$__ttcoin_file)) {\n"
        . "    \$__ttcoin = json_decode((string) @file_get_contents(\$__ttcoin_file), true);\n"
        . "\n"
        . "    if (is_array(\$__ttcoin)) {\n"
        . "        // FACE_IDS 在 JSON 里是人可读的对象，这里转成 lib.php 需要的字符串\n"
        . "        if (isset(\$__ttcoin['TTCOIN_FACE_IDS']) && is_array(\$__ttcoin['TTCOIN_FACE_IDS'])) {\n"
        . "            \$__ttcoin['TTCOIN_FACE_IDS'] = json_encode(\n"
        . "                (object) \$__ttcoin['TTCOIN_FACE_IDS'],\n"
        . "                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE\n"
        . "            );\n"
        . "        }\n"
        . "\n"
        . "        foreach (\$__ttcoin as \$__ttcoin_key => \$__ttcoin_value) {\n"
        . "            if (preg_match('/^TTCOIN_[A-Z0-9_]+\$/', (string) \$__ttcoin_key)\n"
        . "                && !defined(\$__ttcoin_key)) {\n"
        . "                define(\$__ttcoin_key, \$__ttcoin_value);\n"
        . "            }\n"
        . "        }\n"
        . "    }\n"
        . "\n"
        . "    unset(\$__ttcoin, \$__ttcoin_key, \$__ttcoin_value);\n"
        . "}\n"
        . "\n"
        . "unset(\$__ttcoin_file);\n";

    // 接管前先备份：旧文件里可能有用户手写的、后台不认识的 define
    if (is_file(ADMIN_CONFIG_FILE)) {
        $old = (string) @file_get_contents(ADMIN_CONFIG_FILE);

        if (strpos($old, ADMIN_MARK) === false) {
            @copy(ADMIN_CONFIG_FILE, ADMIN_CONFIG_FILE . '.bak.' . date('Ymd-His'));
        }
    }

    return @file_put_contents(ADMIN_CONFIG_FILE, $code, LOCK_EX) !== false;
}

/** 原子写 JSON：先写临时文件再 rename，避免断电留下半截配置。 */
function admin_save_settings($settings)
{
    $json = json_encode(
        $settings,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if (!is_string($json)) {
        return false;
    }

    $tmp = ADMIN_SETTINGS_FILE . '.tmp';

    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, ADMIN_SETTINGS_FILE);
}

// ---------------------------------------------------------------- 校验

function admin_clean_id($raw)
{
    $id = preg_replace('/[^A-Za-z0-9]/', '', (string) $raw);

    return substr((string) $id, 0, 32);
}

function admin_clean_list($raw, $pool)
{
    $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
    $out = [];

    foreach ($parts as $item) {
        $coin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $item));

        if ($coin === '') {
            continue;
        }

        // 只允许候选池里的币种，拼错的直接丢弃（避免「配了但不显示」的迷惑）
        if (!empty($pool) && !in_array($coin, $pool, true)) {
            continue;
        }

        if (!in_array($coin, $out, true)) {
            $out[] = $coin;
        }
    }

    return implode(',', $out);
}

/**
 * 校验单个 ID 的覆盖项（mode / fixed / topN / coin / order / change）。
 * 只收「非空且合法」的键，空 = 不覆盖 = 继承默认。
 * 返回 [one, errors]。管理员保存和设备用户保存共用这一套规则。
 */
function admin_clean_id_over($over, $pool, $id)
{
    $errors = [];
    $one = [];

    if (isset($over['mode']) && $over['mode'] !== '') {
        $m = strtolower(trim((string) $over['mode']));

        if (in_array($m, ['scroll', 'fixed'], true)) {
            $one['mode'] = $m;
        } else {
            $errors[] = 'ID ' . $id . ' 的模式非法';
        }
    }

    if (isset($over['change']) && $over['change'] !== '') {
        $chg = strtolower(trim((string) $over['change']));

        if (in_array($chg, ['24h', 'tz'], true)) {
            $one['change'] = $chg;
        } else {
            $errors[] = 'ID ' . $id . ' 的涨幅口径只能是 24h 或 tz';
        }
    }

    // 该 id 的自然日时区（只在 change=tz 或全局 tz 时生效）；空 = 继承全局
    if (isset($over['tz']) && trim((string) $over['tz']) !== '') {
        $tz = ttcoin_tz_parse($over['tz']);

        if ($tz === null) {
            $errors[] = 'ID ' . $id . ' 的涨幅时区写得不对（示例：8 / -5 / 5:30）';
        } else {
            $one['tz'] = $tz['param'];
        }
    }

    if (isset($over['fixed']) && $over['fixed'] !== '') {
        $f = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $over['fixed']));

        if (in_array($f, $pool, true)) {
            $one['fixed'] = $f;
        } else {
            $errors[] = 'ID ' . $id . ' 的固定币种不在候选池里';
        }
    }

    if (isset($over['topN']) && $over['topN'] !== '') {
        $n = (int) $over['topN'];

        if ($n >= 0 && $n <= 50) {
            $one['topN'] = $n;
        } else {
            $errors[] = 'ID ' . $id . ' 的条数要在 0 到 50 之间';
        }
    }

    if (isset($over['coin']) && $over['coin'] !== '') {
        $c = admin_clean_list($over['coin'], $pool);

        if ($c === '') {
            $errors[] = 'ID ' . $id . ' 的主币种都不在候选池里';
        } else {
            $one['coin'] = $c;
        }
    }

    if (isset($over['order']) && $over['order'] !== '') {
        $o = admin_clean_list($over['order'], $pool);

        if ($o === '') {
            $errors[] = 'ID ' . $id . ' 的顺序都不在候选池里';
        } else {
            $one['order'] = $o;
        }
    }

    return [$one, $errors];
}

/**
 * 规范化提交上来的配置。返回 [clean, errors]。
 * 宁可报错让人改，也不悄悄写进一个非法值 —— 非法值在 lib 里多半是静默回落，
 * 表现为「设置没生效」，比报错更难查。
 */
function admin_validate($input, $pool)
{
    $errors = [];
    $warnings = [];
    $clean  = [];

    // —— 单选 / 枚举 ——
    $mode = isset($input['TTCOIN_FACE_MODE']) ? strtolower(trim((string) $input['TTCOIN_FACE_MODE'])) : 'scroll';

    if (!in_array($mode, ['scroll', 'fixed'], true)) {
        $errors[] = '滚动模式只能是 scroll 或 fixed';
        $mode = 'scroll';
    }

    $clean['TTCOIN_FACE_MODE'] = $mode;

    $clean['TTCOIN_FACE_ORDER'] = admin_clean_list(
        isset($input['TTCOIN_FACE_ORDER']) ? $input['TTCOIN_FACE_ORDER'] : '',
        $pool
    );

    if ($clean['TTCOIN_FACE_ORDER'] === '') {
        $errors[] = '滚动顺序至少要选一个币种';
        $clean['TTCOIN_FACE_ORDER'] = implode(',', $pool);
    }

    $fixed = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) (isset($input['TTCOIN_FACE_FIXED']) ? $input['TTCOIN_FACE_FIXED'] : '')));

    if ($fixed !== '' && !in_array($fixed, $pool, true)) {
        $errors[] = '固定币种 ' . $fixed . ' 不在候选池里';
        $fixed = '';
    }

    $clean['TTCOIN_FACE_FIXED'] = $fixed;

    $topN = isset($input['TTCOIN_FACE_TOP_N']) ? (int) $input['TTCOIN_FACE_TOP_N'] : 0;

    if ($topN < 0 || $topN > 50) {
        $errors[] = '滚动条数要在 0 到 50 之间';
        $topN = max(0, min(50, $topN));
    }

    $clean['TTCOIN_FACE_TOP_N'] = $topN;

    $clean['TTCOIN_FACE_COIN'] = admin_clean_list(
        isset($input['TTCOIN_FACE_COIN']) ? $input['TTCOIN_FACE_COIN'] : '',
        $pool
    );

    // —— 按 ID 覆盖 ——
    $ids = [];

    if (isset($input['TTCOIN_FACE_IDS']) && is_array($input['TTCOIN_FACE_IDS'])) {
        foreach ($input['TTCOIN_FACE_IDS'] as $rawId => $over) {
            $id = admin_clean_id($rawId);

            if ($id === '') {
                continue;
            }

            if (!is_array($over)) {
                continue;
            }

            $one = [];
            list($one, $oneErrors) = admin_clean_id_over($over, $pool, $id);
            $errors = array_merge($errors, $oneErrors);

            $ids[$id] = $one;

            // 软提醒：lib 里 coin 的优先级高于 fixed，全局主币种会把固定币顶掉。
            // 这不是错误（行为本身是设计），但「配了 ETH 却显示 BTC」极难自查，
            // 所以保存时明确说一句。
            if (isset($one['fixed']) && $one['fixed'] !== '') {
                $globalCoin = $clean['TTCOIN_FACE_COIN'];

                if ($globalCoin !== '') {
                    $first = explode(',', $globalCoin);
                    $first = $first[0];

                    $own = isset($one['coin']) ? explode(',', $one['coin']) : [];
                    $ownFirst = !empty($own) ? $own[0] : '';

                    if ($ownFirst === '' && $first !== $one['fixed']) {
                        $warnings[] = 'ID ' . $id . ' 设了固定币 ' . $one['fixed']
                            . '，但全局主币种是 ' . $first . '（coin 优先，会覆盖 fixed）；'
                            . '要让这行固定 ' . $one['fixed'] . '，把该行主币种也填成 ' . $one['fixed'];
                    } elseif ($ownFirst !== '' && $ownFirst !== $one['fixed']) {
                        $warnings[] = 'ID ' . $id . ' 的固定币是 ' . $one['fixed']
                            . '，但该行主币种第一项是 ' . $ownFirst . '，实际会显示 ' . $ownFirst;
                    }
                }
            }
        }
    }

    $clean['TTCOIN_FACE_IDS'] = $ids;

    // —— 布尔 / 数字 ——
    $clean['TTCOIN_WIDGET_LIMIT'] = !empty($input['TTCOIN_WIDGET_LIMIT']);

    $widgetTopN = isset($input['TTCOIN_WIDGET_TOP_N']) ? (int) $input['TTCOIN_WIDGET_TOP_N'] : 3;

    if ($widgetTopN < 1 || $widgetTopN > 50) {
        $errors[] = 'Widget 条数要在 1 到 50 之间';
        $widgetTopN = max(1, min(50, $widgetTopN));
    }

    $clean['TTCOIN_WIDGET_TOP_N'] = $widgetTopN;

    // —— 涨幅口径 ——
    $changeMode = isset($input['TTCOIN_CHANGE_MODE']) ? strtolower(trim((string) $input['TTCOIN_CHANGE_MODE'])) : '24h';

    if (!in_array($changeMode, ['24h', 'tz'], true)) {
        $errors[] = '涨幅口径只能是 24h 或 tz';
        $changeMode = '24h';
    }

    $clean['TTCOIN_CHANGE_MODE'] = $changeMode;

    $tz = isset($input['TTCOIN_CHANGE_TZ']) ? trim((string) $input['TTCOIN_CHANGE_TZ']) : '8';

    if ($changeMode === 'tz') {
        $parsed = ttcoin_tz_parse($tz === '' ? '8' : $tz);

        if ($parsed === null) {
            $errors[] = '时区偏移写得不对，示例：8 / +8 / -5 / 5:30 / Z';
            $tz = '8';
        }
    }

    $clean['TTCOIN_CHANGE_TZ'] = $tz === '' ? '8' : $tz;

    // —— 系统项 ——
    $timezone = isset($input['TTCOIN_TIMEZONE']) ? trim((string) $input['TTCOIN_TIMEZONE']) : 'Asia/Shanghai';

    if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
        $errors[] = 'PHP 时区名不合法（示例：Asia/Shanghai）';
        $timezone = 'Asia/Shanghai';
    }

    $clean['TTCOIN_TIMEZONE'] = $timezone;

    $ttl = isset($input['TTCOIN_CACHE_TTL']) ? (int) $input['TTCOIN_CACHE_TTL'] : 10;

    if ($ttl < 1 || $ttl > 3600) {
        $errors[] = '缓存有效期要在 1 到 3600 秒之间';
        $ttl = max(1, min(3600, $ttl));
    }

    $clean['TTCOIN_CACHE_TTL'] = $ttl;

    $stale = isset($input['TTCOIN_STALE_SERVE']) ? (int) $input['TTCOIN_STALE_SERVE'] : 600;

    if ($stale < 0 || $stale > 86400) {
        $errors[] = '旧缓存可用窗口要在 0 到 86400 秒之间';
        $stale = max(0, min(86400, $stale));
    }

    $clean['TTCOIN_STALE_SERVE'] = $stale;

    $dataDir = trim((string) (isset($input['TTCOIN_DATA_DIR']) ? $input['TTCOIN_DATA_DIR'] : ''));

    if ($dataDir !== '') {
        if ($dataDir[0] !== '/' && !preg_match('/^[A-Za-z]:[\\\\\/]/', $dataDir)) {
            $errors[] = '数据目录要写绝对路径（如 /volume1/docker/ttcoin），留空 = 站点上一级';
        } elseif (!is_dir($dataDir)) {
            $errors[] = '数据目录不存在：' . $dataDir;
        } elseif (!is_writable($dataDir)) {
            $errors[] = '数据目录不可写：' . $dataDir;
        }
    }

    $clean['TTCOIN_DATA_DIR'] = $dataDir;

    $proxy = trim((string) (isset($input['TTCOIN_PROXY']) ? $input['TTCOIN_PROXY'] : ''));

    if ($proxy !== '' && !preg_match('#^https?://[^\s]+$#i', $proxy)) {
        $errors[] = '代理地址要以 http:// 或 https:// 开头';
        $proxy = '';
    }

    $clean['TTCOIN_PROXY'] = $proxy;

    $resolve = trim((string) (isset($input['TTCOIN_RESOLVE']) ? $input['TTCOIN_RESOLVE'] : ''));

    if ($resolve !== '' && !preg_match('/^[A-Za-z0-9.\-]+:\d{1,5}:[0-9a-fA-F.:]+(;\s*[A-Za-z0-9.\-]+:\d{1,5}:[0-9a-fA-F.:]+)*$/', $resolve)) {
        $errors[] = 'DNS 钉 IP 格式应为 host:port:ip，多项用分号隔开';
        $resolve = '';
    }

    $clean['TTCOIN_RESOLVE'] = $resolve;

    $token = trim((string) (isset($input['TTCOIN_DIAG_TOKEN']) ? $input['TTCOIN_DIAG_TOKEN'] : ''));

    if ($token !== '' && strlen($token) < 16) {
        $errors[] = '诊断密钥少于 16 位不会生效（diag.php 直接 404）';
        $token = '';
    }

    $clean['TTCOIN_DIAG_TOKEN'] = $token;

    return [$clean, $errors, $warnings];
}

/** 日志尾部 N 行（大文件也不会整份读进内存）。 */
function admin_tail_lines($file, $lines)
{
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }

    $fh = @fopen($file, 'rb');

    if ($fh === false) {
        return [];
    }

    $size = (int) @filesize($file);
    $chunk = 64 * 1024;
    $data = '';
    $pos = $size;

    while ($pos > 0 && substr_count($data, "\n") < $lines + 1) {
        $read = min($chunk, $pos);
        $pos -= $read;

        if (fseek($fh, $pos) !== 0) {
            break;
        }

        $data = (string) fread($fh, $read) . $data;
    }

    fclose($fh);

    $rows = explode("\n", rtrim($data, "\n"));

    return array_slice($rows, -$lines);
}

// ===========================================================================
// 未登录分支
// ===========================================================================
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
$body   = null;

if ($action !== '' && in_array($action, ['login', 'setpass', 'owner_login', 'owner_logout', 'code_set', 'save', 'owner_save', 'preview', 'owner_preview', 'log', 'logout'], true)) {
    $body = admin_body();
}

if ($action === 'login') {
    $token = isset($body['_csrf']) ? $body['_csrf'] : '';

    if (!admin_csrf_ok($token)) {
        json_out(['ok' => false, 'message' => '页面已过期，刷新后重试'], 419);
    }

    // 简单限速：5 次失败锁 5 分钟
    $now = time();

    if (!empty($_SESSION['lock_until']) && $_SESSION['lock_until'] > $now) {
        json_out(['ok' => false, 'message' => '尝试次数过多，请 ' . ceil(($_SESSION['lock_until'] - $now) / 60) . ' 分钟后再试'], 429);
    }

    $hash = admin_pass_hash();

    if ($hash === null) {
        json_out(['ok' => false, 'message' => '还没设置管理密码'], 400);
    }

    $password = isset($body['password']) ? (string) $body['password'] : '';

    if (!password_verify($password, $hash)) {
        $_SESSION['fails'] = (int) (isset($_SESSION['fails']) ? $_SESSION['fails'] : 0) + 1;

        if ($_SESSION['fails'] >= 5) {
            $_SESSION['fails'] = 0;
            $_SESSION['lock_until'] = $now + 300;
        }

        json_out(['ok' => false, 'message' => '密码不对'], 401);
    }

    $_SESSION['ok'] = true;
    $_SESSION['fails'] = 0;
    unset($_SESSION['lock_until']);
    json_out(['ok' => true]);
}

if ($action === 'setpass') {
    $token = isset($body['_csrf']) ? $body['_csrf'] : '';

    if (!admin_csrf_ok($token)) {
        json_out(['ok' => false, 'message' => '页面已过期，刷新后重试'], 419);
    }

    if (admin_pass_hash() !== null) {
        json_out(['ok' => false, 'message' => '密码已存在，如需重置请删除 admin_pass.php'], 400);
    }

    $password = isset($body['password']) ? (string) $body['password'] : '';
    $confirm  = isset($body['confirm']) ? (string) $body['confirm'] : '';

    if (strlen($password) < 8) {
        json_out(['ok' => false, 'message' => '密码至少 8 位']);
    }

    if ($password !== $confirm) {
        json_out(['ok' => false, 'message' => '两次输入不一致']);
    }

    if (!admin_write_pass($password)) {
        json_out(['ok' => false, 'message' => '写不进 admin_pass.php，检查目录权限'], 500);
    }

    $_SESSION['ok'] = true;
    json_out(['ok' => true]);
}

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

// —— 设备管理登录：ID + 管理码。会话只记 owner_id，权限单独判 ——
// 只能从 owner.php（设备用户入口）发起；管理员入口不受理，登录彻底分开。
if ($action === 'owner_login') {
    if (!defined('ADMIN_OWNER_ENTRY')) {
        json_out(['ok' => false, 'message' => '设备用户请从 owner.php 登录'], 403);
    }

    if (!admin_csrf_ok(isset($body['_csrf']) ? $body['_csrf'] : '')) {
        json_out(['ok' => false, 'message' => '页面已过期，刷新后重试'], 419);
    }

    $now = time();

    if (!empty($_SESSION['owner_lock_until']) && $_SESSION['owner_lock_until'] > $now) {
        json_out(['ok' => false, 'message' => '尝试次数过多，请 ' . ceil(($_SESSION['owner_lock_until'] - $now) / 60) . ' 分钟后再试'], 429);
    }

    $id   = admin_clean_id(isset($body['id']) ? $body['id'] : '');
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) (isset($body['code']) ? $body['code'] : '')));
    $keys = admin_load_keys();
    $got  = isset($keys[$id]['code']) ? (string) $keys[$id]['code'] : '';

    // 不区分「ID 不存在」和「码不对」，不给试探的人反馈哪一步错了
    if ($id === '' || $got === '' || !hash_equals($got, $code)) {
        $_SESSION['owner_fails'] = (int) (isset($_SESSION['owner_fails']) ? $_SESSION['owner_fails'] : 0) + 1;

        if ($_SESSION['owner_fails'] >= 5) {
            $_SESSION['owner_fails'] = 0;
            $_SESSION['owner_lock_until'] = $now + 300;
        }

        json_out(['ok' => false, 'message' => 'ID 或管理码不对'], 401);
    }

    $_SESSION['owner_id'] = $id;
    unset($_SESSION['owner_fails'], $_SESSION['owner_lock_until']);
    json_out(['ok' => true]);
}

// —— 设备用户退出：只清 owner 会话，不动管理员登录态 ——
if ($action === 'owner_logout') {
    unset($_SESSION['owner_id'], $_SESSION['owner_fails'], $_SESSION['owner_lock_until']);
    header('Location: owner.php');
    exit;
}

// —— 管理员维护设备管理码：code=auto 生成，code=空串 删除 ——
if ($action === 'code_set') {
    if (!admin_logged_in()) {
        json_out(['ok' => false, 'message' => '未登录'], 401);
    }

    if (!admin_csrf_ok(isset($body['_csrf']) ? $body['_csrf'] : '')) {
        json_out(['ok' => false, 'message' => '页面已过期，刷新后重试'], 419);
    }

    $id   = admin_clean_id(isset($body['id']) ? $body['id'] : '');
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) (isset($body['code']) ? $body['code'] : '')));

    if ($id === '') {
        json_out(['ok' => false, 'message' => 'ID 不能为空'], 422);
    }

    $keys = admin_load_keys();

    if ($code === '') {
        unset($keys[$id]);

        if (!admin_save_keys($keys)) {
            json_out(['ok' => false, 'message' => '写不进 admin_keys.json，检查目录权限'], 500);
        }

        json_out(['ok' => true, 'message' => '已删除 ' . $id . ' 的管理码']);
    }

    if ($code === 'AUTO') {
        $code = admin_gen_code();
    } elseif (strlen($code) < 6 || strlen($code) > 32) {
        json_out(['ok' => false, 'message' => '管理码长度要在 6 到 32 位之间（auto = 自动生成）'], 422);
    }

    $keys[$id] = ['code' => $code, 'time' => time()];

    if (!admin_save_keys($keys)) {
        json_out(['ok' => false, 'message' => '写不进 admin_keys.json，检查目录权限'], 500);
    }

    json_out(['ok' => true, 'message' => '已保存', 'code' => $code, 'time' => date('Y-m-d H:i', (int) $keys[$id]['time'])]);
}

/**
 * 设备用户预览用轻清洗：只保证结构合法（完整校验在保存时由
 * admin_clean_id_over 做）。非法值交给 lib 的解析器静默回落，预览本来就是
 * 试算，没必要为了它再复制一份候选池校验。
 */
function admin_light_clean_over($over)
{
    $one = [];
    $mode = strtolower(trim((string) (isset($over['mode']) ? $over['mode'] : '')));

    if (in_array($mode, ['scroll', 'fixed'], true)) {
        $one['mode'] = $mode;
    }

    $fixed = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) (isset($over['fixed']) ? $over['fixed'] : '')));

    if ($fixed !== '') {
        $one['fixed'] = substr($fixed, 0, 12);
    }

    if (isset($over['topN']) && $over['topN'] !== '') {
        $one['topN'] = max(0, min(50, (int) $over['topN']));
    }

    $chg = strtolower(trim((string) (isset($over['change']) ? $over['change'] : '')));

    if (in_array($chg, ['24h', 'tz'], true)) {
        $one['change'] = $chg;
    }

    // 这里只做「原样带上、限长」：本函数运行在 require lib.php 之前，
    // 不能调 ttcoin_tz_parse()；真正归一化交给 lib 的 ttcoin_face_config()
    $tz = trim((string) (isset($over['tz']) ? $over['tz'] : ''));

    if ($tz !== '') {
        $one['tz'] = substr($tz, 0, 8);
    }

    foreach (['coin', 'order'] as $key) {
        $list = strtoupper(preg_replace('/[^A-Za-z0-9,]/', '', (string) (isset($over[$key]) ? $over[$key] : '')));

        if ($list !== '') {
            $one[$key] = $list;
        }
    }

    return $one;
}

// ---------------------------------------------------------------- 预览
// 必须在 require lib.php **之前**：先把候选值 define 出来，lib 的
// ttcoin_face_define() 看到已定义就跳过，于是拿到的就是「保存后会生效」的结果。
// 这样预览与线上走的是同一份解析代码，不存在两套逻辑跑偏的问题。
// 设备用户也走这个入口：候选 = 当前已保存配置 + 只覆盖自己那一行。
if ($action === 'preview') {
    $ownerMode = admin_owner_logged_in() && !admin_logged_in();

    if (!admin_csrf_ok(isset($body['_csrf']) ? $body['_csrf'] : '')) {
        json_out(['ok' => false, 'message' => '页面已过期，刷新后重试'], 419);
    }

    if ($ownerMode) {
        $settings = admin_load_settings();
        $ids      = is_array($settings['TTCOIN_FACE_IDS']) ? $settings['TTCOIN_FACE_IDS'] : [];
        $over     = isset($body['over']) && is_array($body['over']) ? $body['over'] : [];

        // 预览 = 已保存配置 + 本次覆盖合并（本次没填的项保留已保存值），
        // 否则保存过的涨幅口径之类会被预览悄悄丢掉，显示结果和保存后不一致
        $savedOne = isset($ids[$_SESSION['owner_id']]) && is_array($ids[$_SESSION['owner_id']])
            ? $ids[$_SESSION['owner_id']]
            : [];

        $ids[$_SESSION['owner_id']] = array_merge($savedOne, admin_light_clean_over($over));
        $settings['TTCOIN_FACE_IDS'] = $ids;

        $candidate = $settings;
        $id        = $_SESSION['owner_id'];
    } else {
        if (!admin_logged_in()) {
            json_out(['ok' => false, 'message' => '未登录'], 401);
        }

        $candidate = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : [];
        $id        = admin_clean_id(isset($body['id']) ? $body['id'] : '');
    }

    if (isset($candidate['TTCOIN_FACE_IDS']) && is_array($candidate['TTCOIN_FACE_IDS'])) {
        $candidate['TTCOIN_FACE_IDS'] = json_encode(
            (object) $candidate['TTCOIN_FACE_IDS'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    foreach (admin_config_keys() as $key) {
        if (!array_key_exists($key, $candidate)) {
            continue;
        }

        $value = $candidate[$key];

        if ($key === 'TTCOIN_WIDGET_LIMIT') {
            $value = (bool) $value;
        } elseif (in_array($key, ['TTCOIN_FACE_TOP_N', 'TTCOIN_WIDGET_TOP_N', 'TTCOIN_CACHE_TTL', 'TTCOIN_STALE_SERVE'], true)) {
            $value = (int) $value;
        } else {
            $value = (string) $value;
        }

        if (!defined($key)) {
            define($key, $value);
        }
    }

    require ADMIN_DIR . '/lib.php';

    $cfg = ttcoin_face_config($id);

    // 模拟输出：优先用真实缓存（就是手表此刻会拿到的行情），没缓存就用假数据
    $json = ttcoin_cache_read();

    if ($json === null) {
        $fake = [];

        foreach (ttcoin_symbols() as $base) {
            $fake[] = ['symbol' => $base, 'base' => $base, 'price' => '1.0000', 'change' => '0.00'];
        }

        $json = ttcoin_encode($fake);
    }

    $data = json_decode((string) $json, true);
    $out  = is_array($data) ? ttcoin_face_output($data, $cfg) : [];
    $coins = [];

    foreach ($out as $item) {
        $coins[] = isset($item['symbol']) ? $item['symbol'] : '';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir    = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

    // 实际生效的涨幅口径（含 id 自己的时区），预览里一并回显
    $effChg  = ttcoin_face_change($cfg);
    $effTz   = ttcoin_tz_parse($effChg['tz']);

    json_out([
        'ok'     => true,
        'id'     => $id,
        'known'  => $id !== '' ? ttcoin_face_id_known($id) : null,
        'config' => [
            'mode'  => $cfg['mode'],
            'fixed' => $cfg['fixed'],
            'topN'  => $cfg['topN'],
            'coin'  => $cfg['coin'],
            'chg'   => isset($cfg['chg']) ? $cfg['chg'] : '',
            'chgTz' => isset($cfg['chgTz']) ? $cfg['chgTz'] : '',
            'chgTzLabel' => ($effChg['mode'] === 'tz' && $effTz !== null) ? $effTz['label'] : '',
            'order' => implode(',', $cfg['order']),
        ],
        'count'  => count($out),
        'coins'  => $coins,
        'curl'   => 'curl -sD - "' . $scheme . '://' . $host . $dir . '/coin.php?type=face'
                    . ($id !== '' ? '&id=' . rawurlencode($id) : '') . '"',
    ]);
}

// ===========================================================================
// 已登录：加载真实配置
// ===========================================================================
if (is_file(ADMIN_CONFIG_FILE)) {
    require ADMIN_CONFIG_FILE;
}

require ADMIN_DIR . '/lib.php';

$pool = array_values(ttcoin_symbols());

if ($action === 'save') {
    if (!admin_logged_in()) {
        json_out(['ok' => false, 'message' => '未登录'], 401);
    }

    if (!admin_csrf_ok(isset($body['_csrf']) ? $body['_csrf'] : '')) {
        json_out(['ok' => false, 'message' => '页面已过期，刷新后重试'], 419);
    }

    $input = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : [];
    list($clean, $errors, $warnings) = admin_validate($input, $pool);

    if (!empty($errors)) {
        json_out(['ok' => false, 'message' => '有 ' . count($errors) . ' 项需要修正', 'errors' => $errors], 422);
    }

    // 只保留有值的项，空的 DATA_DIR / PROXY / RESOLVE / TOKEN 不写（走默认）
    foreach (['TTCOIN_DATA_DIR', 'TTCOIN_PROXY', 'TTCOIN_RESOLVE', 'TTCOIN_DIAG_TOKEN'] as $key) {
        if (isset($clean[$key]) && $clean[$key] === '') {
            unset($clean[$key]);
        }
    }

    if (!admin_save_settings($clean)) {
        json_out(['ok' => false, 'message' => '写不进 admin_settings.json，检查目录权限'], 500);
    }

    $configOk = admin_write_config();

    json_out([
        'ok'       => true,
        'message'  => $configOk ? '已保存，配置已生效' : '设置已保存，但 config.php 写入失败（检查目录权限）',
        'config'   => $configOk,
        'time'     => date('Y-m-d H:i:s'),
        'warnings' => $warnings,
    ]);
}

// —— 设备用户保存：只允许改自己那一行，且只动 FACE_IDS，别的键一概不碰 ——
if ($action === 'owner_save') {
    if (!admin_owner_logged_in()) {
        json_out(['ok' => false, 'message' => '未登录'], 401);
    }

    if (!defined('ADMIN_OWNER_ENTRY')) {
        json_out(['ok' => false, 'message' => '设备用户请从 owner.php 登录'], 403);
    }

    if (!admin_csrf_ok(isset($body['_csrf']) ? $body['_csrf'] : '')) {
        json_out(['ok' => false, 'message' => '页面已过期，刷新后重试'], 419);
    }

    $id   = (string) $_SESSION['owner_id'];
    $over = isset($body['over']) && is_array($body['over']) ? $body['over'] : [];

    list($one, $errors) = admin_clean_id_over($over, $pool, $id);

    if (!empty($errors)) {
        json_out(['ok' => false, 'message' => '有 ' . count($errors) . ' 项需要修正', 'errors' => $errors], 422);
    }

    // 与 worker 端同一策略：读原始 JSON，只改 FACE_IDS 自己那行，其余键原样保留
    // （不能拿 admin_load_settings() 的合并结果整体写回 —— 那会把没保存过的
    // 默认值固化进 JSON，以后 lib 升级改默认也改不动了）
    $raw = json_decode((string) @file_get_contents(ADMIN_SETTINGS_FILE), true);

    if (!is_array($raw)) {
        $raw = [];
    }

    $ids = isset($raw['TTCOIN_FACE_IDS']) && is_array($raw['TTCOIN_FACE_IDS']) ? $raw['TTCOIN_FACE_IDS'] : [];
    $ids[$id] = $one;
    $raw['TTCOIN_FACE_IDS'] = $ids;

    if (!admin_save_settings($raw)) {
        json_out(['ok' => false, 'message' => '写不进 admin_settings.json，检查目录权限'], 500);
    }

    $configOk = admin_write_config();

    // 与 admin_validate 同一条软提醒：coin 优先于 fixed
    $warnings = [];

    if (isset($one['fixed']) && $one['fixed'] !== '') {
        $merged = admin_load_settings();
        $globalCoin = (string) (isset($merged['TTCOIN_FACE_COIN']) ? $merged['TTCOIN_FACE_COIN'] : '');

        if ($globalCoin !== '') {
            $first = explode(',', $globalCoin);
            $first = $first[0];
            $own = isset($one['coin']) ? explode(',', $one['coin']) : [];
            $ownFirst = !empty($own) ? $own[0] : '';

            if ($ownFirst === '' && $first !== $one['fixed']) {
                $warnings[] = '全局主币种是 ' . $first . '（coin 优先，会覆盖固定币）；'
                    . '要固定 ' . $one['fixed'] . '，请把主币种也填成 ' . $one['fixed'];
            } elseif ($ownFirst !== '' && $ownFirst !== $one['fixed']) {
                $warnings[] = '固定币是 ' . $one['fixed'] . '，但主币种第一项是 ' . $ownFirst . '，实际会显示 ' . $ownFirst;
            }
        }
    }

    json_out([
        'ok'       => true,
        'message'  => $configOk ? '已保存，配置已生效' : '已保存，但 config.php 写入失败（请联系管理员）',
        'time'     => date('Y-m-d H:i:s'),
        'warnings' => $warnings,
    ]);
}

if ($action === 'log') {
    if (!admin_logged_in()) {
        json_out(['ok' => false, 'message' => '未登录'], 401);
    }

    $file  = ttcoin_log_file();
    $lines = isset($body['lines']) ? (int) $body['lines'] : 300;
    $lines = max(20, min(2000, $lines));
    $raw   = admin_tail_lines($file, $lines);
    $rows  = [];

    foreach ($raw as $line) {
        if ($line === '') {
            continue;
        }

        $col = explode("\t", $line);

        $rows[] = [
            'time'   => isset($col[0]) ? $col[0] : '',
            'type'   => isset($col[1]) ? $col[1] : '',
            'id'     => isset($col[2]) ? $col[2] : '',
            'uid'    => isset($col[3]) ? $col[3] : '',
            'ip'     => isset($col[4]) ? $col[4] : '',
            'event'  => isset($col[5]) ? $col[5] : '',
            'detail' => isset($col[6]) ? $col[6] : '',
        ];
    }

    json_out([
        'ok'    => true,
        'file'  => $file,
        'size'  => is_file($file) ? filesize($file) : 0,
        'rows'  => array_reverse($rows),
    ]);
}

// ---------------------------------------------------------------- 页面数据
$settings   = admin_load_settings();
$loggedIn   = admin_logged_in();
$hasPass    = admin_pass_hash() !== null;
$csrf       = admin_csrf();

$configMode = 'none';

if (is_file(ADMIN_CONFIG_FILE)) {
    $configMode = strpos((string) @file_get_contents(ADMIN_CONFIG_FILE), ADMIN_MARK) === false
        ? 'legacy'
        : 'managed';
}

// 运行时状态
$dataDir   = ttcoin_data_dir();
$cacheFile = ttcoin_cache_file();
$cacheAge  = ttcoin_cache_age();
$logFile   = ttcoin_log_file();
$upstream  = '';

if (is_file($dataDir . '/cache/upstream.txt')) {
    $upstream = trim((string) @file_get_contents($dataDir . '/cache/upstream.txt'));
}

$orderList = array_values(array_filter(explode(',', (string) $settings['TTCOIN_FACE_ORDER'])));
$coinList  = array_values(array_filter(explode(',', (string) $settings['TTCOIN_FACE_COIN'])));
$idsMap    = is_array($settings['TTCOIN_FACE_IDS']) ? $settings['TTCOIN_FACE_IDS'] : [];

?>
<?php
// ===========================================================================
// 设备用户页面（ID + 管理码登录）：只能改自己那一行的独立配置。
// 只从 owner.php（设备用户入口）渲染；管理员后台 admin.php 不掺和这两套页面，
// 管理员登录和设备用户登录彻底分开。
// ===========================================================================
$ownerId = admin_owner_logged_in() ? $_SESSION['owner_id'] : null;

if (defined('ADMIN_OWNER_ENTRY') && $ownerId !== null):
    // 前面主流程已 require 过 config/lib，这里用 require_once 防重复声明
    if (is_file(ADMIN_CONFIG_FILE)) {
        require_once ADMIN_CONFIG_FILE;
    }

    require_once ADMIN_DIR . '/lib.php';

    $pool     = array_values(ttcoin_symbols());
    $all      = admin_load_settings();
    $idsMapO  = is_array($all['TTCOIN_FACE_IDS']) ? $all['TTCOIN_FACE_IDS'] : [];
    $mine     = isset($idsMapO[$ownerId]) && is_array($idsMapO[$ownerId]) ? $idsMapO[$ownerId] : [];
    $csrfO    = admin_csrf();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TTCoin 设备配置</title>
<style>
:root{--bg:#f4f6f8;--card:#fff;--line:#e3e8ee;--text:#1f2937;--muted:#6b7280;
  --accent:#0f766e;--accent-soft:#e8f4f2;--mono:ui-monospace,SFMono-Regular,Consolas,"Courier New",monospace}
*{box-sizing:border-box}
html,body{max-width:100%;overflow-x:hidden}
body{margin:0;background:var(--bg);color:var(--text);
  font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}
.wrap{max-width:640px;margin:0 auto;padding:24px 16px 40px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;
  padding:18px 16px;margin-bottom:14px}
h1{font-size:18px;margin:0 0 4px}
.sub{color:var(--muted);font-size:12.5px;margin:0 0 16px}
.sub .mono{font-family:var(--mono)}
label{display:block;font-size:12.5px;color:#374151;font-weight:550;margin:12px 0 5px}
input[type=text],input[type=number],select{width:100%;padding:8px 10px;border:1px solid #d5dbe3;
  border-radius:7px;background:#fff;font:inherit;font-size:14px;color:var(--text);outline:none}
input:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(15,118,110,.12)}
.tip{font-size:11.5px;color:var(--muted);margin-top:6px;line-height:1.45}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 16px;
  border-radius:8px;border:1px solid #d5dbe3;background:#fff;color:#374151;font:inherit;
  font-size:13.5px;cursor:pointer}
.btn:disabled{opacity:.55;cursor:default}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:550}
.note{border-radius:8px;padding:10px 12px;font-size:12.5px;line-height:1.55;margin:12px 0 0;display:none}
.note.warn{background:#fff8e1;border:1px solid #f0e0b8;color:#6b4e00;display:block}
.note.err{background:#fdecea;border:1px solid #f5c9c4;color:#8c1d18;display:block}
.note.ok{background:var(--accent-soft);border:1px solid #b9ddd7;color:#0b5f59;display:block}
#pvOut{display:none;background:#10222b;color:#d8e6ee;border-radius:8px;padding:10px 12px;
  font:12.5px/1.7 var(--mono);white-space:pre-wrap;word-break:break-all;margin-top:12px}
@media (max-width:480px){.wrap{padding:16px 10px 32px}.card{padding:15px 12px}}
</style>
</head>
<body>
<div class="wrap">
  <h1>我的行情配置</h1>
  <p class="sub">设备 ID <span class="mono"><?php echo h($ownerId); ?></span> ·
    <a href="owner.php?action=owner_logout">退出</a></p>

  <div class="card">
    <label>模式</label>
    <select id="mode">
      <option value="">跟随默认</option>
      <option value="scroll"<?php echo (isset($mine['mode']) && $mine['mode'] === 'scroll') ? ' selected' : ''; ?>>scroll 滚屏</option>
      <option value="fixed"<?php echo (isset($mine['mode']) && $mine['mode'] === 'fixed') ? ' selected' : ''; ?>>fixed 固定</option>
    </select>
    <div class="tip">scroll：按顺序逐条轮播；fixed：只显示一个币。</div>

    <label>固定币（fixed 模式生效）</label>
    <select id="fixed">
      <option value="">跟随默认</option>
      <?php foreach ($pool as $coin): ?>
        <option value="<?php echo h($coin); ?>"<?php echo (isset($mine['fixed']) && $mine['fixed'] === $coin) ? ' selected' : ''; ?>><?php echo h($coin); ?></option>
      <?php endforeach; ?>
    </select>

    <label>主币种（排最前，可多个，逗号分隔）</label>
    <input type="text" id="coin" placeholder="留空 = 继承默认" value="<?php echo h(isset($mine['coin']) ? $mine['coin'] : ''); ?>">

    <label>条数上限（scroll 模式）</label>
    <input type="number" id="topn" min="0" max="50" placeholder="留空 = 继承默认"
      value="<?php echo isset($mine['topN']) ? (int) $mine['topN'] : ''; ?>">

    <label>顺序（可选，逗号分隔币种）</label>
    <input type="text" id="order" placeholder="留空 = 继承默认顺序" value="<?php echo h(isset($mine['order']) ? $mine['order'] : ''); ?>">

    <label>涨幅口径</label>
    <select id="chg">
      <option value="">跟随默认</option>
      <option value="24h"<?php echo (isset($mine['change']) && $mine['change'] === '24h') ? ' selected' : ''; ?>>24 小时滚动</option>
      <option value="tz"<?php echo (isset($mine['change']) && $mine['change'] === 'tz') ? ' selected' : ''; ?>>自然日（按时区）</option>
    </select>
    <label>自然日时区偏移</label>
    <input type="text" id="otz" placeholder="留空 = 跟随默认（8 / -5 / 5:30）" value="<?php echo h(isset($mine['tz']) ? $mine['tz'] : ''); ?>">
    <div class="tip">自然日：以「该时区当日 00:00」开盘价为基准重算涨幅；时区只在这台选自然日（或全局默认就是自然日）时生效。</div>

    <div class="tip">留空的项 = 继承管理员的全局默认。注意主币种（coin）优先于固定币（fixed）。</div>

    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn primary" style="flex:1" id="saveBtn">保存并生效</button>
      <button class="btn" style="flex:1" id="pvBtn">预览</button>
    </div>
    <div class="note" id="note"></div>
    <div id="pvOut"></div>
  </div>
</div>
<script>
const CSRF = <?php echo json_encode($csrfO); ?>;
function $(id){ return document.getElementById(id); }
function note(msg, cls){
  const el = $('note');
  el.textContent = msg || '';
  el.className = 'note' + (cls ? ' ' + cls : '');
}
async function post(action, payload){
  const res = await fetch(location.pathname + '?action=' + action, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(Object.assign({_csrf: CSRF}, payload || {}))
  });
  return res.json();
}
function collect(){
  const over = {};
  const mode = $('mode').value;
  if(mode) over.mode = mode;
  const fx = $('fixed').value;
  if(fx) over.fixed = fx;
  const coin = $('coin').value.trim();
  if(coin) over.coin = coin;
  const tn = $('topn').value.trim();
  if(tn !== '') over.topN = parseInt(tn, 10);
  const od = $('order').value.trim();
  if(od) over.order = od;
  const chg = $('chg').value;
  if(chg) over.change = chg;
  const otz = $('otz').value.trim();
  if(otz) over.tz = otz;
  return over;
}
$('saveBtn').onclick = async function(){
  const btn = $('saveBtn');
  btn.disabled = true;
  btn.textContent = '保存中…';
  try{
    const r = await post('owner_save', {over: collect()});
    if(r.ok){
      note('已保存 ' + (r.time || '') + ((r.warnings || []).length ? '  ⚠ ' + r.warnings.join('；') : ''), (r.warnings || []).length ? 'warn' : 'ok');
    }else{
      note((r.errors && r.errors.length ? r.errors.join('；') : r.message) || '保存失败', 'err');
    }
  }catch(e){
    note('请求失败：' + e.message, 'err');
  }
  btn.disabled = false;
  btn.textContent = '保存并生效';
};
$('pvBtn').onclick = async function(){
  const out = $('pvOut');
  out.style.display = 'block';
  out.textContent = '计算中…';
  try{
    const r = await post('preview', {over: collect()});
    if(!r.ok){ out.textContent = r.message || '预览失败'; return; }
    const lines = [];
    lines.push('生效模式：' + r.config.mode + (r.config.mode === 'fixed' ? '（只回一条）' : ''));
    lines.push('固定币：' + (r.config.fixed || '—'));
    lines.push('条数上限：' + (r.config.topN === 0 ? '不限' : r.config.topN));
    lines.push('主币种：' + (r.config.coin || '—（不重排）'));
    lines.push('涨幅口径：' + (r.config.chg ? (r.config.chg === 'tz' ? '自然日' : '24 小时滚动') : '跟随全局默认') +
      (r.config.chgTzLabel ? '  时区=' + r.config.chgTzLabel : ''));
    lines.push('最终顺序：' + r.config.order);
    lines.push('表盘会收到 ' + r.count + ' 条：' + r.coins.join(' → '));
    out.textContent = lines.join('\n');
  }catch(e){
    out.textContent = '请求失败：' + e.message;
  }
};
</script>
</body>
</html>
<?php exit; endif; ?>

<?php if (defined('ADMIN_OWNER_ENTRY') && $ownerId === null): ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TTCoin 设备管理登录</title>
<style>
:root{--bg:#f4f6f8;--card:#fff;--line:#e3e8ee;--text:#1f2937;--muted:#6b7280;
  --accent:#0f766e;--accent-soft:#e8f4f2;--mono:ui-monospace,SFMono-Regular,Consolas,"Courier New",monospace}
*{box-sizing:border-box}
html,body{max-width:100%;overflow-x:hidden}
body{margin:0;background:var(--bg);color:var(--text);
  font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}
.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.auth-card{width:100%;max-width:380px;background:var(--card);border:1px solid var(--line);
  border-radius:14px;padding:28px 26px;box-shadow:0 1px 3px rgba(16,24,40,.06)}
h1{font-size:19px;margin:0 0 6px}
.sub{color:var(--muted);font-size:13px;margin:0 0 20px}
label{display:block;font-size:12.5px;color:#374151;margin-bottom:5px;font-weight:550}
input[type=text]{width:100%;padding:8px 10px;border:1px solid #d5dbe3;border-radius:7px;
  background:#fff;font:inherit;font-size:14px;color:var(--text);outline:none}
input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(15,118,110,.12)}
.field{margin-bottom:14px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 16px;
  border-radius:8px;border:1px solid #d5dbe3;background:#fff;color:#374151;font:inherit;
  font-size:13.5px;cursor:pointer}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:550}
.tip{font-size:11.5px;color:var(--muted);margin-top:16px;line-height:1.5}
.mono{font-family:var(--mono)}
.toast{position:fixed;right:20px;bottom:20px;display:flex;flex-direction:column;gap:8px}
.toast div{background:#1f2937;color:#fff;padding:9px 14px;border-radius:8px;font-size:13px}
.toast div.err{background:#8c1d18}
</style>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <h1>TTCoin 设备管理</h1>
    <p class="sub">输入自己的设备 ID 和管理员发的管理码</p>

    <div class="field">
      <label>设备 ID</label>
      <input type="text" id="oid" class="mono" maxlength="32" placeholder="TC…" autocomplete="off">
    </div>
    <div class="field">
      <label>管理码</label>
      <input type="text" id="ocode" class="mono" maxlength="32" placeholder="管理员发给你那个" autocomplete="off"
        onkeydown="if(event.key==='Enter')doOwnerLogin()">
    </div>
    <button class="btn primary" style="width:100%" onclick="doOwnerLogin()">登录</button>

    <p class="tip">
      只能改自己设备的显示配置，看不到全局设置和日志。管理码找管理员要。
    </p>
  </div>
</div>
<div class="toast" id="toast"></div>
<script>
const CSRF = <?php echo json_encode($csrf); ?>;
function toast(msg, kind){
  const box = document.getElementById('toast');
  const el = document.createElement('div');
  el.className = kind || '';
  el.textContent = msg;
  box.appendChild(el);
  setTimeout(() => el.remove(), 3200);
}
async function post(action, payload){
  const res = await fetch(location.pathname + '?action=' + action, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify(Object.assign({_csrf: CSRF}, payload || {}))
  });
  return res.json();
}
async function doOwnerLogin(){
  const id = document.getElementById('oid').value.trim().replace(/[^A-Za-z0-9]/g, '');
  const code = document.getElementById('ocode').value.trim();
  if(!id || !code){ toast('ID 和管理码都要填', 'err'); return; }
  const r = await post('owner_login', {id:id, code:code});
  if(r.ok){ location.reload(); } else { toast(r.message || '登录失败', 'err'); }
}
</script>
</body>
</html>
<?php exit; endif; ?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TTCoin 后台</title>
<style>
:root{
  --bg:#f4f6f8; --card:#ffffff; --line:#e3e8ee; --text:#1f2937; --muted:#6b7280;
  --accent:#0f766e; --accent-soft:#e8f4f2; --accent-line:#b9ddd7;
  --danger:#b3261e; --danger-soft:#fdecea; --warn:#9a6700; --warn-soft:#fff8e1;
  --radius:10px; --mono:ui-monospace,SFMono-Regular,Consolas,"Courier New",monospace;
}
*{box-sizing:border-box}
html,body{max-width:100%;overflow-x:hidden}
body{margin:0;background:var(--bg);color:var(--text);
  font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}
a{color:var(--accent)}
code{font-family:var(--mono);font-size:12.5px;background:#f1f3f5;padding:1px 5px;border-radius:4px}
/* 登录 */
.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.auth-card{width:100%;max-width:380px;background:var(--card);border:1px solid var(--line);
  border-radius:14px;padding:28px 26px;box-shadow:0 1px 3px rgba(16,24,40,.06)}
.auth-card h1{font-size:19px;margin:0 0 6px}
.auth-card p.sub{margin:0 0 20px;color:var(--muted);font-size:13px}
/* 布局 */
.shell{display:flex;min-height:100vh}
.side{width:196px;flex:0 0 196px;background:var(--card);border-right:1px solid var(--line);
  padding:18px 12px;position:sticky;top:0;height:100vh}
.brand{font-weight:650;font-size:15px;padding:0 10px 14px;letter-spacing:.2px}
.brand span{display:block;font-weight:400;font-size:11.5px;color:var(--muted);margin-top:2px}
.nav a{display:block;padding:8px 10px;border-radius:8px;color:#374151;text-decoration:none;
  font-size:13.5px;margin-bottom:2px}
.nav a:hover{background:#f5f7f9}
.nav a.on{background:var(--accent-soft);color:var(--accent);font-weight:600}
.side-foot{position:absolute;bottom:78px;left:12px;right:12px;font-size:11.5px;color:var(--muted);
  max-height:56px;overflow:hidden}
.main{flex:1;min-width:0;padding:22px 26px 96px;max-width:1080px}
.head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;
  margin-bottom:18px;flex-wrap:wrap}
.head h1{font-size:20px;margin:0 0 4px}
.head p{margin:0;color:var(--muted);font-size:13px}
/* 卡片 */
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
  padding:18px 20px;margin-bottom:14px}
.card h2{font-size:14px;margin:0 0 4px;font-weight:650}
.card .hint{color:var(--muted);font-size:12.5px;margin:0 0 16px}
.card h3{font-size:13px;margin:20px 0 8px;font-weight:600;color:#374151}
.card h3:first-of-type{margin-top:0}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
.field label{display:block;font-size:12.5px;color:#374151;margin-bottom:5px;font-weight:550}
.field .tip{font-size:11.5px;color:var(--muted);margin-top:5px;line-height:1.45}
input[type=text],input[type=password],input[type=number],select{
  width:100%;padding:7px 10px;border:1px solid #d5dbe3;border-radius:7px;background:#fff;
  font:inherit;font-size:13.5px;color:var(--text);outline:none}
input:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(15,118,110,.12)}
input[type=number]{max-width:140px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;
  border:1px solid #d5dbe3;background:#fff;color:#374151;font:inherit;font-size:13px;
  cursor:pointer;text-decoration:none}
.btn:hover{background:#f7f9fa}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:550}
.btn.primary:hover{background:#0d6a63}
.btn.ghost{border-color:transparent;color:var(--accent)}
.btn.tiny{padding:3px 8px;font-size:12px;border-radius:6px;white-space:nowrap}
.btn:disabled{opacity:.55;cursor:default}
.seg{display:inline-flex;border:1px solid #d5dbe3;border-radius:8px;overflow:hidden}
.seg label{padding:7px 16px;font-size:13px;cursor:pointer;border-right:1px solid #e6eaef;background:#fff}
.seg label:last-child{border-right:0}
.seg input{display:none}
.seg input:checked+span{color:var(--accent);font-weight:600}
.seg label:has(input:checked){background:var(--accent-soft)}
/* chips */
.chips{border:1px solid var(--line);border-radius:8px;padding:10px;background:#fbfcfd}
.chips .lbl{font-size:11.5px;color:var(--muted);margin-bottom:7px}
.chips .sel,.chips .pool{display:flex;flex-wrap:wrap;gap:6px;min-height:28px}
.chips .pool{margin-top:9px;padding-top:9px;border-top:1px dashed var(--line)}
.chip{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:6px;
  background:#fff;border:1px solid #d5dbe3;font-size:12.5px;font-family:var(--mono)}
.chip.sel{border-color:var(--accent-line);background:var(--accent-soft);color:#0b5f59}
.chip button{border:0;background:transparent;cursor:pointer;color:#8a94a2;padding:0 1px;
  font-size:13px;line-height:1}
.chip button:hover{color:var(--danger)}
.chip.pool-item{cursor:pointer;color:#4b5563}
.chip.pool-item:hover{border-color:var(--accent);color:var(--accent)}
/* 表格 */
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;font-size:11.5px;color:var(--muted);font-weight:600;padding:6px 8px;
  border-bottom:1px solid var(--line);white-space:nowrap}
td{padding:7px 8px;border-bottom:1px solid #f0f3f6;vertical-align:middle}
tr:last-child td{border-bottom:0}
td input,td select{padding:5px 8px;font-size:12.5px}
.mono{font-family:var(--mono);font-size:12.5px}
/* 提示条 */
.note{border-radius:8px;padding:10px 12px;font-size:12.5px;line-height:1.55;margin:0 0 14px}
.note.info{background:var(--accent-soft);border:1px solid var(--accent-line);color:#0b5f59}
.note.warn{background:var(--warn-soft);border:1px solid #f0e0b8;color:#6b4e00}
.note.danger{background:var(--danger-soft);border:1px solid #f5c9c4;color:#8c1d18}
/* 状态 */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.stat{background:#fbfcfd;border:1px solid var(--line);border-radius:9px;padding:10px 12px}
.stat .k{font-size:11.5px;color:var(--muted)}
.stat .v{font-size:16px;font-weight:650;margin-top:2px;font-family:var(--mono)}
.badge{display:inline-block;padding:1px 7px;border-radius:999px;font-size:11.5px;
  background:#eef1f4;color:#4b5563}
.badge.ok{background:var(--accent-soft);color:#0b5f59}
.badge.bad{background:var(--danger-soft);color:#8c1d18}
/* 底部保存条 */
.savebar{position:fixed;left:0;right:0;bottom:0;background:rgba(255,255,255,.96);
  border-top:1px solid var(--line);padding:10px 24px;display:flex;align-items:center;
  justify-content:space-between;gap:12px;backdrop-filter:blur(6px);z-index:30}
.savebar .state{font-size:12.5px;color:var(--muted)}
/* 模态 */
.modal{position:fixed;inset:0;background:rgba(16,24,40,.42);display:none;
  align-items:center;justify-content:center;padding:20px;z-index:60}
.modal.on{display:flex}
.modal .box{background:#fff;border-radius:12px;max-width:620px;width:100%;
  max-height:86vh;overflow:auto;padding:22px 24px;box-shadow:0 18px 40px rgba(16,24,40,.18)}
.modal h2{font-size:16px;margin:0 0 3px}
.modal .sub{color:var(--muted);font-size:12.5px;margin:0 0 16px}
.toast{position:fixed;right:20px;bottom:74px;z-index:80;display:flex;flex-direction:column;gap:8px}
.toast div{background:#1f2937;color:#fff;padding:9px 14px;border-radius:8px;font-size:13px;
  box-shadow:0 6px 18px rgba(16,24,40,.18);max-width:420px}
.toast div.err{background:#8c1d18}
.toast div.ok{background:#0b5f59}
.panel{display:none}
.panel.on{display:block}
.scrollbox{overflow:auto;max-height:60vh}
@media (max-width:860px){
  .shell{flex-direction:column;overflow-x:hidden;width:100%}
  .side{width:100%;max-width:100vw;flex:none;height:auto;position:static;border-right:0;
    border-bottom:1px solid var(--line);min-width:0}
  .nav{display:flex;overflow-x:auto;overflow-y:hidden;gap:4px;min-width:0;max-width:100%}
  .nav a{white-space:nowrap;margin:0}
  .side-foot{display:none}
  .main{padding:16px 14px 96px;overflow-x:hidden}
  .head{flex-direction:column;align-items:stretch}
}
#idsTable input[data-f=tz]{max-width:78px}

/* —— 窄屏：输入全宽、两张表格转卡片（手机上一行一卡，不再横向溢出） —— */
@media (max-width:700px){
  .main{padding:14px 10px 96px}
  .card{padding:14px 12px}
  .savebar{padding:10px 12px}
  .scrollbox{max-height:none;overflow:visible}
  .grid{grid-template-columns:1fr}
  #idsTable,#idsTable tbody,#logTable,#logTable tbody,#codesTable,#codesTable tbody{display:block}
  #idsTable thead,#logTable thead,#codesTable thead{display:none}
  #idsTable tr,#logTable tr,#codesTable tr{display:block;border:1px solid var(--line);border-radius:9px;
    padding:8px 10px 4px;margin-bottom:10px;background:#fbfcfd}
  /* 占位行（colspan 提示）退回普通块，不吃卡片栅格 */
  #idsTable td[colspan],#logTable td[colspan],#codesTable td[colspan]{display:block;padding:6px 0}
  #idsTable td,#codesTable td{display:grid;grid-template-columns:64px 1fr;gap:8px;align-items:center;
    border:0;padding:5px 0;font-size:13px;white-space:normal;min-width:0}
  #idsTable td::before,#codesTable td::before{content:attr(data-label);font-size:12px;color:var(--muted)}
  #idsTable td:last-child,#codesTable td:last-child{display:flex;justify-content:flex-end;gap:8px;padding:8px 0 4px;
    border-top:1px dashed var(--line);margin-top:4px}
  #idsTable td:last-child::before,#codesTable td:last-child::before{content:''}
  #idsTable td input,#idsTable td select{width:100%;max-width:none;font-size:13px;padding:7px 9px}
  #logTable tr{display:flex;flex-wrap:wrap;gap:4px 12px;align-items:baseline}
  #logTable td{display:inline-flex;gap:4px;border:0;padding:0;font-size:12px;
    white-space:normal;word-break:break-all;min-width:0}
  #logTable td::before{content:attr(data-label);font-size:11px;color:var(--muted);flex:none}
}
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<div class="auth-wrap">
  <div class="auth-card">
    <h1><?php echo $hasPass ? 'TTCoin 后台' : '初始化后台密码'; ?></h1>
    <p class="sub"><?php echo $hasPass ? '输入管理密码继续' : '首次使用，先设置一个管理密码（至少 8 位）'; ?></p>

    <?php if (!$hasPass): ?>
      <div class="field" style="margin-bottom:12px">
        <label>管理密码</label>
        <input type="password" id="p1" autocomplete="new-password" placeholder="至少 8 位">
      </div>
      <div class="field" style="margin-bottom:16px">
        <label>再输一次</label>
        <input type="password" id="p2" autocomplete="new-password">
      </div>
      <button class="btn primary" style="width:100%" onclick="doSetPass()">设置并进入</button>
    <?php else: ?>
      <div class="field" style="margin-bottom:16px">
        <label>密码</label>
        <input type="password" id="p1" autocomplete="current-password" onkeydown="if(event.key==='Enter')doLogin()">
      </div>
      <button class="btn primary" style="width:100%" onclick="doLogin()">登录</button>
    <?php endif; ?>

    <p class="tip" style="font-size:11.5px;color:var(--muted);margin-top:16px;line-height:1.5">
      密码以 hash 形式存在 <code>admin_pass.php</code>，忘记就删掉该文件重新设置。
      设备用户不走这里，入口是 <code>owner.php</code>（用设备 ID + 管理码登录）。
    </p>
  </div>
</div>
<div class="toast" id="toast"></div>
<script>
const CSRF = <?php echo json_encode($csrf); ?>;
function toast(msg, kind){
  const box = document.getElementById('toast');
  const el = document.createElement('div');
  el.className = kind || '';
  el.textContent = msg;
  box.appendChild(el);
  setTimeout(() => el.remove(), 3200);
}
async function post(action, payload){
  const res = await fetch(location.pathname + '?action=' + action, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify(Object.assign({_csrf: CSRF}, payload || {}))
  });
  return res.json();
}
async function doLogin(){
  const p = document.getElementById('p1').value;
  if(!p){ toast('请输入密码'); return; }
  const r = await post('login', {password:p});
  if(r.ok){ location.reload(); } else { toast(r.message || '登录失败', 'err'); }
}
async function doSetPass(){
  const p1 = document.getElementById('p1').value;
  const p2 = document.getElementById('p2').value;
  if(p1.length < 8){ toast('密码至少 8 位'); return; }
  if(p1 !== p2){ toast('两次输入不一致'); return; }
  const r = await post('setpass', {password:p1, confirm:p2});
  if(r.ok){ location.reload(); } else { toast(r.message || '设置失败', 'err'); }
}
</script>
</body>
</html>
<?php exit; endif; ?>

<div class="shell">
  <aside class="side">
    <div class="brand">TTCoin 后台<span>PHP 后端 · v<?php echo ADMIN_VERSION; ?></span></div>
    <nav class="nav" id="nav">
      <a href="#quote" class="on" data-panel="quote">行情默认配置</a>
      <a href="#ids" data-panel="ids">按 ID 配置</a>
      <a href="#client" data-panel="client">客户端出口</a>
      <a href="#system" data-panel="system">缓存与上游</a>
      <a href="#logs" data-panel="logs">请求日志</a>
    </nav>
    <div class="side-foot">
      数据目录<br><span class="mono" style="font-size:11px;word-break:break-all"><?php echo h($dataDir); ?></span>
    </div>
  </aside>

  <main class="main">
    <div class="head">
      <div>
        <h1>行情后端设置</h1>
        <p>改完点右下角保存，手表下一次请求就会用新配置（出口裁剪不影响缓存，不会触发重新抓取）。</p>
      </div>
      <div class="row">
        <button class="btn" onclick="openPreview('')">预览输出</button>
        <a class="btn ghost" href="admin.php?action=logout">退出</a>
      </div>
    </div>

    <?php if ($configMode === 'legacy'): ?>
      <div class="note warn">
        检测到已有的 <code>config.php</code>（不是后台生成的）。第一次保存时会被接管，原文件自动备份为
        <code>config.php.bak.日期-时间</code>，里面后台不认识的 <code>define</code> 不会自动迁移——
        如果原来写过别的配置项，保存后请打开备份核对一下。
      </div>
    <?php elseif ($configMode === 'none'): ?>
      <div class="note info">
        还没有 <code>config.php</code>，当前跑的是 lib.php 默认值 + 环境变量。保存后会生成一个只做
        <code>define</code> 的桥接文件，<code>coin.php</code> 不用改。
      </div>
    <?php endif; ?>

    <!-- ============================ 行情默认 ============================ -->
    <section class="panel on" id="panel-quote">
      <div class="card">
        <h2>运行状态</h2>
        <p class="hint">这些是实时读出来的，不是配置。</p>
        <div class="stats">
          <div class="stat">
            <div class="k">缓存年龄</div>
            <div class="v"><?php echo $cacheAge === null ? '无缓存' : $cacheAge . 's'; ?></div>
          </div>
          <div class="stat">
            <div class="k">缓存文件</div>
            <div class="v" style="font-size:12.5px"><?php echo $cacheFile && is_file($cacheFile) ? date('H:i:s', (int) filemtime($cacheFile)) : '—'; ?></div>
          </div>
          <div class="stat">
            <div class="k">优先上游</div>
            <div class="v" style="font-size:12.5px"><?php echo $upstream !== '' ? h(parse_url($upstream, PHP_URL_HOST)) : '自动探测'; ?></div>
          </div>
          <div class="stat">
            <div class="k">配置来源</div>
            <div class="v" style="font-size:12.5px"><?php echo $configMode === 'managed' ? '后台托管' : ($configMode === 'legacy' ? '旧 config.php' : '默认值'); ?></div>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>表盘显示方式</h2>
        <p class="hint">只影响 <code>?type=face</code> 的出口：缓存里始终是完整列表，切模式不用清缓存。</p>

        <div class="field" style="margin-bottom:18px">
          <label>滚动模式</label>
          <div class="seg">
            <label><input type="radio" name="TTCOIN_FACE_MODE" value="scroll" <?php echo $settings['TTCOIN_FACE_MODE'] === 'scroll' ? 'checked' : ''; ?>><span>scroll 滚屏</span></label>
            <label><input type="radio" name="TTCOIN_FACE_MODE" value="fixed" <?php echo $settings['TTCOIN_FACE_MODE'] === 'fixed' ? 'checked' : ''; ?>><span>fixed 固定</span></label>
          </div>
          <div class="tip">scroll：按下面顺序逐条轮播；fixed：只下发一个币，表盘停在第一个不翻页。</div>
        </div>

        <div class="field" style="margin-bottom:18px">
          <label>轮播顺序（点击加入 / × 移除 / ↑↓ 调序）</label>
          <div class="chips" data-chips="order"></div>
          <input type="hidden" data-key="TTCOIN_FACE_ORDER" id="in-order">
          <div class="tip">没列进来的币不会出现在表盘响应里。</div>
        </div>

        <div class="grid">
          <div class="field">
            <label>fixed 模式显示哪个币</label>
            <select data-key="TTCOIN_FACE_FIXED" id="in-fixed">
              <option value="">跟随顺序第一项</option>
              <?php foreach ($pool as $coin): ?>
                <option value="<?php echo h($coin); ?>" <?php echo $settings['TTCOIN_FACE_FIXED'] === $coin ? 'selected' : ''; ?>><?php echo h($coin); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="tip">只在 fixed 模式生效；留空 = 用顺序里的第一个。</div>
          </div>
          <div class="field">
            <label>scroll 最多几条</label>
            <input type="number" min="0" max="50" data-key="TTCOIN_FACE_TOP_N" id="in-topn" value="<?php echo (int) $settings['TTCOIN_FACE_TOP_N']; ?>">
            <div class="tip">0 = 按顺序全给；3 = 只轮播前三个。</div>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>主币种（三端共用）</h2>
        <p class="hint">face / app / widget 都生效：排到最前；face 的 fixed 模式下就是它。</p>
        <div class="chips" data-chips="coin"></div>
        <input type="hidden" data-key="TTCOIN_FACE_COIN" id="in-coin">
        <div class="tip" style="margin-top:8px">留空 = 不重排，走候选池默认顺序。可多选，按这里的顺序排优先级。</div>
      </div>
    </section>

    <!-- ============================ 按 ID ============================ -->
    <section class="panel" id="panel-ids">
      <div class="card">
        <h2>按 ID 覆盖配置</h2>
        <p class="hint">
          客户端（表盘 / APP / Widget）在手机设置里填了「行情配置 ID」就会带 <code>?id=</code> 上来。
          这里登记的 ID 按行覆盖默认配置，没填的项继承上面的默认值。
          留空的项 = 不覆盖；整行都空 = 只做登记（日志里不再带 <code>!</code>）。
          「涨幅口径 / 时区」两列：时区只在自然日口径下生效（<code>8</code> / <code>-5</code> / <code>5:30</code>），
          留空 = 跟随上面的全局时区，所以每台设备可以各算各的当地零点。
        </p>
        <div class="note info" style="margin-bottom:14px">
          注意：<b>主币种（coin）的优先级高于固定币（fixed）</b>。如果全局配了主币种，
          某行只写「固定币」而不写「主币种」，实际显示的会是全局主币种那一个——
          想在这一行固定某个币，请把该行的主币种也填成同一个币。
        </div>

        <div style="overflow-x:auto">
        <table id="idsTable">
          <thead>
            <tr>
              <th style="width:18%">ID（32 位内）</th>
              <th style="width:9%">模式</th>
              <th style="width:8%">固定币</th>
              <th style="width:13%">主币种</th>
              <th style="width:6%">条数</th>
              <th style="width:13%">顺序（可选）</th>
              <th style="width:9%">涨幅口径</th>
              <th style="width:8%">时区</th>
              <th style="width:114px"></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
        </div>

        <div class="row" style="margin-top:12px">
          <button class="btn" onclick="addIdRow()">+ 添加 ID</button>
          <button class="btn" onclick="openPreview('')">预览默认一套</button>
        </div>
      </div>

      <div class="card">
        <h2>设备管理码</h2>
        <p class="hint">
          给每台设备发一个管理码：设备用户打开 <code>owner.php</code>（设备用户入口，与管理员后台分开），
          输入自己的 ID + 管理码，就只能改自己这一行的独立配置
          （模式 / 固定币 / 主币种 / 条数 / 顺序 / 涨幅口径 / 时区），看不到全局配置、日志和其它设备。
          生成后把 ID 和码发给对方即可。
        </p>
        <div class="scrollbox">
          <table id="codesTable">
            <thead>
              <tr>
                <th style="width:38%">ID</th>
                <th style="width:24%">管理码</th>
                <th style="width:20%">生成时间</th>
                <th></th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="row" style="margin-top:12px">
          <select id="codesPickId" style="max-width:340px"></select>
          <button class="btn" onclick="genCode()">为选中 ID 生成管理码</button>
        </div>
        <div class="tip" style="font-size:11.5px;color:var(--muted);margin-top:6px">
          下拉列表来自上面「按 ID 配置」里已登记保存的 ID（改完记得先保存）。重新生成会立刻作废旧码。
        </div>
      </div>

      <div class="card">
        <h2>取 ID</h2>
        <p class="hint">ID 由客户端自动生成：<code>TC</code> + <code>uniqueIdentifier</code> 前 30 位，限长 32。</p>
        <p style="font-size:13px;margin:0 0 10px">
          三个客户端各有自己的 ID（<code>uniqueIdentifier</code> 按应用隔离）。日志第 3 列就是它，
          带 <code>!</code> 表示没登记。想让三端一致，就把三个 ID 都加进来、配置写成一样。
        </p>
        <a class="btn" href="#logs" onclick="go('logs')">去请求日志里找 ID</a>
      </div>
    </section>

    <!-- ============================ 客户端出口 ============================ -->
    <section class="panel" id="panel-client">
      <div class="card">
        <h2>Widget 精简输出</h2>
        <p class="hint">Widget 一屏只有 3 行，给多了也翻不到，白占响应体积。</p>
        <div class="field" style="margin-bottom:16px">
          <label class="row" style="gap:8px">
            <input type="checkbox" data-key="TTCOIN_WIDGET_LIMIT" id="in-wlimit" <?php echo !empty($settings['TTCOIN_WIDGET_LIMIT']) ? 'checked' : ''; ?>>
            <span>开启（仅 <code>type=widget</code> 裁剪）</span>
          </label>
        </div>
        <div class="field" style="max-width:240px">
          <label>返回条数</label>
          <input type="number" min="1" max="50" data-key="TTCOIN_WIDGET_TOP_N" id="in-wtopn" value="<?php echo (int) $settings['TTCOIN_WIDGET_TOP_N']; ?>">
        </div>
      </div>

      <div class="card">
        <h2>涨幅口径</h2>
        <p class="hint">决定 <code>change</code> 字段怎么算。</p>
        <div class="field" style="margin-bottom:16px">
          <label>口径</label>
          <div class="seg">
            <label><input type="radio" name="TTCOIN_CHANGE_MODE" value="24h" <?php echo $settings['TTCOIN_CHANGE_MODE'] === '24h' ? 'checked' : ''; ?>><span>24h 滚动</span></label>
            <label><input type="radio" name="TTCOIN_CHANGE_MODE" value="tz" <?php echo $settings['TTCOIN_CHANGE_MODE'] === 'tz' ? 'checked' : ''; ?>><span>tz 自然日</span></label>
          </div>
          <div class="tip">24h：上游给的滚动 24 小时涨跌，零额外请求。tz：以指定时区当日 00:00 开盘价为基准，跨日归零（每币每天多抓一次 K 线）。</div>
        </div>
        <div class="field" style="max-width:240px">
          <label>时区偏移（tz 用）</label>
          <input type="text" data-key="TTCOIN_CHANGE_TZ" id="in-tz" value="<?php echo h($settings['TTCOIN_CHANGE_TZ']); ?>" placeholder="8">
          <div class="tip">东八区写 <code>8</code>；UTC 写 <code>0</code>；美东 <code>-5</code>；印度 <code>5:30</code>。</div>
        </div>
      </div>
    </section>

    <!-- ============================ 系统 ============================ -->
    <section class="panel" id="panel-system">
      <div class="card">
        <h2>缓存</h2>
        <p class="hint">手表 60 秒请求一次，缓存 10 秒足够吸收多设备并发。</p>
        <div class="grid">
          <div class="field">
            <label>有效期（秒）</label>
            <input type="number" min="1" max="3600" data-key="TTCOIN_CACHE_TTL" id="in-ttl" value="<?php echo (int) $settings['TTCOIN_CACHE_TTL']; ?>">
          </div>
          <div class="field">
            <label>旧缓存可用窗口（秒）</label>
            <input type="number" min="0" max="86400" data-key="TTCOIN_STALE_SERVE" id="in-stale" value="<?php echo (int) $settings['TTCOIN_STALE_SERVE']; ?>">
            <div class="tip">超时窗口内先回旧值、后台刷新，手表不会卡在等上游。</div>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>数据与时间</h2>
        <div class="grid">
          <div class="field">
            <label>数据目录（绝对路径）</label>
            <input type="text" data-key="TTCOIN_DATA_DIR" id="in-datadir" value="<?php echo h(isset($settings['TTCOIN_DATA_DIR']) ? $settings['TTCOIN_DATA_DIR'] : ''); ?>" placeholder="留空 = 站点目录上一级">
            <div class="tip">缓存与日志落在这里。留空走默认（当前：<code><?php echo h($dataDir); ?></code>）。</div>
          </div>
          <div class="field">
            <label>日志时区</label>
            <input type="text" data-key="TTCOIN_TIMEZONE" id="in-timezone" value="<?php echo h(isset($settings['TTCOIN_TIMEZONE']) ? $settings['TTCOIN_TIMEZONE'] : 'Asia/Shanghai'); ?>" list="tzlist">
            <datalist id="tzlist">
              <option value="Asia/Shanghai"></option>
              <option value="UTC"></option>
              <option value="Asia/Tokyo"></option>
              <option value="Europe/London"></option>
              <option value="America/New_York"></option>
            </datalist>
            <div class="tip">日志时间戳用。Docker 里 PHP 默认 UTC，不设会慢 8 小时。</div>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>上游连通性</h2>
        <p class="hint">报 <code>HTTP 0 Connection timed out</code> 时才需要动这里，正常留空。</p>
        <div class="grid">
          <div class="field">
            <label>HTTP 代理</label>
            <input type="text" data-key="TTCOIN_PROXY" id="in-proxy" value="<?php echo h(isset($settings['TTCOIN_PROXY']) ? $settings['TTCOIN_PROXY'] : ''); ?>" placeholder="http://代理地址:7890">
          </div>
          <div class="field">
            <label>DNS 钉 IP</label>
            <input type="text" data-key="TTCOIN_RESOLVE" id="in-resolve" value="<?php echo h(isset($settings['TTCOIN_RESOLVE']) ? $settings['TTCOIN_RESOLVE'] : ''); ?>" placeholder="data-api.binance.vision:443:1.2.3.4">
            <div class="tip">格式 <code>host:port:ip</code>，多项用分号隔开。</div>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>诊断页密钥</h2>
        <p class="hint"><code>diag.php</code> 默认只允许命令行；填了这个才允许浏览器访问 <code>diag.php?k=密钥</code>。</p>
        <div class="field" style="max-width:420px">
          <label>密钥（≥ 16 位，留空 = 关闭）</label>
          <input type="text" data-key="TTCOIN_DIAG_TOKEN" id="in-diag" value="<?php echo h(isset($settings['TTCOIN_DIAG_TOKEN']) ? $settings['TTCOIN_DIAG_TOKEN'] : ''); ?>">
          <div class="row" style="margin-top:6px">
            <button class="btn tiny" onclick="genToken()">生成随机密钥</button>
          </div>
        </div>
      </div>
    </section>

    <!-- ============================ 日志 ============================ -->
    <section class="panel" id="panel-logs">
      <div class="card">
        <h2>请求日志</h2>
        <p class="hint">
          <code><?php echo h($logFile); ?></code>（尾部 <span id="logCount">300</span> 行，最新的在上）。
          列顺序：时间 / 类型 / ID / UID / IP / 事件 / 详情。
        </p>
        <div class="row" style="margin-bottom:12px">
          <button class="btn" onclick="loadLog()">刷新</button>
          <select id="logFilter" onchange="renderLog()" style="max-width:170px">
            <option value="">全部类型</option>
            <option value="face">face（表盘）</option>
            <option value="app">app（APP）</option>
            <option value="widget">widget</option>
          </select>
          <span id="logStats" class="mono" style="font-size:12px;color:var(--muted)"></span>
        </div>
        <div class="scrollbox">
          <table id="logTable">
            <thead>
              <tr><th>时间</th><th>类型</th><th>ID</th><th>事件</th><th>详情</th></tr>
            </thead>
            <tbody><tr><td colspan="5" style="color:var(--muted)">点「刷新」加载</td></tr></tbody>
          </table>
        </div>
      </div>
    </section>
  </main>
</div>

<div class="savebar">
  <div class="state" id="state">没有改动</div>
  <div class="row">
    <button class="btn" onclick="openPreview('')">预览输出</button>
    <button class="btn primary" id="btnSave" onclick="saveAll()">保存并生效</button>
  </div>
</div>

<div class="modal" id="modal">
  <div class="box">
    <h2 id="mTitle">配置预览</h2>
    <p class="sub" id="mSub"></p>
    <div id="mBody"></div>
    <div class="row" style="margin-top:18px;justify-content:flex-end">
      <button class="btn" onclick="closeModal()">关闭</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const POOL   = <?php echo json_encode($pool, JSON_UNESCAPED_UNICODE); ?>;
const CSRF   = <?php echo json_encode($csrf); ?>;
const INIT   = <?php echo json_encode([
    'order' => $orderList,
    'coin'  => $coinList,
    'ids'   => (object) $idsMap,
    'codes' => (object) admin_load_keys(),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

/* ------------------------------------------------------------------ 工具 */
function $(sel, root){ return (root || document).querySelector(sel); }
function $$(sel, root){ return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

function toast(msg, kind){
  const box = document.getElementById('toast');
  const el = document.createElement('div');
  el.className = kind || '';
  el.textContent = msg;
  box.appendChild(el);
  setTimeout(function(){ el.remove(); }, 3600);
}

function markDirty(){
  const s = document.getElementById('state');
  s.textContent = '有未保存的改动';
  s.style.color = '#9a6700';
}

async function post(action, payload){
  const res = await fetch(location.pathname + '?action=' + action, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(Object.assign({_csrf: CSRF}, payload || {}))
  });
  return res.json();
}

/* ------------------------------------------------------------------ 导航 */
function go(name){
  $$('#nav a').forEach(function(a){ a.classList.toggle('on', a.dataset.panel === name); });
  $$('.panel').forEach(function(p){ p.classList.toggle('on', p.id === 'panel-' + name); });
  if(name === 'logs'){ loadLog(); }
  if(location.hash !== '#' + name){ history.replaceState(null, '', '#' + name); }
}
$$('#nav a').forEach(function(a){
  a.addEventListener('click', function(e){ e.preventDefault(); go(a.dataset.panel); });
});
if(location.hash){ go(location.hash.slice(1)); }

/* ------------------------------------------------------------------ 币种 chips */
function ChipSet(root, initial){
  const self = this;
  this.root = root;
  this.sel = initial.slice();
  this.hidden = root.parentNode.querySelector('input[data-key]');

  this.draw = function(){
    const selBox = $('[data-role=sel]', root);
    const poolBox = $('[data-role=pool]', root);
    if(!selBox){
      // 首次绘制时补上结构
      root.innerHTML = '<div class="lbl">已选（按顺序）</div><div class="sel" data-role="sel"></div>' +
                       '<div class="lbl" style="margin-top:9px">可选</div><div class="pool" data-role="pool"></div>';
      return self.draw();
    }
    selBox.innerHTML = '';
    poolBox.innerHTML = '';

    if(!self.sel.length){
      const empty = document.createElement('span');
      empty.style.cssText = 'color:#9aa4b2;font-size:12px';
      empty.textContent = '（空）';
      selBox.appendChild(empty);
    }

    self.sel.forEach(function(coin, i){
      const el = document.createElement('span');
      el.className = 'chip sel';
      el.innerHTML = '<span>' + coin + '</span>';
      const up = document.createElement('button');
      up.type = 'button'; up.textContent = '↑'; up.title = '上移';
      up.onclick = function(){ if(i > 0){ const t = self.sel[i-1]; self.sel[i-1] = self.sel[i]; self.sel[i] = t; self.draw(); } };
      const down = document.createElement('button');
      down.type = 'button'; down.textContent = '↓'; down.title = '下移';
      down.onclick = function(){ if(i < self.sel.length - 1){ const t = self.sel[i+1]; self.sel[i+1] = self.sel[i]; self.sel[i] = t; self.draw(); } };
      const del = document.createElement('button');
      del.type = 'button'; del.textContent = '×'; del.title = '移除';
      del.onclick = function(){ self.sel.splice(i,1); self.draw(); };
      el.appendChild(up); el.appendChild(down); el.appendChild(del);
      selBox.appendChild(el);
    });

    POOL.forEach(function(coin){
      if(self.sel.indexOf(coin) !== -1){ return; }
      const el = document.createElement('span');
      el.className = 'chip pool-item';
      el.textContent = '+ ' + coin;
      el.onclick = function(){ self.sel.push(coin); self.draw(); };
      poolBox.appendChild(el);
    });

    if(self.hidden){ self.hidden.value = self.sel.join(','); }
    markDirty();
  };

  this.draw();
  // 初始绘制不算改动
  const s = document.getElementById('state');
  s.textContent = '没有改动'; s.style.color = '';
}

const chips = {};
chips.order = new ChipSet($('[data-chips=order]'), INIT.order);
chips.coin  = new ChipSet($('[data-chips=coin]'),  INIT.coin);

/* ------------------------------------------------------------------ ID 表格 */
const tbody = $('#idsTable tbody');

function coinOptions(selected){
  let html = '<option value="">跟随默认</option>';
  POOL.forEach(function(c){
    html += '<option value="' + c + '"' + (c === selected ? ' selected' : '') + '>' + c + '</option>';
  });
  return html;
}

function addIdRow(row){
  row = row || {id:'', mode:'', fixed:'', coin:'', topN:'', order:'', change:'', tz:''};
  const tr = document.createElement('tr');
  tr.innerHTML =
    '<td data-label="ID"><input type="text" data-f="id" class="mono" maxlength="32" placeholder="TC…" value="' + (row.id || '') + '"></td>' +
    '<td data-label="模式"><select data-f="mode">' +
      '<option value=""' + (!row.mode ? ' selected' : '') + '>跟随默认</option>' +
      '<option value="scroll"' + (row.mode === 'scroll' ? ' selected' : '') + '>scroll</option>' +
      '<option value="fixed"' + (row.mode === 'fixed' ? ' selected' : '') + '>fixed</option>' +
    '</select></td>' +
    '<td data-label="固定币"><select data-f="fixed">' + coinOptions(row.fixed || '') + '</select></td>' +
    '<td data-label="主币种"><input type="text" data-f="coin" class="mono" placeholder="BTC,ETH" value="' + (row.coin || '') + '"></td>' +
    '<td data-label="条数"><input type="number" data-f="topN" min="0" max="50" placeholder="—" value="' + (row.topN === 0 || row.topN ? row.topN : '') + '"></td>' +
    '<td data-label="顺序"><input type="text" data-f="order" class="mono" placeholder="默认顺序" value="' + (row.order || '') + '"></td>' +
    '<td data-label="涨幅"><select data-f="change">' +
      '<option value=""' + (!row.change ? ' selected' : '') + '>跟随默认</option>' +
      '<option value="24h"' + (row.change === '24h' ? ' selected' : '') + '>24h</option>' +
      '<option value="tz"' + (row.change === 'tz' ? ' selected' : '') + '>自然日</option>' +
    '</select></td>' +
    '<td data-label="时区"><input type="text" data-f="tz" class="mono" placeholder="继承" title="自然日时区偏移：8 / -5 / 5:30，留空 = 跟随全局" value="' + (row.tz || '') + '"></td>' +
    '<td data-label="操作"><div style="display:flex;gap:4px;flex-wrap:nowrap">' +
      '<button type="button" class="btn tiny" data-act="preview" title="预览这套配置">预览</button>' +
      '<button type="button" class="btn tiny" data-act="del" title="删除">×</button>' +
    '</div></td>';

  $('[data-act=preview]', tr).onclick = function(){
    const id = $('[data-f=id]', tr).value.trim();
    openPreview(id, collectIds()[id] || {});
  };
  $('[data-act=del]', tr).onclick = function(){ tr.remove(); markDirty(); };

  $$('input,select', tr).forEach(function(el){
    el.addEventListener('input', markDirty);
    el.addEventListener('change', markDirty);
  });

  tbody.appendChild(tr);
}

Object.keys(INIT.ids || {}).forEach(function(id){
  const o = INIT.ids[id] || {};
  addIdRow({
    id: id,
    mode: o.mode || '',
    fixed: o.fixed || '',
    coin: o.coin || '',
    topN: (o.topN === 0 || o.topN) ? o.topN : '',
    order: o.order || '',
    change: o.change || '',
    tz: o.tz || ''
  });
});

if(!tbody.children.length){
  const tr = document.createElement('tr');
  tr.innerHTML = '<td colspan="9" style="color:var(--muted);font-size:12.5px">还没有按 ID 的配置。不登记也能用，全部走上面的默认一套。</td>';
  tbody.appendChild(tr);
}

renderCodes();

/* ------------------------------------------------------------------ 收集 */
function collectIds(){
  const out = {};
  $$('#idsTable tbody tr').forEach(function(tr){
    const idEl = $('[data-f=id]', tr);
    if(!idEl){ return; }
    const id = idEl.value.trim().replace(/[^A-Za-z0-9]/g, '');
    if(!id){ return; }
    const o = {};
    const mode = $('[data-f=mode]', tr).value;
    const fixed = $('[data-f=fixed]', tr).value;
    const coin = $('[data-f=coin]', tr).value.trim();
    const topN = $('[data-f=topN]', tr).value.trim();
    const order = $('[data-f=order]', tr).value.trim();
    if(mode) o.mode = mode;
    if(fixed) o.fixed = fixed;
    if(coin) o.coin = coin;
    if(topN !== '') o.topN = parseInt(topN, 10);
    if(order) o.order = order;
    const chg = $('[data-f=change]', tr) ? $('[data-f=change]', tr).value : '';
    if(chg) o.change = chg;
    const tzEl = $('[data-f=tz]', tr);
    const tz = tzEl ? tzEl.value.trim() : '';
    if(tz) o.tz = tz;
    out[id] = o;
  });
  return out;
}

function collect(){
  const s = {};
  $$('[data-key]').forEach(function(el){
    const key = el.dataset.key;
    if(el.type === 'checkbox'){ s[key] = el.checked; return; }
    if(el.type === 'radio'){
      if(el.checked){ s[key] = el.value; }
      return;
    }
    if(el.type === 'number'){ s[key] = el.value === '' ? 0 : parseInt(el.value, 10); return; }
    s[key] = el.value;
  });
  // 单选组没选中任何一项时兜底
  ['TTCOIN_FACE_MODE','TTCOIN_CHANGE_MODE'].forEach(function(k){
    if(!(k in s)){
      const on = document.querySelector('input[name="' + k + '"]:checked');
      s[k] = on ? on.value : '';
    }
  });
  s.TTCOIN_FACE_IDS = collectIds();
  return s;
}

$$('input,select').forEach(function(el){
  el.addEventListener('change', markDirty);
});

/* ------------------------------------------------------------------ 保存 */
async function saveAll(){
  const btn = document.getElementById('btnSave');
  btn.disabled = true;
  btn.textContent = '保存中…';
  try{
    const r = await post('save', {settings: collect()});
    if(r.ok){
      toast(r.message, 'ok');
      const s = document.getElementById('state');
      s.textContent = '已保存 ' + r.time; s.style.color = '#0b5f59';

      const warns = r.warnings || [];
      warns.forEach(function(w){ toast('提醒：' + w); });

      // 有提醒就多留一会儿，免得刷掉刚弹出的提示
      setTimeout(function(){ location.reload(); }, warns.length ? 4600 : 700);
    }else{
      toast(r.message || '保存失败', 'err');
      if(r.errors && r.errors.length){
        r.errors.slice(0, 6).forEach(function(e){ toast('· ' + e, 'err'); });
      }
    }
  }catch(e){
    toast('请求失败：' + e.message, 'err');
  }
  btn.disabled = false;
  btn.textContent = '保存并生效';
}

/* ------------------------------------------------------------------ 预览 */
async function openPreview(id, rowOverride){
  const settings = collect();
  if(rowOverride){
    const ids = Object.assign({}, settings.TTCOIN_FACE_IDS);
    ids[id] = rowOverride;
    settings.TTCOIN_FACE_IDS = ids;
  }
  $('#modal').classList.add('on');
  $('#mTitle').textContent = id ? '预览 ID：' + id : '预览默认一套';
  $('#mSub').textContent = '用当前表单里的值（未保存也算）跑一遍服务器端解析';
  $('#mBody').innerHTML = '<p style="color:#6b7280;font-size:13px">计算中…</p>';

  try{
    const r = await post('preview', {settings: settings, id: id || ''});
    if(!r.ok){ $('#mBody').innerHTML = '<p style="color:#b3261e">' + (r.message || '失败') + '</p>'; return; }

    const cfg = r.config;
    const known = r.known === null ? '默认一套（没带 ID）' : (r.known ? '已登记' : '未登记 → 实际走默认');

    let html = '';
    html += '<table style="margin-bottom:14px"><tbody>';
    html += '<tr><th style="width:110px">生效模式</th><td class="mono">' + cfg.mode + (cfg.mode === 'fixed' ? '（只回一条）' : '') + '</td></tr>';
    html += '<tr><th>固定币</th><td class="mono">' + (cfg.fixed || '—') + '</td></tr>';
    html += '<tr><th>条数上限</th><td class="mono">' + (cfg.topN === 0 ? '不限（按顺序全给）' : cfg.topN) + '</td></tr>';
    html += '<tr><th>主币种</th><td class="mono">' + (cfg.coin || '—（不重排）') + '</td></tr>';
    html += '<tr><th>最终顺序</th><td class="mono" style="word-break:break-all">' + cfg.order + '</td></tr>';
    html += '<tr><th>ID 状态</th><td>' + known + '</td></tr>';
    html += '</tbody></table>';

    html += '<p style="font-size:12.5px;color:#6b7280;margin:0 0 6px">表盘会收到 <b>' + r.count + '</b> 条：</p>';
    html += '<div class="row" style="margin-bottom:16px">';
    if(r.count === 0){
      html += '<span style="color:#b3261e;font-size:13px">空数组 —— 表盘会显示无数据，检查顺序里是否都是候选池里的币</span>';
    }else{
      r.coins.forEach(function(c){ html += '<span class="chip sel">' + c + '</span>'; });
    }
    html += '</div>';

    html += '<p style="font-size:12.5px;color:#6b7280;margin:0 0 6px">等价命令（在服务器上跑，看真实响应）：</p>';
    html += '<pre class="mono" style="background:#f1f3f5;padding:9px 11px;border-radius:7px;overflow:auto;font-size:11.5px;margin:0">' +
            r.curl.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</pre>';

    $('#mBody').innerHTML = html;
  }catch(e){
    $('#mBody').innerHTML = '<p style="color:#b3261e">请求失败：' + e.message + '</p>';
  }
}

function closeModal(){ $('#modal').classList.remove('on'); }
$('#modal').addEventListener('click', function(e){ if(e.target === $('#modal')) closeModal(); });
document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeModal(); });

/* ------------------------------------------------------------------ 日志 */
let LOG_ROWS = [];

async function loadLog(){
  const tb = $('#logTable tbody');
  tb.innerHTML = '<tr><td colspan="5" style="color:var(--muted)">加载中…</td></tr>';
  try{
    const r = await post('log', {lines: 300});
    if(!r.ok){ tb.innerHTML = '<tr><td colspan="5" style="color:#b3261e">' + (r.message || '读取失败') + '</td></tr>'; return; }
    LOG_ROWS = r.rows || [];
    $('#logCount').textContent = LOG_ROWS.length;
    renderLog();
  }catch(e){
    tb.innerHTML = '<tr><td colspan="5" style="color:#b3261e">请求失败：' + e.message + '</td></tr>';
  }
}

function renderLog(){
  const tb = $('#logTable tbody');
  const filter = $('#logFilter').value;
  const rows = LOG_ROWS.filter(function(r){ return !filter || r.type === filter; });

  if(!rows.length){
    tb.innerHTML = '<tr><td colspan="5" style="color:var(--muted)">' +
      (LOG_ROWS.length ? '这个类型下没有记录' : '日志还是空的 —— 手表还没请求过，或日志目录不可写') + '</td></tr>';
    $('#logStats').textContent = '';
    return;
  }

  const ev = {};
  rows.forEach(function(r){ ev[r.event] = (ev[r.event] || 0) + 1; });
  $('#logStats').textContent = Object.keys(ev).map(function(k){ return k + ':' + ev[k]; }).join('  ') + '  共 ' + rows.length + ' 条';

  let html = '';
  rows.forEach(function(r){
    const known = r.id && r.id.slice(-1) === '!';
    const idHtml = r.id === '-'
      ? '<span style="color:#9aa4b2">-</span>'
      : '<span class="mono" style="color:' + (known ? '#b3261e' : '#0b5f59') + '">' + r.id + '</span>';
    const evClass = r.event === 'error' ? 'bad' : (r.event === 'stale' ? '' : 'ok');
    html += '<tr>' +
      '<td data-label="时间" class="mono" style="font-size:11.5px;white-space:nowrap">' + r.time + '</td>' +
      '<td data-label="类型"><span class="badge">' + (r.type || '-') + '</span></td>' +
      '<td data-label="ID">' + idHtml + '</td>' +
      '<td data-label="事件"><span class="badge ' + evClass + '">' + r.event + '</span></td>' +
      '<td data-label="详情" class="mono" style="font-size:11.5px;color:#6b7280;word-break:break-all">' + (r.detail || '') + '</td>' +
      '</tr>';
  });
  tb.innerHTML = html;
}

/* ------------------------------------------------------------------ 设备管理码 */
function renderCodes(){
  const tb = $('#codesTable tbody');
  const codes = INIT.codes || {};
  const keys = Object.keys(codes).sort();

  if(!keys.length){
    tb.innerHTML = '<tr><td colspan="4" style="color:var(--muted);font-size:12.5px">还没有生成过管理码。从下拉里选一个已登记的 ID，点「生成管理码」。</td></tr>';
    fillCodesPick();
    return;
  }

  let html = '';
  keys.forEach(function(id){
    const c = codes[id] || {};
    html += '<tr>' +
      '<td data-label="ID" class="mono" style="word-break:break-all">' + id + '</td>' +
      '<td data-label="管理码" class="mono"><b>' + (c.code || '') + '</b></td>' +
      '<td data-label="生成时间" class="mono" style="font-size:11.5px;color:#6b7280">' + (c.time ? new Date(c.time * 1000).toLocaleString('zh-CN', {hour12:false}) : '—') + '</td>' +
      '<td data-label="操作"><div style="display:flex;gap:4px;flex-wrap:nowrap">' +
        '<button type="button" class="btn tiny" data-act="copy">复制</button>' +
        '<button type="button" class="btn tiny" data-act="regen">重新生成</button>' +
        '<button type="button" class="btn tiny" data-act="del">×</button>' +
      '</div></td>' +
      '</tr>';
  });
  tb.innerHTML = html;

  $$('button[data-act]', tb).forEach(function(btn){
    const id = btn.closest('tr').querySelector('td').textContent.trim();
    btn.onclick = function(){
      if(btn.dataset.act === 'copy'){ copyText((INIT.codes[id] || {}).code || ''); }
      else if(btn.dataset.act === 'regen'){ codeSet(id, 'auto'); }
      else if(btn.dataset.act === 'del'){ codeDel(id); }
    };
  });

  fillCodesPick();
}

function fillCodesPick(){
  const sel = document.getElementById('codesPickId');
  if(!sel){ return; }
  const cur = sel.value;
  // 已登记的 ID（INIT.ids）∪ 已有码的 ID
  const names = {};
  Object.keys(INIT.ids || {}).forEach(function(id){ names[id] = 1; });
  Object.keys(INIT.codes || {}).forEach(function(id){ names[id] = 1; });
  let html = '<option value="">— 选一个设备 ID —</option>';
  Object.keys(names).sort().forEach(function(id){
    html += '<option value="' + id + '">' + id + ((INIT.codes || {})[id] ? '（已有码）' : '') + '</option>';
  });
  sel.innerHTML = html;
  if(cur && names[cur]){ sel.value = cur; }
}

function copyText(text){
  if(navigator.clipboard && navigator.clipboard.writeText){
    navigator.clipboard.writeText(text).then(function(){ toast('已复制'); }, function(){ toast('复制失败，请手动选择'); });
  }else{
    toast('浏览器不支持一键复制，请手动选择复制');
  }
}

async function codeSet(id, code){
  const r = await post('code_set', {id:id, code:code});
  if(r.ok){
    INIT.codes[id] = {code: r.code, time: Math.floor(Date.now() / 1000)};
    toast('管理码已生成：' + r.code + '（发给对方时连同 ID 一起发）', 'ok');
    renderCodes();
  }else{
    toast(r.message || '生成失败', 'err');
  }
}

async function codeDel(id){
  if(!confirm('删除 ' + id + ' 的管理码？对方将无法再登录。')){ return; }
  const r = await post('code_set', {id:id, code:''});
  if(r.ok){
    delete INIT.codes[id];
    toast('已删除', 'ok');
    renderCodes();
  }else{
    toast(r.message || '删除失败', 'err');
  }
}

async function genCode(){
  const sel = document.getElementById('codesPickId');
  const id = (sel.value || '').replace(/[^A-Za-z0-9]/g, '');
  if(!id){ toast('先从下拉里选一个设备 ID（来自「按 ID 配置」里登记保存的 ID）'); return; }
  codeSet(id, 'auto');
}

/* ------------------------------------------------------------------ 杂项 */
function genToken(){
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  const hex = Array.prototype.map.call(bytes, function(b){ return ('0' + b.toString(16)).slice(-2); }).join('');
  document.getElementById('in-diag').value = hex;
  markDirty();
}

window.addEventListener('beforeunload', function(e){
  const s = document.getElementById('state');
  if(s.textContent.indexOf('未保存') !== -1){
    e.preventDefault();
    e.returnValue = '';
  }
});
</script>
</body>
</html>
