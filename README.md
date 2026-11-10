# TTCoin API

> TTCoin 的服务端中转：把 Binance 的 24h ticker 裁剪成手表能直接显示的 JSON，带文件缓存、降级与按设备请求日志。

> **项目关系**：本目录原先是 `TTCoin_APP/api/`（三个形态共用同一套服务端代码）。
> 现在独立成 `TTCoin_API`，与 `TTCoin_APP`（Watch App 版）、`TTCoin_Widget`（Widget 版）平级——
> 手表端的接口约定、部署方式见本 README；表端编译与屏幕适配见各自项目的 README。

### 四个仓库

| 形态 | 仓库 |
| --- | --- |
| 表盘（Watch Face） | <https://github.com/hh1949tt/TTCoin_WF> |
| Watch App | <https://github.com/hh1949tt/TTCoin_APP> |
| Widget | <https://github.com/hh1949tt/TTCoin_Widget> |
| 服务端中转 | <https://github.com/hh1949tt/TTCoin_API> |

三个表端项目共用同一套接口约定（`[{symbol,base,price,change}]`），
服务端可任选 PHP 或 Cloudflare Worker 版。

## 文件

| 文件 | 职责 |
| --- | --- |
| `coin.php` | 手表调用的主接口，带缓存与降级，按设备记请求日志 |
| `update_price.php` | 可由 cron 预热的同一套逻辑（输出纯文本日志，失败退出码 1） |
| `lib.php` | 抓取 / 校验 / 缓存 / 请求日志 / 参数清洗（两个入口共用） |
| `diag.php` | 上游连通性诊断。命令行 `php diag.php` 始终可用；网页模式默认关闭，需在 `config.php` 设 `TTCOIN_DIAG_TOKEN` 后访问 `diag.php?k=<密钥>` |
| `.htaccess` | 兜底：禁止直接访问缓存 / 日志 / `lib.php` |
| `cache/.htaccess` | 仓库占位：缓存目录（服务器上运行时生成）禁止外部访问 |
| `logs/.htaccess` | 同上，针对请求日志目录 |
| `config.example.php` | **配置示例**：数据目录、时区、Widget 精简开关、表盘显示、涨幅口径。复制成 `config.php` 才生效；不建也能跑（全走 `lib.php` 里的默认值） |
| `worker/` | 可选：Cloudflare Workers 版实现（`worker.js` + `wrangler.toml`），输出契约与 `coin.php` 完全一致；上游在 Coinbase / Bybit 之间自动切换，`?diag=1` 可现场体检，见 [worker/README.md](worker/README.md) |

## 环境要求

- PHP 7.4+，启用 `curl` 与 `json` 扩展
- 任意支持 HTTPS 的 Web 服务器（Nginx / Apache / 宝塔 / 虚拟主机均可）
- **必须是 HTTPS**，Connect IQ 默认拒绝明文 HTTP 请求

## 后端部署

### 1. 上传文件

把本目录下的**四个文件**（`coin.php`、`update_price.php`、`lib.php`、`.htaccess`）上传到站点目录。`lib.php` 是前两个共用的依赖，缺了会直接报 `require` 失败。`config.php` 是**可选**配置：想改数据目录 / 时区 / 表盘显示时，把仓库里的 `config.example.php` 复制成 `config.php` 再改；不建就全走默认值（见下一节）。`config.php` 已在 `.gitignore` 里，不会被提交。

### 2. 数据目录：缓存与日志放哪

默认写在**站点目录的上一级**：`../cache/coin.json`、`../logs/coin.log`。这个默认值是为"站点目录 = docroot"设计的——上一级天然在 docroot 之外，外网拿不到。

想放别处就设 `TTCOIN_DATA_DIR`，两种写法任选：

```php
// 站点目录下把 config.example.php 复制成 config.php（推荐，不必改 coin.php / update_price.php）
<?php
define('TTCOIN_DATA_DIR', __DIR__);          // 数据落在站点目录内的 cache/ 与 logs/
// define('TTCOIN_DATA_DIR', '/vol2/1000/Docker/web_out/data');  // 或任意绝对路径
```

```yaml
# 或走环境变量（docker-compose / php-fpm env / nginx fastcgi_param 都认）
environment:
  TTCOIN_DATA_DIR: /vol2/1000/Docker/web_out/data
```

脚本在数据目录下再建 `cache/` 与 `logs/`，权限 0755、递归创建。三种结果：

| 情况 | 实际路径 |
| --- | --- |
| 未配置 `TTCOIN_DATA_DIR` | `<站点目录>/../cache`、`<站点目录>/../logs` |
| 配置为 `__DIR__`（站点目录） | `<站点目录>/cache`、`<站点目录>/logs` |
| 数据目录不可写、子目录也建不出来 | 退回 `coin_cache.json`、`coin.log`（与 `coin.php` 同级，最后的兜底） |

以站点目录 `/vol2/1000/Docker/web_out/www/ttcoin` 为例：

