# ZFS Snapshot Manager — 插件规划

## 1. 产品定位

这是一个 **Unraid WebGUI 原生 ZFS Snapshot 生命周期管理插件**，重点解决 Unraid 对 ZFS snapshot 缺少统一 GUI 的问题。

插件只负责 snapshot 相关能力，不在第一阶段接管 zpool 创建、vdev 管理、scrub、replace 等完整 ZFS 管理功能。

建议主要支持 **Unraid 7.0+**。Unraid 7 已集成 Dynamix File Manager，因此 filesystem snapshot 可以直接跳到原生文件管理器浏览。Unraid 6.12.x 可作为 best-effort 兼容目标，但需要检测 Dynamix File Manager 是否存在。

---

## 2. ZFS 对象模型

UI 中可以显示：

```text
Pool
└── Dataset
    ├── Filesystem
    ├── Filesystem
    └── ZVOL / Volume
```

需要注意：ZFS 并不存在真正的 “zpool snapshot”。snapshot 的实际对象是 filesystem / volume。

UI 中的 “Snapshot entire pool” 实际转换为：

```bash
zfs snapshot -r pool@snapshot-name
```

也就是对 pool 根 dataset 与其所有 descendants 创建同一时点的 recursive snapshot。

---

# 3. 页面结构

插件建议入口：

```text
Tools
└── ZFS Snapshot Manager
```

进入插件后两个主 Tab：

```text
Snapshots | Schedules
```

暂时不增加独立顶栏一级菜单。

---

# 4. Snapshots 页面

## 4.1 顶部 Pool 概览

每个 pool 显示：

- Pool name
- Health
- Used / Free / Capacity
- Dataset 数
- Snapshot 总数
- Snapshot 占用空间
- 最近一次 snapshot 时间
- 是否存在 active schedule

Pool 本身只作为分组和 recursive snapshot 的操作入口。

---

## 4.2 Dataset Tree

建议以可展开 Tree Table 展示：

| Column | 内容 |
|---|---|
| Dataset | pool/dataset |
| Type | filesystem / volume |
| Mountpoint / Volsize | filesystem 显示 mountpoint；zvol 显示 volsize |
| Snapshots | snapshot 数量 |
| Snapshot Used | usedbysnapshots |
| Latest | 最近 snapshot |
| Schedule | 是否被 schedule 管理 |
| Actions | Create 等操作 |

Pool 根 dataset 也显示为一行，并提供：

```text
Create Recursive Snapshot
```

---

## 4.3 Snapshot 展开列表

点击 dataset 后展开 snapshot：

| Column | 内容 |
|---|---|
| Snapshot | snapshot name |
| Created | creation |
| Used | 当前 snapshot 独占占用 |
| Referenced | referenced |
| Source | Manual / Schedule / External |
| Policy | schedule policy name |
| Hold | Locked / external holds |
| Clone | 是否存在 clone / clone 名称 |
| Actions | Browse / Lock / Clone / Delete / More |

建议读取：

```bash
zfs list -H -p -t snapshot
zfs holds -H <snapshot>
```

同时读取 `clones`、`userrefs`、`defer_destroy` 等属性。

---

# 5. 手动 Snapshot 操作

## 5.1 Create Snapshot

点击 Dataset → Create Snapshot。

弹窗字段：

```text
Dataset:       pool/data
Snapshot Name: manual-2026-08-19_091800
Recursive:     [ ]
Lock after creation: [ ]
```

默认名称：

```text
manual-YYYY-MM-DD_HHMMSS
```

创建时同时设置 metadata：

```text
io.github.atsrxl:managed=1
io.github.atsrxl:source=manual
```

如果是 schedule：

```text
io.github.atsrxl:managed=1
io.github.atsrxl:source=schedule
io.github.atsrxl:policy=<policy-id>
```

这样 snapshot 自己就是 metadata 的 source of truth。

---

## 5.2 Lock / Unlock

“锁定”不要自己维护数据库状态，直接使用 ZFS 原生 hold：

```bash
zfs hold zsm-protect pool/data@snapshot
```

解锁：

```bash
zfs release zsm-protect pool/data@snapshot
```

规则：

- 插件只 release 自己的 `zsm-protect` tag。
- 如果 snapshot 存在其他程序设置的 hold，需要显示为 External Hold。
- 不允许因为用户点击 Unlock 就移除别的 hold。
- retention 遇到任意 hold 都直接跳过该 snapshot。

---

## 5.3 Delete Snapshot

删除必须二次确认。

默认只执行：

```bash
zfs destroy dataset@snapshot
```

**绝不默认附带 `-R`。**

删除前检查：

- 是否有 hold
- 是否存在 clone dependency
- 是否为 deferred destroy
- snapshot reclaimable used space

如果存在 clone，UI 显示：

