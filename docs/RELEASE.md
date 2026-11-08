# TTCoin_API 发布文档

> 服务端中转：把 Binance 24h ticker 裁成手表可直接显示的 JSON，带文件缓存、旧值兜底与按设备请求日志。
> 独立于两个手表项目部署；表端只依赖 HTTP 契约，不依赖本项目的文件结构。

| 项目 | 版本 | 发布日期 | 发布形态 | 状态 |
| --- | --- | --- | --- | --- |
| TTCoin_API | **1.0.1** | 2026-11-08 | 自建服务器部署（不上架任何商店） | 可发布；缓存命中率待线上按 §6.1 核对 |

**发布顺序**：`TTCoin_API` → `TTCoin_Widget` → `TTCoin_APP`。服务端先上线，否则表端一定 `No Net`（表端无法自证是网络问题还是服务端没起）。

---

## 1. 版本与组件

| 项 | 值 |
| --- | --- |
| 版本 | 1.0.1（1.0.0 起从 `TTCoin_APP/api/` 拆为独立项目；1.0.1 增加 `diag.php` 网页模式并修其配置加载） |
| 运行环境 | PHP **7.4+**，扩展 `curl`、`json`；HTTPS 终结在 Web 服务器 |
| 部署形态 | 单目录 PHP 脚本 + 文件缓存，无数据库、无框架、无 Composer |
| 数据源 | `https://data-api.binance.vision/api/v3/ticker/24hr` |
| 时间基准 | 日志时间戳默认 `Asia/Shanghai` |
| 发布物 | 3 个 PHP + 3 层 `.htaccess`（见第 2 节） |

本版本号规则：**主版本**改动 HTTP 契约（字段、状态码语义、必填参数）或数据目录约定；**次版本**新增可选参数 / 开关；**补丁**只改内部实现或修 bug。表端只依赖契约，所以主版本变化必须同步发两个表端项目。

## 2. 发布物清单

站点目录（示例 `/var/www/www/ttcoin`）应包含：

| 文件 | 必须 | 职责 |
| --- | --- | --- |
| `coin.php` | ✅ | 手表调用的主接口，三档响应 + 日志 |
| `update_price.php` | ✅ | cron 预热：只写缓存，失败退出码 1 |
| `lib.php` | ✅ | 抓取 / 格式化 / 缓存 / 日志 / 参数清洗（缺失则两个入口直接 fatal） |
| `diag.php` | 建议 | 上游连通性诊断；命令行始终可用，网页模式默认关闭（需 `TTCOIN_DIAG_TOKEN`）；报 `HTTP 0 timed out` 时先跑它 |
| `.htaccess` | 建议 | Apache 兜底：拒绝 `coin_cache.json`、`coin.log`、`lib.php` 直连（`diag.php` 的访问控制由 PHP 里的密钥负责，网页模式需在 nginx/Apache 下都能用） |
| `cache/.htaccess` | 建议 | 缓存目录兜底 |
| `logs/.htaccess` | 建议 | 日志目录兜底 |
| `config.php` | 可选 | 覆盖数据目录 / 时区 / Widget 精简开关；不存在走默认 |

```
ttcoin/
├── coin.php
├── update_price.php
├── lib.php
├── diag.php                # 可选，排查上游连通性用
├── config.php              # 可选，仓库自带、内容等于默认值
├── .htaccess
├── cache/.htaccess
└── logs/.htaccess
```

> ✅ **评估发现（已修正）**：仓库里是可直接生效的 `config.php`（内含 `define('TTCOIN_WIDGET_LIMIT', true)`），而 `README.md` 原先引用的是不存在的 `config.example.php`。照 README 部署的人会以为"不用建 config.php"，照目录抄的人会把调试配置带上线。README 的文件表与部署段已改为指向 `config.php` 并注明"仓库自带、内容等于默认值、删掉也能跑"。

## 3. 发布就绪评估