| 配置 | 缓存 | 日志 |
| --- | --- | --- |
| 默认 | `<数据目录>/cache/coin.json` | `<数据目录>/logs/coin.log` |
| `define('TTCOIN_DATA_DIR', __DIR__)` | `<站点目录>/cache/coin.json` | `<站点目录>/logs/coin.log` |

权限：目录属主要能写。容器里通常是 `www-data`（Alpine 镜像多为 `nginx` / `82`），必要时 `chown -R www-data:www-data <数据目录>`。

#### 数据会不会被公网访问？

只看一件事：**数据目录在不在 docroot 之内**。

| 数据目录位置 | 结论 |
| --- | --- |
| docroot 之外 | 安全，无需额外配置（默认配置通常就是这种） |
| docroot 之内（例如 docroot 就是 `www`，而数据在 `www/cache`、`www/ttcoin/cache`） | **可直接 GET**，必须挡 |

实测最准：

```bash
curl -sI https://your-domain.example/cache/coin.json | head -1   # 200 = 暴露了
curl -sI https://your-domain.example/ttcoin/logs/coin.log | head -1
```

**Nginx 不读 `.htaccess`**（Docker 部署基本都是 Nginx + php-fpm），此时 `cache/.htaccess`、`logs/.htaccess` 都不起作用，必须在站点配置里挡。路径按数据的 URL 前缀写：

```nginx
# docroot = www，数据在 www/ttcoin/cache、www/ttcoin/logs
location ^~ /ttcoin/cache/ { deny all; }
location ^~ /ttcoin/logs/  { deny all; }
```

要放在 `location ~ \.php$` **之前**，否则请求会先交给 PHP。最省事的组合仍是让数据落在 docroot 之外，`.htaccess` 与 deny 规则都不用管。

### 3. 验证

```bash
curl -s https://your-domain.example/coin.php
# 期望输出：
# [{"symbol":"BTC","base":"BTC","price":"75701.02","change":"2.3"},{"symbol":"SHIB","base":"SHIB","price":"0.0000183","change":"-1.32"},…]
```

### 4. （可选）用 cron 预热缓存

`update_price.php` 与 `coin.php` 共用 `lib.php` 里的抓取逻辑，区别只是**不读缓存、只写缓存**，且失败时退出码为 `1`（便于 cron 邮件或监控发现）。适合放到定时任务里，让手表请求永远命中缓存：

```cron
# 每 10 秒刷新一次（部分主机不允许 <1min 的间隔，按需调整）
* * * * * for i in 0 10 20 30 40 50; do sleep $i; php /var/www/html/update_price.php >> /var/log/ttcoin.log 2>&1; done
```

### 5. 安全建议（公开部署必看）

- 缓存与请求日志默认在站点目录的上一级（`../cache/coin.json`、`../logs/coin.log`）。若 docroot 恰好是那一级（如 `www` 为根、接口在 `www/ttcoin/`），它们仍在可访问范围内：Apache 下靠两个 `.htaccess` 兜底，**Nginx 下必须自己加** `location ^~ /ttcoin/cache/ { deny all; }` 之类（放在 PHP location 之前）；更彻底的办法是用 `TTCOIN_DATA_DIR` 把数据指到 docroot 之外，见上文「数据目录：缓存与日志放哪」；
- 增加简单的来源/频率限制，防止接口被刷；
- 若要对外开放给他人使用，请在 README 中明确写出你的服务可用性无承诺。

## 接口约定

### 请求

```
GET /coin.php
GET /coin.php?uid=ac915d426451c88e8ea691fa412f9af9c21b4d12
GET /coin.php?uid=ac915d...&type=app
GET /coin.php?uid=ac915d...&type=face
```

| 参数 | 说明 |
| --- | --- |
| `uid`（可选） | 设备唯一标识，取自 `System.getDeviceSettings().uniqueIdentifier`（API 2.4.1+）：同一设备 + 本应用取值恒定、卸载重装不变、**不同应用之间取值不同**。手表端取不到时就不带这个参数 |
| `type`（可选） | 客户端形态标记：Watch App 版固定发 `app`，Widget 版发 `widget`，表盘版（TTCoin_Face）发 `face`，服务端日志里区分同一块表上的几种安装形态 |

服务端处理方式（`lib.php` `ttcoin_client_uid()`）：