```text
Cannot delete: snapshot has clone(s)
```

第一版不要自动 destroy clone。

批量删除也使用同一套检查。

---

## 5.4 Browse Snapshot

仅支持 filesystem dataset。

snapshot 文件路径：

```text
<mountpoint>/.zfs/snapshot/<snapshot-name>/
```

跳转到 Unraid 原生浏览器：

```text
/Shares/Browse?dir=<urlencoded snapshot path>
```

例如：

```text
/Shares/Browse?dir=%2Fmnt%2Fzfs%2Fdata%2F.zfs%2Fsnapshot%2Fmanual-2026-08-19_091800
```

注意：

- 不主动修改 dataset 的 `snapdir` 属性。
- `snapdir=hidden` 时 `.zfs/snapshot` 仍可通过明确路径访问；插件直接生成目标路径。
- 如果 mountpoint=none / legacy / dataset 未挂载，则 Browse 按钮禁用并提示原因。
- Unraid File Manager 不可用时显示 fallback 提示，而不是擅自修改 dataset 属性。

### ZVOL

zvol snapshot 没有 `.zfs/snapshot` 文件目录，因此 **不能直接 Browse**。

zvol 行应显示：

```text
Browse: unavailable for ZVOL
```

后续可以提供 Clone，然后让用户通过 block device / iSCSI / mount 等方式访问。

---

## 5.5 Clone Snapshot

建议放入第一版或紧随第一版实现。

命令：

```bash
zfs clone source@snapshot target
```

默认目标名：

```text
pool/data-clone1
pool/data-clone2
...
```

如果用户自定义则使用自定义名称。

UI 必须显示 clone dependency，因为存在 clone 的 snapshot 不能直接删除。

对于 zvol，这个功能尤其重要。

---

## 5.6 Rename Snapshot

建议 P1：

```text
More → Rename
```

用于用户将自动生成的名称改成有意义的 checkpoint 名称。

如果 snapshot 属于 schedule policy，rename 后 metadata 仍保留，因此 retention 不依赖 snapshot name。

---

## 5.7 Rollback

Rollback 属于高风险操作，建议 P1，不要和普通操作并排放置。

入口：

```text
More → Rollback
```

确认窗口显示：

- 当前 dataset
- 目标 snapshot
- 是否存在 newer snapshot
- 是否可能删除 newer snapshot
- clone dependency

需要用户明确确认。

第一阶段不要自动使用 destructive `-R`。

---

## 5.8 Diff

P2：filesystem snapshot 可提供：

```bash
zfs diff snapshot current
```

显示：

- Added
- Removed
- Modified
- Renamed

大型 dataset 上可能耗时，因此必须异步执行并可取消/超时。

---

# 6. Schedules 页面

核心概念不是 “一个全局 cron”，而是 **Snapshot Policy**。

页面：

```text
Schedules

[ + Add Policy ]       [ Pause All ]

Name        Target        Schedule       Keep      Last Run    Status      Actions
Hourly      pool/data     0 * * * *      24        09:00       OK          Run/Edit
Daily       pool/data     0 3 * * *      14        03:00       OK          Run/Edit
VM          pool/vm       */30 * * * *   48        09:00       Failed      Run/Edit
```

---

# 7. Policy 数据结构

建议：

```json
{
  "id": "f4bff7d0-...",
  "name": "Hourly",
  "enabled": true,
  "targets": [
    "pool/data"
  ],
  "recursive": false,
  "cron": "0 * * * *",
  "prefix": "hourly",
  "retention": {
    "keep_last": 24,
    "max_age_days": null
  },
  "notify": "failure"
}
```

配置持久化：

```text
/boot/config/plugins/zsnapshot.manager/policies.json
/boot/config/plugins/zsnapshot.manager/settings.json
```

不要把运行日志频繁写 USB flash。

运行状态：

```text
/tmp/zsnapshot-manager/status.json
/var/log/zsnapshot-manager.log
```

---

# 8. Schedule 编辑器

字段：

### General

```text
Policy Name
Enabled
```

### Target

```text
Dataset / ZVOL selector
Include descendants / Recursive
```

支持多 target。

### Schedule

UI 先提供 preset：

```text
Hourly
Daily
Weekly
Monthly
Custom Cron
```

Custom：

```text
Minute Hour Day Month Weekday
```

时间以 **Unraid Server Timezone** 为准。

### Naming

```text
Prefix: hourly
Result: hourly-2026-08-19_090000
```

### Retention

第一版推荐保持简单：

```text
Keep latest: 24
Delete snapshots older than: optional
```

不要一开始做复杂 GFS 算法。

需要 Hourly / Daily / Weekly / Monthly 分层时，让用户创建多个 policy，这样实现更透明：