| 维度 | 结论 | 依据 |
| --- | --- | --- |
| 语法 | ⚠️ 间接验证 | 本机无 PHP 运行时，改动是用 `php-parser`（JS 实现）+ node 等价逻辑跑通的，**没有跑过 `php -l`**。部署前必须在目标机跑一次 |
| 功能逻辑 | ✅ | 格式化 / 放大 / 裁剪 / 日志行格式均用等价实现逐例比对过（含 8 位小数、进位、SHIB 放大倍数） |
| HTTP 契约 | ✅ 已实测 | `?type=widget` 回 3 项、`?type=app` 与不带参数回全量，形状与表端解析一致（§6.1） |
| 异步刷新 | ✅ 设计成立，⚠️ 线上疑似未命中 | 实测同一 URL 首字节 2.7s / 5.8s / 8.8s / 22.1s，且一次 >40s；背靠背第二次 22s 不符合"命中 10s 缓存"，怀疑缓存未写入或未读到（§6.1、R6） |
| 缓存与日志目录 | ✅ | 兼容 `www/XXX` + `XXX/cache` + `XXX/logs` 的布局（`TTCOIN_DATA_DIR` = `__DIR__`） |
| 安全 | ⚠️ 取决于部署 | 数据若落在 docroot 内，Nginx **不读** `.htaccess`，必须手写 `deny all` |
| 回滚 | ✅ | 纯文件替换，缓存格式未变 |

**总体判定：可发布（契约已验证），但上线后必须做一次 §6.1 的缓存自查。** 若 `cache/coin.json` 的 mtime 不随请求前进，异步档不会触发，表端就会退化成"每次等上游"，表现为长时间 `- Cache -`。

## 4. 部署步骤

### 4.1 静态检查（目标机）

```bash
php -v                                  # >= 7.4
php -m | grep -E '^(curl|json)$'
php -l coin.php && php -l lib.php && php -l update_price.php
```

### 4.2 上传与数据目录

默认数据目录是站点目录的**上一级**（`../cache`、`../logs`）。docroot 若绑在上一级（`www` 为根、接口在 `www/ttcoin/`），必须显式指定，否则缓存与日志对公网可读：

```php
<?php
// 站点目录下 config.php
define('TTCOIN_DATA_DIR', __DIR__);      // 落在 ttcoin/cache、ttcoin/logs
// define('TTCOIN_DATA_DIR', '/var/www/data');   // 或放 docroot 之外
```

```yaml
# 或走容器环境变量
environment:
  TTCOIN_DATA_DIR: /var/www/data
```

权限：容器内 PHP 进程属主（Alpine 常见 `nginx`/`82`，Debian 系 `www-data`）要能写该目录：

```bash
chown -R www-data:www-data /var/www/www/ttcoin
```

### 4.3 Nginx 必须补的两条（Docker 部署）

```nginx
# docroot = www，数据落在 www/ttcoin/cache、www/ttcoin/logs
location ^~ /ttcoin/cache/ { deny all; }
location ^~ /ttcoin/logs/  { deny all; }
```

位置必须在 `location ~ \.php$` **之前**，否则请求先进 PHP。放在 docroot 之外则两条都不用写。

### 4.4 cron 预热（强烈建议）

上游从服务器出发经常 5~30s，阻塞路径迟早触发一次。预热后手表几乎永远命中缓存：

```cron
* * * * * for i in 0 10 20 30 40 50; do sleep $i; php /path/to/ttcoin/update_price.php >> /var/log/ttcoin.log 2>&1; done
```

cron 间隔应 ≤ `TTCOIN_CACHE_TTL`（默认 10s），否则表端仍会命中过期缓存走异步路径。

## 5. 配置项（全部有默认值，不改也能跑）

