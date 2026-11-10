# TTCoin_API on Cloudflare Workers

同一套接口的第二种运行时。**输出契约与 `coin.php` 完全一致**，手表端（APP / Widget / 表盘）不用改一行代码。

## 为什么要这个版本

PHP 版跑在家里的内网服务器上，实测从 `your-domain.example` 取一次要 **11.7s / 18.5s / 18.8s**，而手表 3 秒就超时。根因不是网络差，是阻塞式抓取把 PHP-FPM 的 worker 占满了——同一时间访问一个**不存在**的 PHP 文件都要 15.6 秒才回（正常应 <1ms），说明请求在排队。

Worker 版把抓取和缓存都放到边缘，冷启动一次，之后 60 秒内所有设备共享缓存，命中在毫秒级。

## 数据源：不押注单一家

币安把 Cloudflare 的出口 IP 段（**AS13335**）整段挡了。在 `colo=HKG / country=HK` 下实测：

| 上游 | 结果 |
| --- | --- |
| `data-api.binance.vision`（默认 UA / 浏览器 UA） | **403** + nginx 默认页，54~220ms |
| `api.binance.com` / `api1~4` | **403** |
| OKX | 429（CF 共享 IP 被限流） |
| **Bybit** | **200，55ms** |
| **Coinbase** | **200，7ms** |
| Kraken | 200，8ms |
| CoinGecko | 403（要求自定义 UA） |

`403 + nginx 默认页` 和 `451 + JSON`、`429 + JSON` 是三种不同的拒绝：前者是**边缘 ACL 按 IP 段拦**，请求根本没进业务层。

但**同一家交易所，不同节点结论相反**：同一份代码从洛杉矶节点（`CF-RAY ...-LAX`）打 Bybit，11 个币**全是 403** —— Bybit 对美国出口有地域限制。所以现在按顺序自动切换，前一家缺的币才交给下一家：

```
Coinbase（11 个币齐全，美 / 欧 / 亚都通）
  → Bybit（亚太通，符号与币安一致，做补充）
```

合并是**逐币**的：只要有一家给到价就算成功，不会因为某一家挂了整单失败。全挂才退化到冷备。

## 排查：`?diag=1`

```
https://<站点>/ttcoin/api/coin.php?diag=1
```

当场打印本次请求所在节点、出口国家、每家上游的真实状态与耗时，以及聚合结果：

```
colo=LAX  country=US  city=Los Angeles
time=2026-11-08T06:43:21.927Z
uid=-  id=TCa1b2c3d4  ip=203.0.113.7

✅ coinbase   BTC last=75664.98 prev=75941.3  521ms
❌ bybit      HTTP 403  118ms

聚合结果：11/11 个币
实际来源：coinbase

face 配置：mode=scroll  fixed=DOGE  topN=0
face 顺序：DOGE,BTC,ETH,SHIB,SOL,BNB,XRP,ADA,LINK,LTC,AVAX
face 出口：[{"symbol":"DOGE",...},{"symbol":"BTC",...}, ...]
```

它不读缓存也不写缓存，所以每次都是真实链路。「是出口被拒还是代码有 bug」看这个最快。

## 与 PHP 版的差异

| 项 | PHP 版 | Worker 版 |
| --- | --- | --- |
| 数据源 | Binance（多域名回退） | **Coinbase → Bybit 自动切换**（价格差通常 <0.1%） |
| 缓存 | 文件缓存，10s 新鲜 / 后台刷新 | Cache API，60s 新鲜 + 7 天冷备 |
| 冷备 | 有（旧值窗口） | 有（上游全挂时返回 7 天内旧值，绝不 503） |
| `uid` | 记日志，不进缓存键 | 从 `?uid=` 取，回显 `X-TTCoin-Uid` + 写 `console.log` 日志，不进缓存键 |
| 缓存键 | 无（单份文件） | `type`（app / widget / face），**刻意排除 uid** |
| 涨跌小数位 | 最多 4 位 | **3 位**（与币安 `priceChangePercent` 一致，表端是直接拼 `%` 的，多一位会挤） |
| 价格格式化 | 字符串运算 | 逐行移植，同样不走浮点 |
| 小额价格 | `0.0000183` | 同规则（小数位前面都是 0 时最多 7 位，无倍数前缀） |

响应头：`X-TTCoin-Cache`（`HIT` / `MISS` / `STALE`）、`X-TTCoin-Source`（实际用了哪几家，如 `coinbase` / `coinbase+bybit`）、`X-TTCoin-Age`（缓存年龄）、`X-TTCoin-Degraded`（有币没拿到价时为 `1`）、`X-TTCoin-Uid`（客户端带了 `?uid=` 才回显）。`?type=face` 额外回显 `X-TTCoin-Face-Mode` / `-Order` / `-Fixed` / `-Top-N`。

## 设备标识从哪来：Worker 只能靠客户端带

边缘节点**看不到手表硬件**，Cloudflare 侧没有任何 API 能"查设备 ID"。能拿到的
只有请求本身带的参数 + 连接信息：

