# Unraid ZFS Snapshot Manager

面向 Unraid WebGUI 的原生 ZFS Snapshot 管理插件。

> 当前阶段：架构与功能规划。

## 目标

让 Unraid 用户不进入终端，也能完成 ZFS snapshot 的日常管理：

- 查看所有 zpool 下的 filesystem dataset 与 zvol
- 手动创建 snapshot
- 删除 snapshot
- 锁定 / 解锁 snapshot（ZFS hold / release）
- 浏览 filesystem snapshot 内容，并跳转到 Unraid 自带 File Manager
- Clone snapshot
- 安全 Rollback
- 配置定时 snapshot
- 自动保留 / 清理旧 snapshot
- 查看任务历史、失败原因并接入 Unraid 通知

## 页面规划

插件入口建议放在 **Tools → ZFS Snapshot Manager**，进入后保留两个主 Tab：

1. **Snapshots**：手动管理现有 dataset / zvol 与 snapshot
2. **Schedules**：配置自动 snapshot、Cron 与 retention

不单独增加顶栏一级菜单，避免侵入 Unraid 原生导航。

## 基本原则

- zpool 作为 UI 分组；实际 snapshot 对象是 ZFS filesystem / volume(dataset/zvol)。
- “整池快照”实际执行根 dataset 的 recursive snapshot。
- 删除绝不默认使用 `zfs destroy -R`。
- “锁定”直接使用原生 `zfs hold`，不自建伪锁。
- 自动清理只处理由本插件创建并带有对应 policy metadata 的 snapshot。
- 外部工具创建的 snapshot 默认只展示，不自动清理。
- zvol snapshot 不提供“文件浏览”；需要文件级查看时应 clone 后由用户自行挂载/使用。
- 所有破坏性操作使用 POST + CSRF，并进行二次确认与参数校验。

## Snapshot metadata

计划使用 ZFS user properties 标记本插件创建的 snapshot，例如：

```text
io.github.atsrxl:managed=1
io.github.atsrxl:source=manual|schedule
io.github.atsrxl:policy=<policy-id>
```

保护 snapshot 使用固定 hold tag：

```text
zsm-protect
```

因此即使插件配置文件丢失，也能从 ZFS snapshot 本身恢复归属关系。

## 文档

详细功能、UI、数据结构、Cron、目录结构和版本路线见：

- [docs/PLAN.md](docs/PLAN.md)