| 常量 | 默认 | 作用 |
| --- | --- | --- |
| `TTCOIN_CACHE_TTL` | 10 | 新鲜缓存窗口（秒），命中即 `hit` |
| `TTCOIN_STALE_SERVE` | 600 | 旧值兜底窗口（秒），窗口内返回旧值 + 后台刷新 |
| `TTCOIN_WIDGET_LIMIT` | `true` | `?type=widget` 时是否只回前 N 项 |
| `TTCOIN_WIDGET_TOP_N` | 3 | 精简条数，与 Widget 一屏行数对齐 |
| `TTCOIN_DATA_DIR` | `dirname(__DIR__)` | 缓存 / 日志父目录 |
| `TTCOIN_TIMEZONE` | `Asia/Shanghai` | 日志时间戳时区 |
| `TTCOIN_MAX_DECIMALS` | 4 | 常规价格小数位上限 |
| `TTCOIN_SCALE_MIN_ZEROS` | 3 | 小数前导 0 达到几个才放大价格 |
| `TTCOIN_API_URL` | Binance `ticker/24hr` | 换源需同步改 `ttcoin_fetch()` 解析字段 |

## 6. 发布后冒烟（4 条命令 + 1 张日志）

```bash
# ① 基础连通 + 契约形状
curl -s 'https://api.example.com/ttcoin/api/coin.php' | head -c 300
# 期望：[{"symbol":"BTC","base":"BTC","price":"...","change":"..."}, ...]  全量

# ② Widget 精简：只回 3 项
curl -s '.../coin.php?type=widget' | grep -o '"base":"[^"]*"'
# 期望：恰好 3 个

# ③ uid 回显
curl -sI '.../coin.php?uid=ac915d42' | grep -i x-ttcoin-uid
# 期望：X-TTCoin-Uid: ac915d42

# ④ 首字节时间（应 < 1s，命中缓存或异步）
curl -s -o /dev/null -w '%{time_starttransfer}\n' '.../coin.php'
```

日志（`<数据目录>/logs/coin.log`）应出现 6 列、每行独立：

```text
2026-11-08 11:20:01	widget	ac915d42	203.0.113.7	hit	top3
2026-11-08 11:20:11	app	ac915d42	203.0.113.7	async	age=37
```

验收标准：`hit` / `async` 占绝大多数；偶发 `fresh` 正常；持续 `stale` 说明上游不可达。

### 6.1 实测记录（2026-11-08 11:55~12:10）

| 请求 | 结果 |
| --- | --- |
| `?type=widget` | 200，**正好 3 项**：`BTC 75882 / ETH 2237.49 / DOGE 0.0713` → 出口裁剪生效 |
| `?type=app` | 200，727 字节全量（11 个候选） |
| 不带 `type` | 200，726 字节全量（按最保守处理，符合设计） |
| `X-TTCoin-Uid` 回显 | 未单独验证（当轮未带 `uid` 参数） |

耗时（`curl -w`，同一 URL 连续观测）：

```text
dns=0.00004  tcp=0.0013  tls=0.56  ttfb=2.77   total=2.77
ttfb=5.84  total=7.87      # 背靠背第 1 次
ttfb=22.09 total=22.09     # 背靠背第 2 次（紧接着第 1 次，理论上应命中 10s 缓存）
另有 1 次 >40s 未返回
```

两处需要留意：

1. `tcp=0.0013` 说明本机到该域名中间有本地代理 / TUN 接管，**这段耗时不完全等于服务器耗时**，ttfb 波动（2.7~22s）不能全算在 PHP 头上；
2. 背靠背第 2 次仍要 22s —— 若缓存正常，它应该命中 10s TTL 并秒回。**说明缓存很可能没有被写入或没被读到**（权限、路径不一致，或该请求走的是阻塞分支）。线上请按下面两条自查：

```bash
ls -l <数据目录>/cache/coin.json        # 存在吗？
stat -c '%y %s' <数据目录>/cache/coin.json   # 每次请求后 mtime 是否前进
tail -5 <数据目录>/logs/coin.log        # 第 5 列是否出现 hit / async
```

- 文件不存在或 mtime 不动 → `ttcoin_cache_write()` 静默失败（目录不可写），此时所有请求都会阻塞拉上游，手表必然长时间停在 `- Cache -`；
- 文件在、但日志里几乎全是 `fresh` / `async` 且 ttfb 一直 >2s → 上游确实慢，按 §4.4 上 cron 预热。

## 7. 回滚

