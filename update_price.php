<?php
/**
 * cron 预热：提前把价格写进缓存，让手表请求几乎不触发上游调用。
 *
 * 用法：* * * * * php /path/to/api/update_price.php
 *
 * 与 coin.php 的差异只有输出形式：这里写一行日志、失败返回退出码 1，
 * 便于 cron 邮件 / 监控发现异常。
 */

// 与 coin.php 共用同一份可选配置（数据目录等），保证 cron 写的缓存
// 和被接口读的缓存是同一个文件
if (is_file(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
}

require __DIR__ . '/lib.php';

// 时区由 lib.php 统一设置（默认 Asia/Shanghai，可用 TTCOIN_TIMEZONE 覆盖）
$stamp = date('Y-m-d H:i:s');

// 预算 8 秒：单域名连不上时要留出换域名的余地。
// 原来 5 秒时，首个域名吃满 3 秒连接超时后只剩 2 秒，基本不可能等到第二个域名成功。
$res = ttcoin_fetch(8, 3);

if (!$res['ok']) {
    echo $stamp . ' ERROR ' . $res['error'] . PHP_EOL;
    exit(1);
}

$json = ttcoin_encode($res['data']);

if ($json === null) {
    echo $stamp . ' ERROR json_encode failed' . PHP_EOL;
    exit(1);
}

ttcoin_cache_write($json);

echo $stamp . ' OK ' . $json . PHP_EOL;