```text
Hourly → keep 24
Daily  → keep 14
Weekly → keep 8
Monthly → keep 12
```

后续再加 “GFS preset” 一键生成这些 policy。

---

# 9. Retention / Auto Prune

这是插件必须有的功能，不能只会自动创建 snapshot。

关键规则：

## 只删除自己的 snapshot

候选 snapshot 必须同时满足：

```text
io.github.atsrxl:managed=1
io.github.atsrxl:source=schedule
io.github.atsrxl:policy=<current-policy-id>
```

即使 snapshot name 看起来像：

```text
hourly-xxxx
```

如果 metadata 不匹配，也不能自动删除。

## 永远跳过

- 任何有 hold 的 snapshot
- 有 clone dependency 的 snapshot
- 用户手工 snapshot
- 外部工具创建 snapshot
- metadata 不完整的 snapshot

## 执行顺序

```text
Acquire lock
→ validate target
→ create snapshot
→ verify snapshot exists
→ calculate retention candidates
→ prune eligible snapshots
→ write status/log
→ send notification if needed
→ release lock
```

使用 `flock` 避免两个 policy 同时修改同一个 pool。

---

# 10. Cron 实现

Unraid 的系统文件系统在 RAM 中，因此不能只修改 `/etc/cron.d` 后就认为配置持久化。

建议 policy 保存后生成：

```text
/boot/config/plugins/zsnapshot.manager/zsnapshot-manager.cron
```

内容类似：

```cron
0 * * * * /usr/local/emhttp/plugins/zsnapshot.manager/scripts/run-policy f4bff7d0 >/dev/null 2>&1
0 3 * * * /usr/local/emhttp/plugins/zsnapshot.manager/scripts/run-policy a91101e0 >/dev/null 2>&1
```

然后执行：

```bash
update_cron
```

插件安装 / 开机 / array starting event 时重新生成 cron，避免状态漂移。

Schedules 页面提供：

```text
Run Now
```

Run Now 直接调用同一个 `run-policy`，不能另写第二套 snapshot 逻辑。

---

# 11. 建议目录结构

参考现代 Unraid plugin template，把 WebGUI 与业务逻辑分开：

```text
unriad-zsnapshot-manager/
├── README.md
├── docs/
│   └── PLAN.md
├── plugin/
│   ├── zsnapshot-manager.plg
│   └── plugin.json
├── src/
│   ├── install/
│   └── usr/local/emhttp/plugins/zsnapshot.manager/
│       ├── ZSnapshot.page
│       ├── ZSnapshot-Schedules.page
│       ├── include/
│       │   ├── api.php
│       │   ├── zfs.php
│       │   ├── snapshots.php
│       │   ├── policies.php
│       │   ├── validation.php
│       │   └── notifications.php
│       ├── scripts/
│       │   ├── run-policy
│       │   ├── rebuild-cron
│       │   └── prune
│       ├── event/
│       │   └── starting_array
│       ├── javascript/
│       │   └── zsnapshot.js
│       ├── styles/
│       │   └── zsnapshot.css
│       └── images/
│           └── icon.svg
└── .github/
```

`.page` 只负责页面壳与 include，尽量不要把全部业务代码塞进 `.page`。

---

# 12. Backend API

建议使用单一 API router：

```text
/plugins/zsnapshot.manager/include/api.php
```

动作：

```text
GET  list-datasets
GET  list-snapshots
GET  list-holds
GET  list-policies

POST create-snapshot
POST destroy-snapshot
POST hold-snapshot
POST release-snapshot
POST clone-snapshot
POST rename-snapshot
POST rollback-snapshot
POST save-policy
POST delete-policy
POST run-policy
POST pause-scheduler
```

所有 mutation：

- 必须 POST
- 必须 CSRF
- dataset / snapshot / policy ID 参数校验
- 不接受任意 shell command

---

# 13. 命令安全

这是插件的重点。

禁止：

```php
shell_exec("zfs destroy " . $_POST['snapshot']);
```

必须：

- 对 dataset / snapshot 名称做严格 whitelist validation
- 对传递给 shell 的参数使用安全 escaping
- 使用固定 `/usr/sbin/zfs` / `/usr/sbin/zpool` executable
- command action 与参数分开映射
- 前端传 `action=destroy`，不能传完整 command

破坏性操作前 backend 重新读取 ZFS 当前状态，不能只相信前端缓存。

---

# 14. Snapshot 浏览实现细节

filesystem：

1. `zfs get mountpoint,mounted <dataset>`
2. 确认 filesystem mounted
3. 拼接：

```text
<mountpoint>/.zfs/snapshot/<snap-short-name>
```

4. URL encode
5. 跳转：

```text
/Shares/Browse?dir=...
```

不要提供任意 path 参数让用户直接控制 File Manager 跳转，路径必须由 backend 根据 dataset + snapshot 计算。

