# TTCoin_API on Cloudflare Workers

同一套接口的第二种运行时。**输出契约与 `coin.php` 完全一致**，手表端（APP / Widget）不用改一行代码。

## 为什么要这个版本

PHP 版跑在家里的 NAS 上，实测从 `api.example.com` 取一次要 **11.7s / 18.5s / 18.8s**，而手表 3 秒就超时。根因不是网络差，是阻塞式抓取把 PHP-FPM 的 worker 占满了——同一时间访问一个**不存在**的 PHP 文件都要 15.6 秒才回（正常应 <1ms），说明请求在排队。

Worker 版把抓取和缓存都放到边缘，冷启动一次，之后 60 秒内所有设备共享缓存，命中在毫秒级。

## 数据源为什么不是币安

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

`403 + nginx 默认页` 和 `451 + JSON`、`429 + JSON` 是三种不同的拒绝：前者是**边缘 ACL 按 IP 段拦**，请求根本没进业务层。换域名、换 UA、换 colo 都没用。

Bybit 被选为主源，因为它的现货符号与币安**完全相同**（`BTCUSDT`、`SHIBUSDT`…），且一次请求同时给出 `lastPrice`、`prevPrice24h`、`price24hPcnt`，价格和 24h 涨跌一次拿齐。11 个币种实测全部可用。

## 与 PHP 版的差异

| 项 | PHP 版 | Worker 版 |
| --- | --- | --- |
| 数据源 | Binance（多域名回退） | **Bybit 现货**（价格差通常 <0.1%） |
| 缓存 | 文件缓存，10s 新鲜 / 后台刷新 | Cache API，60s 新鲜 + 7 天冷备 |
| 冷备 | 有（旧值窗口） | 有（上游全挂时返回 7 天内旧值，绝不 503） |
| `uid` | 记日志，不进缓存键 | 接收但完全忽略，也不进缓存键 |
| 缓存键 | 无（单份文件） | `type`（app / widget），**刻意排除 uid** |
| 涨跌小数位 | 最多 4 位 | **3 位**（与币安 `priceChangePercent` 一致，表端是直接拼 `%` 的，多一位会挤） |
| 价格格式化 | 字符串运算 | 逐行移植，同样不走浮点 |
| 小额放大 | `1000*SHIB` | 同规则（`0.000004467` → `0.04467`，factor 10000） |

`X-TTCoin-Cache` 响应头会告诉你这次走的是 `HIT` / `MISS` / `STALE`，`X-TTCoin-Age` 是缓存年龄，排查时很有用。

## 部署

```bash
cd worker
npx wrangler login          # 浏览器授权，一次就够
npx wrangler deploy         # 先发到 *.workers.dev 上验证
```

`wrangler deploy` 后它会打印一个 `https://ttcoin-api.<你的子域>.workers.dev` 地址，直接 curl：

```bash
curl -s 'https://ttcoin-api.<你的子域>.workers.dev/?type=app' | head -c 300
```

确认没问题，再把 `wrangler.toml` 里的 `routes` 注释取消，重新 `deploy`——这样 Worker 会接管
`api.example.com/ttcoin/api/coin.php` 这个路径，**手表上的地址一个字都不用改，也不用重发版本**。

前提：域名的 DNS 托管在同一个 Cloudflare 账号下（现在已经在 CF 后面，满足）。

## 回滚

删掉路由即可，秒级生效：

```bash
npx wrangler deployments list      # 看当前版本
npx wrangler rollback              # 回上一个版本
```

或者直接在面板 **Workers & Pages → 你的 Worker → Settings → Triggers → Remove route**，
流量立刻回到 NAS 上的 PHP 版（PHP 版和缓存文件都没动过，随时能接回去）。

## 本地调试

```bash
npx wrangler dev            # 本地起一个，走真实上游
```

## 已知限制

- **免费额度 10 万请求/天**。手表每分钟刷新一次 = 1440 次/天/设备，几十台设备毫无压力；一旦超了，额度按 UTC 零点重置。
- **Bybit 与币安的价格不是同一个盘口**，BTC 可能差几十美元。对"看个大概"的手表场景无影响；如果要和币安严格一致，这个方案不适用。
- **Bybit 若也被 CF 挡**，`fetchOne()` 换域名即可（Coinbase / Kraken 实测都是 200），响应字段映射在 `buildPayload()` 里。这种替换是 20 行以内的事。
