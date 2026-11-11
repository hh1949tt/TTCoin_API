<?php
/**
 * 手表接口：GET /coin.php
 *
 * ?type=face 走表盘（TTCoin_Face）专属出口：按 FACE_ORDER 重排，
 * fixed 模式只回一条（表盘停止轮播），配置见 config.php / lib.php。
 *
 * 命中新鲜缓存 -> 直接返回；
 * 缓存过期但还新（<= TTCOIN_STALE_SERVE）-> 先返回旧值，响应结束后后台刷新；
 * 其余情况（无缓存 / 太旧 / 非 PHP-FPM）-> 阻塞拉一次 Binance；
 * 拉取失败但有旧缓存 -> 返回旧值；两者都没有 -> 502。
 * 抓取 / 校验 / 缓存逻辑见 lib.php。
 */

// 可选：站点目录放 config.php 可覆盖数据目录等配置（如 TTCOIN_DATA_DIR），
// 不建这个文件就是默认行为。放在 require lib.php 之前，常量才生效。
if (is_file(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
}

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
// 手表按 60 秒节奏自己请求，中间层再缓存只会让"退出再进"看到旧价
header('Cache-Control: no-store');

// 回显客户端设备标识（可选参数 ?uid=），端到端验证用；
// 不参与缓存键（缓存是所有设备共用的一份），只写进请求日志第 4 列，
// 将来做按设备统计 / 限流时从 ttcoin_client_uid() 取值即可
$uid = ttcoin_client_uid();

if ($uid !== '') {
    header('X-TTCoin-Uid: ' . $uid);
}

// 客户端形态（可选参数 ?type=app|widget|face），进日志，并决定出口怎么裁剪 / 重排
$type = ttcoin_client_type();

// 出口备注（只影响出口，缓存始终完整，见 lib.php）
$trimNote = (TTCOIN_WIDGET_LIMIT && $type === 'widget') ? 'top' . TTCOIN_WIDGET_TOP_N : '';

// face 是表盘版（TTCoin_Face）专属：滚屏 / 固定 + 独立币种顺序由服务端配置决定。
// 表盘请求可带 ?id=（手机端设置里的「行情配置 ID」）：TTCOIN_FACE_IDS 里登记了
// 就按登记的覆盖，没登记 / 没带 id 走默认一套。本次生效的配置回显到响应头，
// 本地 curl 一下就能核对，不用上表、不用翻日志；同时 ?id= 也会写进请求日志
// 第 3 列（没登记的 id 末尾带 `!`），事后想查「哪块表拿的哪套配置」直接 grep。
// 表盘端目前不读这些头（它按数组条数自动滚屏或固定），这里纯粹是排障 / 测试用。
if ($type === 'face') {
    $faceId     = ttcoin_face_client_id();
    $faceConfig = ttcoin_face_config();

    if ($faceId !== '') {
        header('X-TTCoin-Face-Id: ' . $faceId);
    }

    header('X-TTCoin-Face-Mode: ' . $faceConfig['mode']);
    header('X-TTCoin-Face-Order: ' . implode(',', $faceConfig['order']));

    if ($faceConfig['mode'] === 'fixed') {
        header('X-TTCoin-Face-Fixed: ' . $faceConfig['fixed']);
    }

    if ($faceConfig['topN'] > 0) {
        header('X-TTCoin-Face-Top-N: ' . $faceConfig['topN']);
    }

    $trimNote = ttcoin_face_note($type);
}

// 主币种（coin，三种出口都生效，见 lib.php）配了就回显：
// app / widget / face 带同一个 id 时，从这个头一眼确认三端拿到的是同一套
// 主币种优先级（多币种时回显整个列表，如 "BTC,ETH"）。
$coinNote = ttcoin_face_config()['coin'];

if ($coinNote !== '') {
    header('X-TTCoin-Coin: ' . $coinNote);
}

// 涨幅口径回显：24h 是上游滚动 24 小时；tz 是以指定时区当日 00:00 开盘价
// 为基准的自然日涨幅。face 出口按 id 单独设置优先，没设跟随全局；curl -D -
// 一眼能看出本次（以及缓存里那份）用的哪套。
$changeCfg = ttcoin_change_config();
// face 出口：口径和时区都可能被该 id 单独覆盖（见 ttcoin_face_change）
$effChg = ($type === 'face' && isset($faceConfig))
    ? ttcoin_face_change($faceConfig)
    : ['mode' => $changeCfg['mode'], 'tz' => $changeCfg['tz']['param']];

header('X-TTCoin-Change-Mode: ' . $effChg['mode']);

if ($effChg['mode'] === 'tz') {
    $effTz = ttcoin_tz_parse($effChg['tz']);

    if ($effTz === null) {
        $effTz = $changeCfg['tz'];
    }

    header('X-TTCoin-Change-Tz: ' . $effTz['label']);
    header('X-TTCoin-Change-Day: ' . gmdate('Y-m-d', time() + $effTz['offset']));
}

// 1) 新鲜缓存
$cached = ttcoin_cache_read(TTCOIN_CACHE_TTL);

if ($cached !== null) {
    ttcoin_log_request($type, $uid, 'hit', $trimNote);
    echo ttcoin_filter_output($cached, $type);
    exit;
}

// 2) 过期但还在可用窗口内：先回旧值，响应发完再在后台刷新。
//
// 为什么要这么绕：上游（Binance）从服务器出发经常要 5~30 秒，
// 阻塞式抓取会让手表一直等，超过它的请求超时就表现为"无网络"。
// fastcgi_finish_request() 把已经生成的响应立刻推给客户端，
// 之后的抓取发生在同一个进程里，但客户端早就拿到数据了。
// 非 PHP-FPM 环境（Apache mod_php 等）没有这个函数，自动退回下面的阻塞式流程。
$stale = ttcoin_cache_read();

if ($stale !== null && function_exists('fastcgi_finish_request')) {

    $age = ttcoin_cache_age();

    if ($age <= TTCOIN_STALE_SERVE) {

        ttcoin_log_request($type, $uid, 'async', 'age=' . $age);

        echo ttcoin_filter_output($stale, $type);

        fastcgi_finish_request();          // 响应已送达，后面的耗时手表感知不到

        ttcoin_refresh_background();

        exit;
    }
}

// 3) 拉取（没有缓存 / 缓存太旧 / 不是 PHP-FPM）
$res = ttcoin_fetch(10, 5);

// 4) 失败：有旧缓存就先顶着，实在没有才报错
if (!$res['ok']) {

    if ($stale !== null) {
        ttcoin_log_request($type, $uid, 'stale', $res['error']);
        echo ttcoin_filter_output($stale, $type);
        exit;
    }

    ttcoin_log_request($type, $uid, 'error', $res['error']);
    http_response_code(502);
    echo json_encode(['error' => $res['error']]);
    exit;
}

// 5) 成功：编码 -> 写缓存 -> 输出
$json = ttcoin_encode($res['data']);

if ($json === null) {
    ttcoin_log_request($type, $uid, 'error', 'json_encode failed');
    http_response_code(500);
    echo json_encode(['error' => 'json_encode failed']);
    exit;
}

ttcoin_cache_write($json);

ttcoin_log_request($type, $uid, 'fresh', $trimNote);

echo ttcoin_filter_output($json, $type);