1. 保留上一版目录（`ttcoin@1.0.0` 之类），回滚即改 Nginx root 或覆盖文件；
2. **缓存文件不用删**：格式是 JSON 数组，跨版本兼容；删了只是下一请求变阻塞抓取；
3. `config.php` 与数据目录属于部署侧，回滚代码时不必动；
4. 回滚后跑第 6 节 ①② 确认契约没退化。

## 8. 已知限制（不在 1.0.0 范围）

- **无鉴权、无限流**：公开地址可被任意刷；目前只靠缓存吸收并发；
- **单数据源**：只接 Binance，无多源容灾；
- **日志无压缩**：单文件 2MB 滚动一代（`coin.log.old`），长期上限约 4MB；
- **uid 可伪造**：仅用于日志区分设备，不参与鉴权与缓存键；
- **缓存是全体设备共享**：同一份数据，不做按设备差异化。

## 9. 风险登记

| 编号 | 风险 | 影响 | 现状与缓解 |
| --- | --- | --- | --- |
| R1 | 上游偶发 5~30s | 表端请求超时 → `- Offline -` | 已缓解：`TTCOIN_STALE_SERVE` + `fastcgi_finish_request` + `flock` 单飞；根治靠 cron 预热 |
| R2 | 非 PHP-FPM 环境（Apache mod_php） | 无 `fastcgi_finish_request`，退回阻塞路径 | 自动降级，功能不变；此类环境必须配 cron |
| R3 | 数据目录落在 docroot 内 | 缓存 / 日志可被直接下载 | 文档给了 Nginx `deny` 与 `TTCOIN_DATA_DIR` 两种解法；上线后按 README「数据会不会被公网访问」实测两条 `curl -sI` |
| R4 | 未跑 `php -l` | 语法错误会 500 | 第 4.1 节列为发布门禁 |
| R5 | 只做 Unicorn 单进程锁 | 多机部署时各自刷各自的 | 当前单机部署，不涉及 |
| R6 | **缓存可能没真正生效**（新发现） | 每个请求都阻塞拉上游 → 表端长时间 `- Cache -`，异步档形同虚设 | 11-08 实测：背靠背两次请求第二次仍 22s，不符合"命中 10s TTL"；按 §6.1 三行命令核对 `cache/coin.json` 的 mtime 与日志第 5 列 |
| R7 | 网络路径有本地代理接管 | ttfb 抖动 2.7~22s，误判为服务端慢 | 拆 `time_namelookup/connect/appconnect` 再下结论，见 §6.1 |
| R8 | **上游域名个别不可达 / DNS 污染** | 单域名连不上 → 一直返回缓存，日志刷 `HTTP 0 … timed out` | 已缓解：`TTCOIN_API_FALLBACKS` 六域名轮询 + 记住上次成功域名；被污染时用 `TTCOIN_RESOLVE` 钉 IP，出口被封时用 `TTCOIN_PROXY`。诊断走 `php diag.php` |

## 10. 变更记录

| 日期 | 版本 | 内容 |
| --- | --- | --- |
| 2026-11-08 | 1.0.0 | 从 `TTCoin_APP/api/` 拆出为独立项目：三档响应（hit/async/fresh-stale-error）、`flock` 单飞后台刷新、`tmp+rename` 原子写缓存、`TTCOIN_DATA_DIR` 可配置、Widget 出口裁剪、日志时区与整行修复；上游改为多域名回退（`TTCOIN_API_FALLBACKS`）+ 记住成功域名 + 错误带 curl errno，新增 `diag.php` 与 `TTCOIN_PROXY` / `TTCOIN_RESOLVE` 两个逃生配置 |
| 2026-11-08 | 1.0.1 | `diag.php` 新增带密钥的网页模式（`TTCOIN_DIAG_TOKEN`，不配仍返回 404，密钥短于 16 位视为未配置）；修复 `diag.php` 未加载 `config.php`、导致已配的 `TTCOIN_PROXY` / `TTCOIN_RESOLVE` / `TTCOIN_DATA_DIR` 在诊断里显示为默认值；`.htaccess` 不再拦 `diag.php`（访问控制交给 PHP，nginx 与 Apache 行为一致） |