- 只留字母数字、最长 64，白名单清洗，防注入；
- 用于**响应头回显** `X-TTCoin-Uid: <uid>`，方便端到端验证链路；
- 用于**按设备记请求日志**：`coin.php` 每次请求都往 `../logs/coin.log` 追加一行（时间、形态、id、uid、来源 IP、事件、备注），见下文[请求日志](#请求日志)；
- **不参与缓存键**——缓存是全体设备共享的（这正是中转层省流量的意义），uid 将来可用于按设备统计 / 限流，取 `ttcoin_client_uid()` 的返回值即可。

> 说明：三个客户端目前都只带 `?id=`（`TC` + `uniqueIdentifier`，见[按 ID 覆盖](#表盘-typeface滚屏--固定--独立币种排序)），
> 它本身就等价于设备唯一标识；`?uid=` 是给「想单独上报原始设备号」的场景留的，
> 客户端不加也不影响任何功能，日志里 uid 列就是 `-`。

`type` 的服务端处理（`lib.php` `ttcoin_client_type()`）：白名单只认 `app` / `widget` / `face` 三个值，其他一律按未提供处理。除进日志外，`widget` 触发[精简输出](#widget-只回前-3-项开关)，`face` 触发[表盘专属出口](#表盘-typeface滚屏--固定--独立币种排序)。

无鉴权。响应头 `Content-Type: application/json; charset=utf-8`。

### Widget 只回前 3 项（开关）

Widget 版一屏只有 3 行，多给的条目它也翻不到（Widget 显示几秒就被系统回收，8 秒/页的轮播基本转不起来）。所以默认**只给 Widget 回前 3 项**：

| 请求 | 返回 |
| --- | --- |
| `?type=widget` | 候选池顺序的前 3 项 |
| `?type=app` | 全部候选币种 |
| `?type=face` | 按表盘专属顺序重排；可能是 1 条（固定）或 N 条（滚屏），见[下一节](#表盘-typeface滚屏--固定--独立币种排序) |
| 不带 `type` | 全部（无法判断形态，按最保守处理） |

```bash
curl -s 'https://your-domain.example/coin.php?type=widget' | grep -o '"base":"[^"]*"'
# "base":"BTC"  "base":"ETH"  "base":"DOGE"
```

关闭开关（回到"Widget 也拿全部"）：

```php
// 站点目录的 config.php
define('TTCOIN_WIDGET_LIMIT', false);
```

或者容器里加环境变量 `TTCOIN_WIDGET_LIMIT=0`（认 `0` / `false` / `off` / `no`，其余都当开启）。

几个关键点：

- **缓存里永远是完整列表**，裁剪只发生在出口。所以开关随时切换、App 与 Widget 交替请求都不会触发重新抓上游，也不存在"Widget 先把缓存写窄、App 就少了几项"的问题；
- 条数由 `TTCOIN_WIDGET_TOP_N` 决定（默认 3，与 `TTCoin_Widget` 的 `PAGE_ROWS` 对齐）；改成 4 也不会让 Widget 多显示一行，只会多传一项；
- **想挑哪 3 个给 Widget，就调 `ttcoin_symbols()` 的顺序**——裁剪取的是候选池的**前 N 项**，不是"涨得最多的 3 个"；
- 命中精简模式时，请求日志第 7 列会写上 `top3`，便于确认开关是否生效。


### 涨幅口径：24 小时 / 指定时区自然日

`change` 字段默认就是上游给的**滚动 24 小时涨跌幅**（`priceChangePercent`）。
想让涨幅跟着某个时区的自然日走（例如东八区：每天北京时间 00:00 归零重算），
在 `config.php` 里加两行（Worker 版在 `wrangler.toml` 的 `[vars]` 写
`CHANGE_MODE` / `CHANGE_TZ`）：

```php
define('TTCOIN_CHANGE_MODE', 'tz');   // '24h'（默认）| 'tz' | 'day' | 'timezone'
define('TTCOIN_CHANGE_TZ',   '8');    // 东八区
```

| 配置 | 默认 | 说明 |
| --- | --- | --- |
| `TTCOIN_CHANGE_MODE` | `24h` | `24h` = 上游滚动 24 小时（零额外请求，行为与以前完全一致）；`tz` = 自然日涨幅 |
| `TTCOIN_CHANGE_TZ` | `8` | 固定 UTC 偏移，**不换算夏令时**。`8` / `+8` / `UTC+8` = 东八区；`0` / `Z` = UTC；`-5` = 美东标准时；`5:30` / `5.5` = 印度。写错回落到 `8` |

自然日涨幅的定义：`(现价 − 该时区当日 00:00 开盘价) / 开盘价 × 100`，
输出仍是 3 位小数的百分比数值（不带 `%`），**表盘 / App / Widget 三端都不用改**：

- 日开盘价来自同一家上游（Binance `klines?interval=1d&timeZone=8&limit=1`，
  即当地当日那根日 K 的开盘价），按「时区 + 当地日期」写在
  `<数据目录>/cache/dayopen.json`，**一天最多抓一轮**，11 个币并行、实测约 2.6 秒含主行情；
- 跨过当地 00:00 缓存键自动失效，涨幅归零重算；
- 某个币的日开盘价没抓到（上游缺 K 线 / 网络抖动）→ **该币保留 24h 涨幅**，
  其余币照常用自然日；接口绝不会因此失败或返回空值；
- 24h 模式下这套东西完全不执行（一次额外请求都没有）。

响应头会回显本次口径，方便 curl 核对：

```bash
curl -sD - 'https://your-domain.example/coin.php?type=app' | grep -i x-ttcoin-change
# X-TTCoin-Change-Mode: tz
# X-TTCoin-Change-Tz: UTC+8
# X-TTCoin-Change-Day: 2026-11-10
# （24h 模式只有 X-TTCoin-Change-Mode: 24h）
```

配置改动在**下一次抓取**生效（PHP 缓存 TTL 10 秒，Worker 60 秒），不用清缓存、
不用重启；`cron` 预热（`update_price.php`）也走同一套逻辑。

同一时段两种口径的实测差异（2026-11-10 上午，Binance）：

| 币 | 24h 涨幅 | 东八区自然日涨幅 |
| --- | --- | --- |
| BTC | `-0.553` | `-0.881` |
| SHIB | `-0.225` | `-1.774` |
| LTC | `+0.391` | `-1.819` |
| AVAX | `+6.498` | `-4.357` |


### 表盘 `type=face`：滚屏 / 固定 + 独立币种排序

`TTCoin_Face`（表盘版）的后台请求固定带 `?type=face`。服务端对这一种请求单独决定
「显示哪些币、按什么顺序、滚不滚」，配置在 `config.php`（Worker 版在 `wrangler.toml` 的 `[vars]`）：

| 配置 | 默认值 | 说明 |
| --- | --- | --- |
| `TTCOIN_FACE_MODE` | `scroll` | `scroll` = 滚屏；`fixed` = 固定显示一个币。非法值一律回落 `scroll` |
| `TTCOIN_FACE_ORDER` | `DOGE,BTC,ETH,SHIB,SOL,BNB,XRP,ADA,LINK,LTC,AVAX` | **表盘专用**轮播顺序，按 `base` 匹配，逗号分隔；没列进去的币不会出现在表盘响应里 |
| `TTCOIN_FACE_FIXED` | `DOGE` | `fixed` 模式固定显示哪个币；留空或写错 = 用 ORDER 第一项 |
| `TTCOIN_FACE_TOP_N` | `0` | `scroll` 模式最多回几条，`0` = 按 ORDER 全给 |
| `TTCOIN_FACE_COIN` | 空 | **三端共用**的主币种（留空 = 默认币种）：face 的 fixed / 滚屏第一位、app / widget 列表第一位都换成它。支持逗号分隔的**多币种优先级列表**（如 `BTC,ETH`）：按给定顺序排到最前，fixed 模式取列表第一个 |
| `TTCOIN_FACE_IDS` | `{}` | **按 ID 覆盖**的配置（JSON，见下） |

**按 ID 覆盖**：三个客户端（表盘 TTCoin_WF / Watch App / Widget）请求都会带
`?id=xxx`。**id 由客户端自动生成**（留空设置时）：`TC` + 设备
`uniqueIdentifier`、限长 32，同设备恒定、卸载重装不变；想知道某块表的 id，
看响应头 `X-TTCoin-Face-Id`、请求日志第 3 列，或访问日志里的 `?id=`。手机设置里手动填了非空
id 则优先用手动的。`TTCOIN_FACE_IDS` 是个 JSON 对象，键是 ID，值是要覆盖的键
（`mode` / `order` / `fixed` / `topN` / `coin`，写哪个覆盖哪个，没写的继承默认
一套；同一个 ID 里 `coin` 比 `fixed` 优先）；没带 id、或 id 没登记 = 全部走
默认一套（默认币种）：

```json
{
  "11111111": { "mode": "fixed",  "fixed": "DOGE" },
  "22222222": { "mode": "scroll" },
  "33333333": { "coin": "BTC" },
  "44444444": { "coin": "BTC,ETH" }
}
```

上例：默认一套按 `TTCOIN_FACE_*` 出；`11111111` 这块表固定显示 DOGE；
`22222222` 这块表滚播（顺序仍用默认 ORDER，想单独排就再加 `"order": "..."`）；
`33333333` 是「三端只换主币种」：表盘滚屏第一个变成 BTC，App / Widget 的
列表第一条也变成 BTC（其余顺序不动）；`44444444` 是多币种优先级：BTC 第一、
ETH 第二，其余币保持默认顺序跟在后面（App / Widget 列表同样按这个顺序排）。

**coin（主币种）对三种出口的效果**（默认留空 = 不动，走默认币种；支持逗号
分隔的多币种列表，下表以列表第一个简称「主币种」）：

| 出口 | `coin` 配了之后 |
| --- | --- |
| `face` + fixed | 固定币换成列表第一个（比同 ID 里的 `fixed` 优先） |
| `face` + scroll | 按列表顺序排到 `FACE_ORDER` 最前，滚屏第一条就是列表第一个 |
| `app` | 按列表顺序排到最前，其余顺序不动 |
| `widget` | 同上，排完再裁前 3 条 |

**为什么不用新字段**：表盘端只认 `[{symbol,base,price,change}, ...]` 这个数组
（`source/TTCoinFaceService.mc`），前台逐条轮播（`TTCoinFaceView.mc` 的 `rotate()`）。
给它多条 = 滚屏；只给一条 = 轮播索引恒为 0 = 固定显示。所以输出契约一字不改，
**表盘端零改动**，服务端改配置即刻生效。

| 请求 | 返回 |
| --- | --- |
| `?type=face` + `scroll` | 按 `FACE_ORDER` 排好的 N 条，表盘逐条轮播 |
| `?type=face` + `fixed` | 只回 `FACE_FIXED` 那一条 |
| `?type=face&id=已登记的ID` | 按 `FACE_IDS` 里该 ID 的覆盖出，其余同上 |
| `?type=app` / 不带 `type` | 全部候选币种，顺序不变；登记的 ID 配了 `coin` 才把主币种排前 |
| `?type=widget` | 候选池顺序前 3 项；同上，`coin` 配了先排前再裁 |

```bash
curl -si 'https://your-domain.example/coin.php?type=face' | head -20
# X-TTCoin-Face-Mode: scroll
# X-TTCoin-Face-Order: DOGE,BTC,ETH,SHIB,SOL,BNB,XRP,ADA,LINK,LTC,AVAX
# [{"symbol":"DOGE",...},{"symbol":"BTC",...}, ...]

curl -si 'https://your-domain.example/coin.php?type=face&id=11111111' | head -20
# X-TTCoin-Face-Id: 11111111
# X-TTCoin-Face-Mode: fixed
# X-TTCoin-Face-Fixed: DOGE
# [{"symbol":"DOGE",...}]

curl -si 'https://your-domain.example/coin.php?type=app&id=33333333' | head -8
# X-TTCoin-Coin: BTC
# [{"symbol":"BTC",...},{"symbol":"ETH",...}, ...]   ← 主币种排到最前
```

几个关键点：

- **缓存里永远是完整列表**，重排 / 裁剪只发生在出口。三种形态混访不会触发重新抓上游，
  也不会出现「表盘先把缓存写窄、App 就少了几项」；
- `FACE_ORDER` 按 **`base`** 匹配。当前版本 `symbol` 与 `base` 相同（不再有倍数前缀）；
  历史缓存没有 `base` 时会从 `symbol` 反推（更早版本缓存里的 `1000*SHIB` 也能取对）；
- 配置里的币一个都没拿到（上游缺币 / 拼写全错）就**退回完整列表**，宁可多给，
  也不让表盘收到空数组显示 `--`；
- 生效配置会回显到响应头 `X-TTCoin-Face-Id` / `-Mode` / `-Order` / `-Fixed` / `-Top-N`，
  并写进请求日志第 7 列（`face:scroll:DOGE,BTC,ETH` / `face#11111111:fixed:DOGE`），
  `?id=` 本身还单独占第 3 列（没登记的 id 带 `!`），本地 `curl -i` 即可验证，不用上表。响应头只是排障用，表盘端不读；
- 主币种（`coin`）配了时三种出口都会额外回显 `X-TTCoin-Coin: <币种>`，
  多币种时回显整个列表（如 `BTC,ETH`），
  三端填同一个 id 时从这个头一眼确认拿到的是同一套主币种优先级；
- `id` 两端都只留字母数字、限长 32；`FACE_IDS` 写成非法 JSON 时视为空表，
  全部走默认，不会 500；
- **缓存不按 id 分键**：缓存里存的是完整列表，按 id 的覆盖只发生在出口；
- Worker 版同名但去掉 `TTCOIN_` 前缀（`FACE_MODE` / `FACE_IDS` 等），写在
  `wrangler.toml` 的 `[vars]`；`?diag=1` 会打印默认一套与每个登记 id 的
  生效配置及真实出口示例。

> **本轮状态**：这组改动只在本地开发副本 `Project/TTCoin/TTCoin_API`，
> 未同步到 `ttcoin-release/`、未提交、未推送 GitHub，仅用于本地测试。

### 请求日志

`coin.php` 每次请求（含命中缓存的）都会落一行日志，制表符分隔 7 列，方便 `grep` / `awk` / 导表格：

```text
2026-11-10 11:14:44	face	11111111	abc123def	203.0.113.7	hit	face#11111111:fixed:DOGE
2026-11-10 11:14:47	app	99999999!	abc123def	203.0.113.7	hit
2026-11-10 11:14:48	widget	-	-	203.0.113.7	hit	top3
2026-11-10 11:14:49	-	-	-	198.51.100.2	error	Binance HTTP 503 Connection timed out
```

| 列 | 内容 |
| --- | --- |
| 1 | 请求时间（见下方时区说明，默认北京时间） |
| 2 | 客户端形态：`app` / `widget` / `face`，未携带或非法时为 `-` |
| 3 | **行情配置 ID**（`?id=`），未提供时为 `-`；末尾带 `!` 表示这个 id **没在 `TTCOIN_FACE_IDS` 里登记**（实际走的是默认一套） |
| 4 | uid（`?uid=`），未提供时为 `-` |
| 5 | 客户端 IP（`REMOTE_ADDR`），排障用 |
| 6 | 事件：`hit`（命中新鲜缓存）/ `async`（返回旧缓存，响应结束后后台刷新）/ `fresh`（阻塞重拉上游成功）/ `stale`（上游失败、返回旧缓存）/ `error`（502 或 500） |
| 7 | 备注（失败原因、`age=秒` 等，可为空；Widget 精简模式写 `top3`，表盘写 `face:scroll:DOGE,BTC,ETH` 或 `face#11111111:fixed:DOGE`） |

> 第 3 列是查「哪块表、用的哪套行情」最直接的入口：`awk -F'\t' '$3=="11111111"' logs/coin.log`。
> 想统计有多少台设备在跑：`awk -F'\t' '$3!="-"{print $3}' logs/coin.log | sort -u | wc -l`。

- 文件位置由数据目录决定（见「数据目录：缓存与日志放哪」）：默认 `<站点目录>/../logs/coin.log`，可用 `TTCOIN_DATA_DIR` 改成 `<数据目录>/logs/coin.log`；只有连子目录都建不出来时才退回与 `coin.php` 同级的 `coin.log`；
- 单文件超过 2MB 自动滚动为 `coin.log.old`（覆盖上一代），不会无限增长；
- 每条严格占一行（行尾 `\n`）；`detail` 里的换行 / 制表符会先压成空格，避免一条被拆成多行或多出列；
- 若日志文件末尾缺少换行（旧版本写入的文件会把整个日志粘成一行），追加时会先补一个换行，不必手工清理；
- 日志时间默认用**北京时间**（`Asia/Shanghai`）。Docker 容器里 PHP 默认是 UTC，不设置就会慢 8 小时——`lib.php` 已统一处理；想改用其他时区（如 `UTC`），在 `config.php` 里 `define('TTCOIN_TIMEZONE', 'UTC');`；
- 日志写入失败是静默的，不影响接口正常响应；
- 隐私提示：uid 只是应用级匿名设备标识（关联不到 Garmin 账号），IP 建议仅用于排障、按需定期清理。

同一块表装几种形态时 **uid 相同、type 不同**：想看某块表的轨迹用 uid 过滤，想对比 APP / Widget / 表盘版活跃度用 type 过滤。

### 响应行为：新鲜 / 异步 / 阻塞

上游（Binance）从服务器出发有时要 5~30 秒，手表的请求等不了那么久，会直接超时（表现为 `- Offline -`）。所以接口按缓存年龄分三档：

| 缓存年龄 | 行为 | 手表感受 |
| --- | --- | --- |
| < `TTCOIN_CACHE_TTL`（默认 10s） | 直接返回缓存 | 秒回，日志 `hit` |
| 10s ~ `TTCOIN_STALE_SERVE`（默认 600s） | **先返回旧缓存**，`fastcgi_finish_request()` 交出响应后再在后台抓一次并写缓存 | 秒回，日志 `async`、备注 `age=N` |
| 无缓存 / 超过 600s / 非 PHP-FPM | 阻塞式抓取（最多 10s 超时），失败退回旧缓存 | 慢，最差 `stale` 或 `502` |

后台刷新用 `flock` 做单飞：并发 10 个请求也只有一个真的去拉上游，其余加锁失败直接放弃（它们的响应早已发出）。

> 只有 PHP-FPM 才有 `fastcgi_finish_request()`。Apache `mod_php` 下自动退回阻塞式流程，功能不变、只是慢一些——这种环境建议配 cron 预热。

### 成功响应 `200`

```json
[
  { "symbol": "BTC",  "base": "BTC",  "price": "75701.02",   "change": "2.3" },
  { "symbol": "ETH",  "base": "ETH",  "price": "2235.51" },
  { "symbol": "SHIB", "base": "SHIB", "price": "0.0000183",  "change": "-1.32" }
]
```

- 默认返回**全部候选币种**（当前 11 个：BTC ETH DOGE SHIB SOL BNB XRP ADA LINK LTC AVAX），显示哪些由客户端配置决定；`type=widget` 只回前 3 项，`type=face` 按[专属顺序](#表盘-typeface滚屏--固定--独立币种排序)重排甚至只回 1 条（**字段与结构不变**）；个别候选失败会被跳过，只要还有一个有效条目就算成功；
- `symbol` 是**表端直接显示的文本**，与 `base` 相同（旧版小额币的 `1000*SHIB` 倍数前缀已移除）；
- `base` 是**原始简称**，永远是 `ttcoin_symbols()` 里的值。表端的菜单勾选、本地缓存匹配都用它；
- `price` 为 **字符串**（不是数字），已按"上游几位就几位、超过 4 位才四舍五入；小额价格（小数位前面都是 0）最多 7 位"规整好，客户端**原样显示**；
- `change` 为 24 小时涨跌幅**字符串**（如 `"2.3"` / `"-1.23"` / `"0"`），来自 Binance `ticker/24hr` 的 `priceChangePercent`；
- 客户端已做防御：**少返回、乱返回都不会崩**，无法识别的条目会被跳过（最多画 3 行），但顺序仍建议保持不变。

> 为什么用字符串：价格一旦经过 `float`，小数位就回不来了。服务端用字符串运算保留上游原始位数，手表端直接拼字符串，两头都不碰浮点。

### 失败响应 `502`

```json
{ "error": "Binance HTTP 502 Connection timed out" }
```

服务端会在上游失败时优先返回**上一次成功缓存**（不看 TTL），只有缓存也不存在时才返回 502。客户端只依赖状态码和"响应体是不是数组"，因此 `error` 字段的文案可以随意调整。

极端情况下（写入的值无法编码成 JSON）返回 `500 { "error": "json_encode failed" }`，且不会污染缓存。

### 小数位怎么定

**跟着上游 API 的实际位数走**，只有超过 4 位才四舍五入（`TTCOIN_MAX_DECIMALS`）。Binance 固定下发 8 位小数（涨跌幅同理），所以第一步先去掉尾部多余的 0：

| 上游原始值 | 输出 | 说明 |
| --- | --- | --- |
| `75701.02000000` | `75701.02` | 去尾零，2 位 |
| `2235.51000000` | `2235.51` | 去尾零，2 位 |
| `0.07161000` | `0.0716` | 5 位 → 四舍五入到 4 位 |
| `1234.56789` | `1234.5679` | 5 位 → 进位 |
| `0.12300000` | `0.123` | 3 位 |
| `67000.00000000` | `67000` | 整数不留小数点 |
| `9.99999000` | `10` | 进位后不留小数点 |

实现在 `lib.php` 的 `ttcoin_format_price()`，**全程字符串 / 整数运算，不经过 `float`**。客户端只负责拼接，不再做任何格式化。

早期版本是在手表端 `format("%0.4f")`，结果是 BTC 显示成 `75678.7812` —— Monkey C 的 `Float` 是 32 位，`75678.78` 存进去实际是 `75678.78125`，后两位纯属精度噪声。这个坑也写在 [`TTCoin_APP/docs/CODE_REVIEW.md`](https://github.com/hh1949tt/TTCoin_APP/blob/main/docs/CODE_REVIEW.md) 的"踩坑记录"里。

⚠️ 例外：价格小于 `0.0001` 时 4 位会显示成 `0`——这类币由下一节的小额价格规则处理，不需要调大 `TTCOIN_MAX_DECIMALS`。

### 小额价格处理

SHIB 这类币的原始价格是 `0.00001826`，按 4 位小数四舍五入会变成 `"0"`，屏幕上什么都没了。规则：**整数部分为 0、且小数位前面都是 0** 的价格（如 `0.00001826`、`0.07161000`），小数位上限从 4 位放宽到 `TTCOIN_MAX_DECIMALS_SMALL = 7` 位，超过仍四舍五入：

| 原始值 | 输出价格 | 说明 |
| --- | --- | --- |
| `0.07161000` | `0.07161` | 去尾 0，7 位以内原样保留 |
| `0.00001826000` | `0.0000183` | 最多 7 位，第 8 位 6 → 进位 |
| `0.0000123456789` | `0.0000123` | 超过 7 位，第 8 位 4 → 舍去 |
| `0.0000000600` | `0.0000001` | 第 8 位 6 → 进位 |
| `0.5` | `0.5` | 小数位前面不是 0，走常规 4 位规则 |

与旧版**放大显示**（`1000*SHIB` 这种倍数前缀）的区别：价格按真实值显示，不再乘以倍数换算，币种名前也不加 `1000*` 等前缀；symbol 与 base 恒相同。

实现在 `lib.php` 的 `ttcoin_format_price()`（Worker 版 `formatPrice()` 逐行对应）：仍然是**全程字符串运算、不经过 float**，判断条件就是一个正则 `^-?0\.0\d`（整数部分为 0 且小数点后第一位是 0），命中才放宽位数，普通价格完全不受影响。

## 自定义配置（服务端）

| 想改什么 | 改哪里 | 说明 |
| --- | --- | --- |
| 币种列表 | `lib.php` 的 `ttcoin_symbols()` | **只改这一处**，输出顺序 = 数组顺序；`TTCoin_APP` 的 `source/TTCoinView.mc` `COINS` 要同步（菜单展示用） |
| 小数位上限 | `lib.php` 的 `TTCOIN_MAX_DECIMALS` | 默认 4；上游位数超过它才四舍五入 |
| 小额价格位数 | `lib.php` 的 `TTCOIN_MAX_DECIMALS_SMALL` | 默认 7；小数位前面都是 0 的价格放宽到这么多位 |
| 缓存时长 | `lib.php` 的 `TTCOIN_CACHE_TTL` | 单位秒，默认 10；建议 <= cron 间隔 |
| 旧值可用窗口 | `lib.php` 的 `TTCOIN_STALE_SERVE` | 单位秒，默认 600；过期后仍可先返回旧值并后台刷新，超时才阻塞 |
| 时区 | `config.php` 的 `TTCOIN_TIMEZONE` | 默认 `Asia/Shanghai`，影响日志时间戳 |
| Widget 精简开关 | `lib.php` 的 `TTCOIN_WIDGET_LIMIT`（或 `config.php` / 环境变量同名覆盖） | 默认 `true`：`?type=widget` 只回前 N 项；`false` 回全部 |
| 精简条数 | `lib.php` 的 `TTCOIN_WIDGET_TOP_N` | 默认 3，与 Widget 一屏行数一致 |
| 表盘滚屏 / 固定 | `config.php` 的 `TTCOIN_FACE_MODE`（或环境变量同名覆盖） | 默认 `scroll`；改 `fixed` 就只回一个币，表盘停止轮播 |
| 表盘独立排序 | `config.php` 的 `TTCOIN_FACE_ORDER` | 逗号分隔的 `base` 列表，默认 `DOGE,BTC,ETH,…`；只影响 `type=face`，不动 App / Widget 顺序 |
| 表盘固定币种 | `config.php` 的 `TTCOIN_FACE_FIXED` | 默认 `DOGE`；`fixed` 模式下显示它 |
| 表盘滚屏条数 | `config.php` 的 `TTCOIN_FACE_TOP_N` | 默认 0 = 按 ORDER 全给；填 3 就只滚前 3 个 |
| 数据源 | `lib.php` 的 `TTCOIN_API_URL` + `TTCOIN_API_FALLBACKS` | 主域名失败会按顺序换备用域名（同一套 API）；换交易所需同步改 `ttcoin_fetch()` 的解析字段 |
| 上游代理 | `config.php` 的 `TTCOIN_PROXY` | 空 = 直连；服务器到 Binance 全域名都不通时配它（如 `http://proxy.example:7890`） |
| 域名固定 IP | `config.php` 的 `TTCOIN_RESOLVE` | 绕开 DNS 污染，格式 `域名:443:IP`，多个用分号隔开 |

## 排查：连不上上游（`HTTP 0 … timed out`）

日志里出现这类记录，说明**服务器到 Binance 的 TCP 连接没建立起来**，跟手表、跟代码无关：

```
2026-11-08 13:14:31 ERROR Binance HTTP 0 Connection timed out after 3000 milliseconds
```

`HTTP 0` = 一个字节都没收到；`milliseconds` 是连接超时（`coin.php` 给 5 秒、后台刷新给 4 秒、`update_price.php` 给 3 秒，整体抓取预算分别是 10 / 15 / 8 秒）。

先跑诊断，别靠猜：

```bash
docker exec -it <php容器名> php /path/to/ttcoin/diag.php
```

没有命令行、又不想 ssh 的话，可以开一个带密钥的网页入口：

```php
// config.php
define('TTCOIN_DIAG_TOKEN', '你的一串随机字符（至少 16 位）');
// 生成：openssl rand -hex 16
```

然后浏览器访问 `https://<站点>/diag.php?k=你的一串随机字符`。没配密钥、密钥不对、或没带 `k`，一律返回 404 —— 外人看不出这个文件存在。看完把这一行清空即可关闭。注意网页模式会占住一个 PHP 进程几十秒，期间站点其它请求会变慢；密钥也会出现在访问日志里。

它会打印每个候选域名的 DNS 结果、TCP / TLS / 首字节各段耗时、curl errno，并给出结论。对照表：

| 诊断结果 | 含义 | 处理 |
| --- | --- | --- |
| DNS 解析失败 | 容器 DNS 不通 | 改容器 `/etc/resolv.conf` 或换 DNS |
| 有 IP 但 TCP 超时 | 多半解析到了假 IP（DNS 污染） | 用 `TTCOIN_RESOLVE` 把域名钉到真 IP |
| TCP 通但 TLS 未完成 | 中间设备干扰 TLS | 只能走 `TTCOIN_PROXY` |
| 全部域名超时 | 出口被封 | 配 `TTCOIN_PROXY` |
| 某个域名返回 200 | 那个可用 | 不用管，代码会自动优先用它 |

代码默认会依次尝试 `data-api.binance.vision`、`api.binance.com`、`api1~4.binance.com`，任一通了就记到 `<数据目录>/cache/upstream.txt`，之后优先使用。这段逻辑只影响"取数据"，不影响表端：取不到时仍按旧值窗口返回缓存（见「响应行为」）。

## 免责声明

- 本项目仅用于个人学习与自用，行情数据来自第三方公开接口，**不保证准确性、实时性与可用性**；
- 所有价格仅供参考，**不构成任何投资建议**；
- 请遵守 Binance 及各数据提供方的服务条款，不要高频抓取。

## License

以 [PolyForm Noncommercial License 1.0.0](LICENSE) 发布，**仅限非商业用途**。版权归 `TTCoin contributors` 所有。

| 可以 | 不可以 |
| --- | --- |
| 个人学习、研究、自用、改着玩 | **任何商业用途**：卖给客户、随硬件捆绑销售、公司内部业务系统、做成付费服务或订阅 |
| 学校、慈善、政府、公共研究等非商业组织使用 | 拿本项目提供有偿技术支持、代部署或托管服务 |
| 修改、二次开发、分发副本（须保留许可证与版权声明，并注明改动） | 删掉版权与许可证声明后当自己的作品发布 |

一句话区分：**能看到源码 ≠ 可以商用**。这是 source-available，不是 OSI 认可的开源许可证。

需要商用授权，到 [Issues](https://github.com/hh1949tt/TTCoin_API/issues) 说明用途即可。软件按"现状"提供，不含任何担保。

This project is licensed under the PolyForm Noncommercial License 1.0.0.
**Commercial use of any kind requires a separate written license.**