---

# 15. Notifications

建议使用 Unraid 原生通知机制。

Policy 可选：

```text
Never
Failure only
Success + Failure
```

失败通知内容：

```text
ZFS Snapshot Policy Failed
Policy: Hourly
Target: pool/data
Error: ...
```

特别通知：

- pool 不存在 / 未 import
- snapshot 创建失败
- cron expression 无效
- retention 删除失败
- pool 空间过低
- clone/hold 导致长期无法 prune

---

# 16. Low Space Guard

这是非常值得加入的功能。

如果 snapshot 一直保留，pool 可以被旧 block 占满。

全局或 policy 设置：

```text
Low space warning: 80%
Critical: 90%
```

达到阈值：

- 黄色 / 红色 UI warning
- 发 Unraid notification
- 先按 retention 删除允许删除的旧 snapshot
- **绝不能删除 held/manual/external snapshot 来救空间**

如果仍然过高，只告警，不越权删除。

---

# 17. Audit / History

Snapshots 页面建议增加 Recent Activity 区域：

```text
09:00 Snapshot created pool/data@hourly-...
09:00 Retention removed pool/data@hourly-...
08:32 Snapshot locked pool/data@before-update
08:12 Delete failed: snapshot has clone
```

日志放 RAM：

```text
/var/log/zsnapshot-manager.log
```

不要因为每次 snapshot 都持续写 boot flash。

---

# 18. 还应该加入的功能

## P0 — 第一版必须

1. Dataset / ZVOL tree
2. Snapshot list
3. Create
4. Delete
5. Hold / Release
6. filesystem snapshot → Unraid File Manager Browse
7. Schedule policy
8. Custom cron
9. Retention keep-last
10. Run Now
11. Scheduled snapshot metadata
12. External snapshot coexistence
13. Clone dependency detection
14. Error log
15. Unraid failure notification
16. Batch select / batch delete / batch lock

## P1 — 很快应该加

1. Clone snapshot
2. Rename snapshot
3. Safe Rollback
4. Low-space guard
5. Policy import/export
6. Search / filter snapshot
7. Next run / last run 状态
8. Schedule global pause
9. Age-based retention
10. GFS preset（自动生成 hourly/daily/weekly/monthly policies）

## P2 — 高级功能

1. `zfs diff`
2. Pre/Post snapshot hooks
3. VM / database quiesce hooks
4. Snapshot bookmark
5. zfs send / receive replication
6. Remote replication status
7. Temporary zvol clone/mount helper
8. Snapshot restore wizard

---

# 19. 特别建议：Pre/Post Hooks

对普通文件 share，snapshot 是 filesystem-consistent 的。

但对于：

- iSCSI zvol
- VM disk
- database
- 正在大量写入的应用

ZFS snapshot 本身不等于 application-consistent backup。

未来 policy 可以增加：

```text
Pre Snapshot Script
Post Snapshot Script
```

例如：

```text
freeze application
→ snapshot
→ thaw application
```

第一版暂时不要让 GUI 接收任意 shell 文本；后续可以只允许从固定 hook 目录选择管理员预先创建的脚本。

---

# 20. 明确不做的事情（第一阶段）

避免项目失控：

- Create / destroy zpool
- vdev add/remove/replace
- Scrub manager
- ZFS property 全功能编辑器
- Encryption key manager
- SMB/NFS share manager
- iSCSI target manager

这些可以由 Unraid 本身或其他插件负责。

---

# 21. MVP 开发顺序

## Milestone 1 — Read-only UI

```text
Detect ZFS
→ list pools
→ list filesystem/zvol
→ list snapshots
→ display hold/clone/space metadata
→ Browse filesystem snapshot
```

## Milestone 2 — Manual operations

```text
Create
→ Delete
→ Lock / Unlock
→ Batch operations
→ Activity log
```

## Milestone 3 — Scheduler

```text
policies.json
→ schedule editor
→ generate .cron
→ update_cron
→ run-policy
→ Run Now
```

## Milestone 4 — Retention

```text
snapshot metadata
→ keep-last
→ skip hold / clone
→ auto prune
→ notifications
```

## Milestone 5 — Advanced operations

```text
Clone
→ Rename
→ Safe Rollback
→ low-space guard
```

---

# 22. 项目命名

当前 repository 名为：

```text
unriad-zsnapshot-manager
```

其中 `unriad` 看起来是 `unraid` 的拼写错误。

正式发布前建议改为：

```text
unraid-zsnapshot-manager
```

插件内部建议统一使用：

```text
Display Name: ZFS Snapshot Manager
Plugin ID: zsnapshot.manager
Config Path: /boot/config/plugins/zsnapshot.manager/
Runtime Path: /usr/local/emhttp/plugins/zsnapshot.manager/
```