| 来源 | 怎么取（Worker 里） | 是什么 |
| --- | --- | --- |
| `?id=` | `url.searchParams.get('id')` | **行情配置 ID**，三个客户端都发，格式 `TC` + 设备 `uniqueIdentifier`（限长 32，同设备恒定），实际就等价于设备 ID |
| `?uid=` | `url.searchParams.get('uid')` | 可选的原设备匿名标识，白名单只留字母数字、最长 64；客户端不带就是空 |
| 客户端 IP | `request.headers.get('CF-Connecting-IP')` | 出口 IP，同一 NAT 后多台表会重合，**不能当设备 ID** |
| 节点位置 | `request.cf.colo` / `.country` / `.city` | 请求落在哪个边缘节点，与设备无关 |

所以 `worker.js` 的做法是：解析 `?uid=` / `?id=` → 回显 `X-TTCoin-Uid` → 每个请求写一行
JSON 日志（`console.log`），验链路和查「哪块表用哪套行情」都够用。

### 请求日志怎么看

```bash
npx wrangler tail                 # 实时流（本地 wrangler dev 直接打在终端）
```

```json
{"t":"2026-11-10T03:14:04.057Z","type":"face","id":"TCa1b2c3d4","idKnown":true,
 "uid":"","ip":"203.0.113.7","colo":"LAX","cc":"US","cache":"HIT",
 "src":"coinbase","coin":"BTC","age":1}
```

- `id` / `uid` / `ip` 就是"哪块表在请求"；`idKnown:false` 表示这个 id **没在
  `FACE_IDS` 里登记**（实际走默认一套，与 PHP 日志里 id 末尾的 `!` 同义）；
- `cache` = `HIT` / `MISS` / `STALE` / `EMPTY`（上游全挂且无冷备，503）；
- 不想留日志：`wrangler.toml` 里 `LOG_REQUESTS = "0"`（默认 `"1"`）；
- 需要按 uid 做长期统计（而不是翻日志）时，再挂 Analytics Engine 数据集，在
  `logRequest()` 里加一行 `env.ANALYTICS.writeDataPoint({ indexes: [uid], ... })`；
  那是额外计费项，默认不接；
- 控制台 → Workers → 你的 Worker → Logs 也能按时间回看（保留期取决于套餐）。

## 表盘 `?type=face`：滚屏 / 固定 + 独立币种排序

表盘版（TTCoin_Face）的固定请求带 `?type=face`。Worker 对它有独立出口，
配置写在 `wrangler.toml` 的 `[vars]`（与 PHP 版 `config.php` 一一对应，去掉 `TTCOIN_` 前缀）：

| vars | 默认 | 说明 |
| --- | --- | --- |
| `FACE_MODE` | `scroll` | `scroll` 滚屏 / `fixed` 固定显示一个币；非法值回落 `scroll` |
| `FACE_ORDER` | `DOGE,BTC,ETH,SHIB,SOL,…` | **表盘专用**轮播顺序，按 `base` 匹配，逗号分隔 |
| `FACE_FIXED` | `DOGE` | `fixed` 模式显示哪个币；留空 / 写错 = ORDER 第一项 |
| `FACE_TOP_N` | `0` | `scroll` 最多回几条，`0` = 按 ORDER 全给 |
| `FACE_COIN` | 空 | **三端共用**的主币种（留空 = 默认币种）：face 的 fixed / 滚屏第一位、app / widget 列表第一位都换成它。支持逗号分隔的**多币种优先级列表**（如 `BTC,ETH`）：按给定顺序排到最前，fixed 模式取列表第一个 |
| `FACE_IDS` | `{}` | 按 ID 覆盖（JSON）：三个客户端（表盘 / App / Widget）请求都带 `?id=`——**id 客户端自动生成**（`TC` + 设备 uniqueIdentifier、限长 32，同设备恒定；响应头 `X-TTCoin-Face-Id` 可查），手动填了「行情配置 ID」则优先用手动的。登记了哪个 id 就按登记的键（`mode` / `order` / `fixed` / `topN` / `coin`，同一 id 里 `coin` 比 `fixed` 优先；`coin` 支持逗号分隔的多币种优先级列表）覆盖默认一套；没登记 / 没带 id 走默认一套（默认币种） |

表盘端只认数组、多条就逐条轮播、一条就固定，所以 `fixed` 是「只回 1 条」实现的，
**输出契约不变、表盘端零改动**。`face` 独享一份缓存键（存的仍是完整列表，重排 / 裁剪
只发生在出口）；配置里的币一个都没拿到时退回完整列表，宁可多给也不让表盘显示 `--`。
生效配置回显在响应头，`?diag=1` 也会打印配置与真实出口示例。
完整规则和 PHP 侧说明见上一层 `README.md`。

> 本轮改动只在本地开发副本 `Project/TTCoin/TTCoin_API`，未同步到 `ttcoin-release/`、
> 未提交、未推送 GitHub，仅用于本地测试。

## 涨幅口径：24 小时 / 指定时区自然日

