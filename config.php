<?php
/**
 * 可选配置示例。想用就复制成 config.php（同目录），不想用就不用建 —— coin.php
 * 和 update_price.php 都会检测：存在 config.php 才 require，不存在走默认。
 *
 * 典型用途：把缓存 / 日志挪到你指定的目录，而不是默认的"站点目录上一级"。
 */

// 缓存与日志的父目录。脚本会在其下建 cache/ 与 logs/。
// 留空或不定义 = 站点目录的上一级（默认）。
//
// 举例：站点目录是 /var/www/www/ttcoin，
// 想让数据落在站点目录内，就写：
//   define('TTCOIN_DATA_DIR', __DIR__);
// 落在同级的另一个目录（docroot 之外）就写：
//   define('TTCOIN_DATA_DIR', dirname(__DIR__));
//
// define('TTCOIN_DATA_DIR', __DIR__);

// Widget 精简输出开关（默认开启）。
// 开：type=widget 的请求只返回前 TTCOIN_WIDGET_TOP_N 项行情；
// 关：返回全部，与 App 一致。
define('TTCOIN_WIDGET_LIMIT', true);

// 精简模式返回几项。默认 3，与 Widget 版一屏 3 行对齐。
// define('TTCOIN_WIDGET_TOP_N', 3);

// 日志时间戳的时区。默认 Asia/Shanghai（北京时间）；
// Docker 容器里 PHP 默认是 UTC，不设这里日志就会慢 8 小时。
// define('TTCOIN_TIMEZONE', 'Asia/Shanghai');

// ---------------------------------------------------------------------------
// 上游连通性（报 "Binance HTTP 0 Connection timed out" 时看这三项）
// ---------------------------------------------------------------------------
//
// 代码默认会依次尝试 data-api.binance.vision 和 api1~4/api.binance.com，
// 任一通了就记下来优先使用（记在 <数据目录>/cache/upstream.txt）。
// 正常不需要配置；如果全部域名都不通，按下面两种方式之一处理。
//
// 1) 服务器有可用代理（例如局域网里跑着 clash / v2ray）：
//      define('TTCOIN_PROXY', 'http://192.168.1.10:7890');
//
// 2) 域名解析被污染（能 ping 通假 IP 却连不上）时，把域名钉到已知可用的 IP：
//      先在能上网的机器上查：nslookup data-api.binance.vision 8.8.8.8
//      define('TTCOIN_RESOLVE', 'data-api.binance.vision:443:1.2.3.4');
//    多个用分号隔开。
//
// 想先看诊断结论，在容器里跑：php diag.php
// define('TTCOIN_PROXY', '');
// define('TTCOIN_RESOLVE', '');

