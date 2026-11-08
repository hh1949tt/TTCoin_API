<?php
/**
 * 手表接口：GET /coin.php
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
// 服务端目前不存储、不参与缓存键，将来做按设备统计 / 限流时从这里取值
$uid = ttcoin_client_uid();

if ($uid !== '') {
    header('X-TTCoin-Uid: ' . $uid);
}

// 客户端形态（可选参数 ?type=app|widget），进日志，并在 Widget 精简模式下决定输出条数
$type = ttcoin_client_type();

// 精简模式是否对本次请求生效（只影响出口，缓存始终完整，见 lib.php）
$trimNote = (TTCOIN_WIDGET_LIMIT && $type === 'widget') ? 'top' . TTCOIN_WIDGET_TOP_N : '';

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