`change` 字段默认是上游给的**滚动 24 小时涨跌幅**。想让涨幅跟着某个时区的
自然日走（东八区：每天北京时间 00:00 归零重算），在 `[vars]` 里改两个值：

```toml
CHANGE_MODE = "tz"    # '24h'（默认）| 'tz'
CHANGE_TZ   = "8"     # 固定 UTC 偏移，不换算夏令时
```

| vars | 默认 | 说明 |
| --- | --- | --- |
| `CHANGE_MODE` | `24h` | `24h` = 上游滚动 24 小时（零额外请求）；`tz` = 自然日涨幅 `(现价 − 当地 00:00 开盘价) / 开盘价 × 100` |
| `CHANGE_TZ` | `8` | `8` / `+8` / `UTC+8` = 东八区；`0` / `Z` = UTC；`-5` = 美东标准时；`5:30` / `5.5` = 印度。写错回落到 `8` |

日开盘价按「时区 + 当地日期」存在 Cache API（`dayopen-<tz>-<day>` 键，
**一天最多抓一轮**），跨过当地 00:00 自动换键。取价思路：先在 Coinbase 的
1 分钟 K 线里找当地 00:00 那根，没有成交（如 SHIB）就取当日头 10 分钟里最早的
一根，再不行交给 Bybit 的 `interval=1` 补；**某个币实在取不到就保留它的 24h 涨幅**，
其余币照常用自然日，接口不会因此失败。

响应头回显本次口径：

```bash
curl -sD - 'https://your-domain.example/coin.php?type=app' | grep -i x-ttcoin-change
# X-TTCoin-Change-Mode: tz
# X-TTCoin-Change-Tz: UTC+8
# X-TTCoin-Change-Day: 2026-11-10
# （24h 模式只有 X-TTCoin-Change-Mode: 24h）
```

`?diag=1` 也会打印当前口径、当地日期与日开盘价抓取情况。

## 部署

```bash
cd worker
npx wrangler login          # 浏览器授权，一次就够
npx wrangler deploy         # 先发到 *.workers.dev 上验证
```

`wrangler deploy` 后它会打印一个 `https://ttcoin-api.<你的子域>.workers.dev` 地址，直接 curl：

```bash
curl -s 'https://ttcoin-api.<你的子域>.workers.dev/?type=app&diag=1'
```

确认没问题，再把 `wrangler.toml` 里的 `routes` 注释取消，重新 `deploy`——这样 Worker 会接管
`your-domain.example/coin.php` 这个路径，**手表上的地址一个字都不用改，也不用重发版本**。

前提：域名的 DNS 托管在同一个 Cloudflare 账号下（现在已经在 CF 后面，满足）。

### 路由模式必须带 `*`，否则带参数的请求会漏到源站

Cloudflare 的路由匹配**把查询串算进整条 URL**，而路由模式里又不允许写 `?foo=*`。官方文档原话：

> Route pattern matching considers the entire request URL, including the query parameter string. Since route patterns may not contain query parameters, the only way to have a route pattern match URLs with query parameters is to terminate it with a wildcard, `*`.

所以写成 `.../coin.php` 时：**不带参数**的请求进 Worker，**带 `?type=app` 的请求照旧回源站**。而手表每次请求都带 `type`——等于完全没生效。必须写成：

```
your-domain.example/coin.php*
```

或者更省事：`your-domain.example/coin.php*`（`diag.php` 由 PHP 自己的密钥守卫负责，不受影响）。

## 回滚

删掉路由即可，秒级生效：

```bash
npx wrangler deployments list      # 看当前版本
npx wrangler rollback              # 回上一个版本
```

或者直接在面板 **Workers & Pages → 你的 Worker → Settings → Domains & Routes → Remove route**，
流量立刻回到内网 PHP 版（PHP 版和缓存文件都没动过，随时能接回去）。

## 本地调试

```bash
npx wrangler dev            # 本地起一个，走真实上游

# 另开一个终端：验证 face 出口与响应头
curl -si 'http://127.0.0.1:8787/coin.php?type=face' | head -20
curl -s  'http://127.0.0.1:8787/coin.php?diag=1'   # 末尾会打印 face 配置与出口示例
```

想临时切固定显示，改 `wrangler.toml` 的 `FACE_MODE = "fixed"`（或本地放一份
`.dev.vars` 覆盖同名变量），不用动 `worker.js`。

## 已知限制

- **免费额度 10 万请求/天**。手表每分钟刷新一次 = 1440 次/天/设备，几十台设备毫无压力；一旦超了，额度按 UTC 零点重置。
- **价格不是币安盘口**。Coinbase / Bybit 与币安各有细微价差，BTC 可能差几十美元。对"看个大概"的手表场景无影响；要和币安严格一致就不适用。
- **交易所对数据中心 IP 的态度会变**。加一家上游只需在 `PROVIDERS` 里追加一个 `{ name, url, parse }`，20 行以内；`?diag=1` 会立刻告诉你新上游在各地通不通。
- 冷备只在**同一个 Worker 版本**内有效（Cache API 按 data center 存），跨节点不会共享，但每个节点各自有自己的一份，够用。
