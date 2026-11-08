# TTCoin_API on Cloudflare Workers

同一套接口的第二种运行时。**输出契约与 `coin.php` 完全一致**，手表端（APP / Widget）不用改一行代码。

## 为什么要这个版本

PHP 版跑在家里的 NAS 上，实测从 `api.example.com` 取一次要 **11.7s / 18.5s / 18.8s**，而手表 3 秒就超时。根因不是网络差，是阻塞式抓取把 PHP-FPM 的 worker 占满了——同一时间访问一个**不存在**的 PHP 文件都要 15.6 秒才回（正常应 <1ms），说明请求在排队。

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

✅ coinbase   BTC last=75664.98 prev=75941.3  521ms
❌ bybit      HTTP 403  118ms

聚合结果：11/11 个币
实际来源：coinbase
```

它不读缓存也不写缓存，所以每次都是真实链路。「是出口被拒还是代码有 bug」看这个最快。

## 与 PHP 版的差异

| 项 | PHP 版 | Worker 版 |
| --- | --- | --- |
| 数据源 | Binance（多域名回退） | **Coinbase → Bybit 自动切换**（价格差通常 <0.1%） |
| 缓存 | 文件缓存，10s 新鲜 / 后台刷新 | Cache API，60s 新鲜 + 7 天冷备 |
| 冷备 | 有（旧值窗口） | 有（上游全挂时返回 7 天内旧值，绝不 503） |
| `uid` | 记日志，不进缓存键 | 接收但完全忽略，也不进缓存键 |
| 缓存键 | 无（单份文件） | `type`（app / widget），**刻意排除 uid** |
| 涨跌小数位 | 最多 4 位 | **3 位**（与币安 `priceChangePercent` 一致，表端是直接拼 `%` 的，多一位会挤） |
| 价格格式化 | 字符串运算 | 逐行移植，同样不走浮点 |
| 小额放大 | `1000*SHIB` | 同规则（`0.000004467` → `0.04467`，factor 10000） |

响应头：`X-TTCoin-Cache`（`HIT` / `MISS` / `STALE`）、`X-TTCoin-Source`（实际用了哪几家，如 `coinbase` / `coinbase+bybit`）、`X-TTCoin-Age`（缓存年龄）、`X-TTCoin-Degraded`（有币没拿到价时为 `1`）。

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
`api.example.com/ttcoin/api/coin.php` 这个路径，**手表上的地址一个字都不用改，也不用重发版本**。

前提：域名的 DNS 托管在同一个 Cloudflare 账号下（现在已经在 CF 后面，满足）。

### 路由模式必须带 `*`，否则带参数的请求会漏到源站

Cloudflare 的路由匹配**把查询串算进整条 URL**，而路由模式里又不允许写 `?foo=*`。官方文档原话：

> Route pattern matching considers the entire request URL, including the query parameter string. Since route patterns may not contain query parameters, the only way to have a route pattern match URLs with query parameters is to terminate it with a wildcard, `*`.

所以写成 `.../coin.php` 时：**不带参数**的请求进 Worker，**带 `?type=app` 的请求照旧回源站**。而手表每次请求都带 `type`——等于完全没生效。必须写成：

```
api.example.com/ttcoin/api/coin.php*
```

或者更省事：`api.example.com/ttcoin/api/*`（`diag.php` 由 PHP 自己的密钥守卫负责，不受影响）。

## 回滚

删掉路由即可，秒级生效：

```bash
npx wrangler deployments list      # 看当前版本
npx wrangler rollback              # 回上一个版本
```

或者直接在面板 **Workers & Pages → 你的 Worker → Settings → Domains & Routes → Remove route**，
流量立刻回到 NAS 上的 PHP 版（PHP 版和缓存文件都没动过，随时能接回去）。

## 本地调试

```bash
npx wrangler dev            # 本地起一个，走真实上游
```

## 已知限制

- **免费额度 10 万请求/天**。手表每分钟刷新一次 = 1440 次/天/设备，几十台设备毫无压力；一旦超了，额度按 UTC 零点重置。
- **价格不是币安盘口**。Coinbase / Bybit 与币安各有细微价差，BTC 可能差几十美元。对"看个大概"的手表场景无影响；要和币安严格一致就不适用。
- **交易所对数据中心 IP 的态度会变**。加一家上游只需在 `PROVIDERS` 里追加一个 `{ name, url, parse }`，20 行以内；`?diag=1` 会立刻告诉你新上游在各地通不通。
- 冷备只在**同一个 Worker 版本**内有效（Cache API 按 data center 存），跨节点不会共享，但每个节点各自有自己的一份，够用。
