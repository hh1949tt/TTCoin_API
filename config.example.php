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
// 举例：站点目录是 /vol2/1000/Docker/web_out/www/ttcoin，
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

// ---------------------------------------------------------------------------
// 行情显示配置（face 滚屏 / 固定 + 三端共用的主币种）—— 仅本地测试，暂不发布
// ---------------------------------------------------------------------------
//
// TTCoin_WF 的后台请求已经带 type=face。服务端对这一种请求单独决定出口：
//
//   MODE = scroll  滚屏：按 ORDER 顺序回多条，表盘逐条轮播（每 ROTATE_SEC 翻一条）
//   MODE = fixed   固定：只回 FIXED 指定的那一个币，表盘轮播索引恒为 0
//
// 表盘端不读任何新字段：它本来就只认 [{symbol,base,price,change}, ...]，
// 多条就滚、一条就固定。所以这里改完，表盘端一行都不用动。
//
// ORDER 是**表盘专用**的币种顺序，跟 App / Widget 的候选池顺序互不影响；
// 写 base 简称（BTC / DOGE / SHIB），没写进来的币不会出现在
// 表盘响应里。缓存里始终存完整列表，改这些值不用清缓存、也不会触发重新抓上游。
//
// COIN 是**三端共用**的主币种（默认留空 = 默认币种，即候选池默认顺序）：
//   face   fixed 模式把它当 FIXED、scroll 模式把它排到 ORDER 最前；
//   app    返回列表里把它排到最前（其余顺序不动）；
//   widget 同上，排完再裁前 N 条。
// 想临时用环境变量覆盖（TTCOIN_FACE_MODE=fixed 之类），把下面对应的 define
// 注释掉即可；本文件里一旦 define，就以本文件为准。
define('TTCOIN_FACE_MODE',  'scroll');
define('TTCOIN_FACE_ORDER', 'DOGE,BTC,ETH,SOL,BNB,XRP,ADA,LINK,LTC,AVAX,SHIB');
define('TTCOIN_FACE_FIXED', 'DOGE');
define('TTCOIN_FACE_TOP_N', 0);      // 0 = 按 ORDER 全给；填 3 = 只滚前 3 个
// define('TTCOIN_FACE_COIN', '');   // 全局主币种；留空 = 默认币种（默认就注释着）
                                    // 支持逗号分隔的多币种优先级列表，如 'BTC,ETH'

// 按 ID 覆盖：三个客户端（表盘 / App / Widget）手机设置里都填「行情配置 ID」，
// 请求都会带 ?id=；这里 JSON 里登记了哪个 id，那个客户端就按登记的键覆盖
// 默认一套（mode / order / fixed / topN / coin 写哪个覆盖哪个，没写的继承默认；
// 同一个 id 三端看到同一套行情，币种也可以各 id 不一致）。
// 没带 id、或 id 没登记 = 全部走上面默认的一套（默认币种）。
// 下面是测试用示例：11111111 固定 DOGE，22222222 滚播，33333333 主币种换 BTC
// （coin 也支持多币种优先级列表，如 {"coin":"BTC,ETH"}，44444444 是示例）。
define('TTCOIN_FACE_IDS', '{"11111111":{"mode":"fixed","fixed":"DOGE"},"22222222":{"mode":"scroll"},"33333333":{"coin":"BTC"},"44444444":{"coin":"BTC,ETH"}}');

// ---------------------------------------------------------------------------
// 涨幅口径（change 字段）—— 24 小时 / 指定时区自然日
// ---------------------------------------------------------------------------
//
//   '24h'  默认。上游给的滚动 24 小时涨跌幅，零额外请求。
//   'tz'   自然日涨幅：以该时区当日 00:00 的开盘价为基准，
//          (现价 - 开盘价) / 开盘价 × 100，跨过当地 00:00 自动归零重算。
//          日开盘价按「时区 + 当地日期」缓存，一天最多抓一轮（11 个币并行），
//          抓不到的币自动退回 24h，接口不会因此失败。
//
// TTCOIN_CHANGE_TZ 是固定 UTC 偏移（不换算夏令时），东八区写 '8'：
//   8 / +8 / UTC+8 → 东八区    0 / Z → UTC    -5 → 美东标准时
//   5:30 / 5.5 → 印度        写错 → 回落到 8
//
// 验证：curl -sD - "https://<站点>/coin.php?type=app" | grep -i x-ttcoin-change
//       X-TTCoin-Change-Mode: tz / X-TTCoin-Change-Tz: UTC+8 / X-TTCoin-Change-Day: 2026-11-10
//
// 不定义这两项 = 完全保持原来的 24 小时行为。
// define('TTCOIN_CHANGE_MODE', 'tz');
// define('TTCOIN_CHANGE_TZ',   '8');

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
//      define('TTCOIN_PROXY', 'http://proxy.example:7890');
//
// 2) 域名解析被污染（能 ping 通假 IP 却连不上）时，把域名钉到已知可用的 IP：
//      先在能上网的机器上查：nslookup data-api.binance.vision 8.8.8.8
//      define('TTCOIN_RESOLVE', 'data-api.binance.vision:443:1.2.3.4');
//    多个用分号隔开。
//
// 想先看诊断结论，在容器里跑：php diag.php
// define('TTCOIN_PROXY', '');
// define('TTCOIN_RESOLVE', '');

// ---------------------------------------------------------------------------
// diag.php 的网页模式密钥（默认关闭）
// ---------------------------------------------------------------------------
//
// diag.php 默认只允许命令行运行，浏览器访问一律 404。服务器上没有 PHP 命令行、
// 又不想 ssh 时，可以填下面这一项，然后用浏览器看诊断结果：
//
//   define('TTCOIN_DIAG_TOKEN', '这里填你自己的一串随机字符');
//   访问 https://<站点>/diag.php?k=这里填你自己的一串随机字符
//
// 规则：
//   * 不填 = 网页模式关闭，行为与以前完全一样（404）；
//   * 少于 16 个字符视为没配，同样 404，避免设成 '123' 这种；
//   * 只要不符就返回 404（不是 403），外人无法确认这个文件是否存在；
//   * 密钥会出现在 nginx/Apache 的访问日志和浏览器历史里，用完可以改掉。
//
// 生成一串：openssl rand -hex 16   或   php -r "echo bin2hex(random_bytes(16));"
//
// define('TTCOIN_DIAG_TOKEN', '');

