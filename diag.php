<?php
/**
 * 上游连通性诊断。**只允许命令行运行**，网页访问一律 404。
 *
 * 用途：判断"连不上 Binance"到底卡在哪一层——DNS 污染、TCP 不通、
 * TLS 握手失败，还是上游返回了非 200。
 *
 * 用法（在 PHP 容器里）：
 *   docker exec -it <容器名> php /path/to/ttcoin/diag.php
 * 或者直接在站点目录下：
 *   php diag.php
 *
 * 输出内容：
 *   1. 环境：PHP 版本、curl 是否存在、代理/域名固定配置、数据目录、缓存新鲜度
 *   2. 每个候选域名：DNS 解析到的 IP、TCP 连接耗时、TLS 耗时、首字节耗时、HTTP 状态、curl errno
 *   3. 结论提示
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib.php';

function diag_line($label, $value)
{
    printf("  %-22s %s\n", $label, $value);
}

echo "\n=== 1. 环境 ===\n";

diag_line('PHP', PHP_VERSION . ' (' . PHP_SAPI . ')');
diag_line('curl 扩展', function_exists('curl_init') ? '有' : '缺失（这就是原因）');
diag_line('TTCOIN_PROXY', (defined('TTCOIN_PROXY') && TTCOIN_PROXY !== '') ? TTCOIN_PROXY : '(空，直连)');
diag_line('TTCOIN_RESOLVE', (defined('TTCOIN_RESOLVE') && TTCOIN_RESOLVE !== '') ? TTCOIN_RESOLVE : '(空)');
diag_line('数据目录', ttcoin_data_dir());
diag_line('缓存文件', ttcoin_cache_file());

$age  = ttcoin_cache_age();
$file = ttcoin_cache_file();

if (is_file($file)) {
    diag_line('缓存年龄', $age === PHP_INT_MAX ? '取不到 mtime' : $age . ' 秒前');
    diag_line('缓存大小', filesize($file) . ' 字节');
} else {
    diag_line('缓存状态', '文件不存在（还没成功抓过一次）');
}

$upstreamFile = ttcoin_storage_path('cache', 'upstream.txt', 'coin_upstream.txt');
diag_line('上次成功域名', is_file($upstreamFile) ? trim(file_get_contents($upstreamFile)) : '(还没有记录)');

echo "\n=== 2. 逐个候选域名 ===\n";

$candidates = ttcoin_upstream_candidates();

foreach ($candidates as $base) {

    $host = ttcoin_host_of($base);
    echo "\n-- $host\n";

    // DNS：先看解析到哪个 IP。国内常见的是"能解析但解析到被污染的地址"，
    // 表现就是 TCP 连接一直等到超时。
    $ip = @gethostbyname($host);
    diag_line('DNS', ($ip === $host) ? '解析失败' : $ip);

    $ch = curl_init();

    $opts = [
        CURLOPT_URL            => $base . '?' . http_build_query([
            'symbols' => json_encode(['BTCUSDT']),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: ' . TTCOIN_USER_AGENT,
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

    $t0     = microtime(true);
    $body   = curl_exec($ch);
    $total  = microtime(true) - $t0;
    $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $dns    = curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME);
    $conn   = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
    $tls    = curl_getinfo($ch, CURLINFO_APPCONNECT_TIME);
    $ttfb   = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);

    curl_close($ch);

    diag_line('DNS 耗时', sprintf('%.3fs', $dns));
    diag_line('TCP 连接', sprintf('%.3fs', $conn));
    diag_line('TLS 握手', $tls > 0 ? sprintf('%.3fs', $tls) : '未完成');
    diag_line('首字节', $ttfb > 0 ? sprintf('%.3fs', $ttfb) : '未收到');
    diag_line('总耗时', sprintf('%.3fs', $total));
    diag_line('HTTP 状态', $code === 0 ? '0（没拿到响应）' : (string)$code);
    diag_line('curl errno', $errno === 0 ? '0（无错误）' : $errno . ' ' . $error);
    diag_line('响应大小', $body === false ? '-' : strlen($body) . ' 字节');

    if ($body !== false && $code === 200) {
        $json   = json_decode($body, true);
        $first  = (is_array($json) && isset($json[0]['symbol'])) ? $json[0]['symbol'] : '结构不符';
        diag_line('解析结果', '第 1 条 ' . $first);
    }
}

echo "\n=== 3. 怎么读这张表 ===\n";
echo "  DNS 解析失败           -> 容器 DNS 不通，改 /etc/resolv.conf 或换 DNS\n";
echo "  DNS 有 IP 但 TCP 超时  -> 多半是 DNS 污染（解析到假 IP），用 TTCOIN_RESOLVE 钉住真 IP\n";
echo "  TCP 通但 TLS 未完成    -> 中间设备干扰 TLS，只能走代理\n";
echo "  全部域名都超时         -> 出口被封，配 TTCOIN_PROXY 指向可用代理\n";
echo "  某个域名 200           -> 那个域名可用，代码会自动优先用它（记在 cache/upstream.txt）\n\n";
