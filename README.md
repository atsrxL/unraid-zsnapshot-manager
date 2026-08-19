# Unraid ZFS Snapshot Manager

面向 Unraid WebGUI 的原生 ZFS Snapshot 管理插件。

> 当前阶段：`2026.08.19a` 测试版。

## 已实现

- 查看所有 ZFS filesystem dataset 与 zvol
- 手动创建 snapshot，可选 recursive
- 删除 snapshot（不使用危险的 `destroy -R`）
- 锁定 / 解锁 snapshot（ZFS hold / release）
- filesystem snapshot 直接跳转到 Unraid File Manager 浏览
- Clone snapshot
- 安全 Rollback：测试版仅允许回滚到当前 dataset 最新 snapshot
- Snapshot Policy / Cron 定时任务
- Run Now
- Retention 自动清理
- Recursive snapshot generation-safe retention
- Unraid 通知
- Activity log
- CSRF 校验与参数白名单

## 安装

Unraid WebGUI → **Plugins → Install Plugin**：

```text
https://raw.githubusercontent.com/atsrxL/unraid-zsnapshot-manager/main/plugin/zsnapshot.manager.plg
```

> 注意：GitHub 仓库必须允许匿名读取 raw 文件。仓库为 Private 时，Unraid Plugin Manager 无法使用上面的裸 URL 下载插件和源码；请在公开仓库后使用该地址，或手动下载 PLG 后进行本地测试。

安装后入口：

- **Tools → ZFS Snapshot Manager**
- **Tools → ZFS Snapshot Schedules**

## 页面

### Snapshots

按 dataset / zvol 展示 snapshot，支持：Create、Delete、Lock/Unlock、Browse、Clone、Rollback。

### Schedules

每条 Policy 包含：

- Target dataset / zvol
- Cron（5-field）
- Recursive
- Keep latest N
- Failure only / Success + failure notification
- Enable / Disable
- Run Now

Policy 持久化在：

```text
/boot/config/plugins/zsnapshot.manager/schedules.json
```

Cron 由插件生成：

```text
/boot/config/plugins/zsnapshot.manager/zsnapshot-manager.cron
```

## Snapshot metadata

插件创建的 snapshot 使用 ZFS user properties 标记：

```text
io.github.atsrxl:managed=1
io.github.atsrxl:source=manual|schedule
io.github.atsrxl:policy=<policy-id>
```

保护 snapshot 使用 hold tag：

```text
zsm-protect
```

Retention **只处理 `managed=1` 且 policy ID 与当前 Policy 完全匹配的 snapshot**。外部工具和手工创建的未托管 snapshot 不会被自动清理；有 hold 的 snapshot 也不会被清理。

## 安全原则

- zpool 仅作为 UI 分组；实际 snapshot 对象是 filesystem / volume dataset。
- “整池快照”通过根 dataset 的 recursive snapshot 实现。
- 普通删除不调用 `zfs destroy -R`。
- recursive retention 会按同一 snapshot generation 验证 metadata 和 hold，再从最深层 dataset 向上清理。
- Rollback 第一版只允许 newest snapshot，避免隐式删除较新的 snapshot。
- zvol snapshot 不提供文件浏览；需要查看内容时通过 clone 后自行挂载/使用。
- 所有 WebGUI POST 操作包含 Unraid CSRF token 校验。

## 日志

运行日志：

```text
/var/log/zsnapshot-manager.log
```

避免持续写入 Unraid 启动 U 盘。

## 文档

完整规划见：

- [docs/PLAN.md](docs/PLAN.md)
