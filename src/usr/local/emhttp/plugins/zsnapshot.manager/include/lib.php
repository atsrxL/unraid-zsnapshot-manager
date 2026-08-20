<?php

declare(strict_types=1);

const ZSM_CONFIG_DIR = '/boot/config/plugins/zsnapshot.manager';
const ZSM_SCHEDULES = ZSM_CONFIG_DIR . '/schedules.json';
const ZSM_LOG = '/var/log/zsnapshot-manager.log';
const ZSM_HOLD_TAG = 'zsm-protect';
const ZSM_MANAGED_PROP = 'io.github.atsrxl:managed';
const ZSM_SOURCE_PROP = 'io.github.atsrxl:source';
const ZSM_POLICY_PROP = 'io.github.atsrxl:policy';
const ZSM_MANAGER_SCRIPT = '/usr/local/emhttp/plugins/zsnapshot.manager/scripts/zsm-manager.sh';
const ZSM_SCHEDULER_SCRIPT = '/usr/local/emhttp/plugins/zsnapshot.manager/scripts/zsm-scheduler';

function zsm_init(): void
{
    if (!is_dir(ZSM_CONFIG_DIR)) @mkdir(ZSM_CONFIG_DIR, 0755, true);
    if (!file_exists(ZSM_SCHEDULES)) @file_put_contents(ZSM_SCHEDULES, "[]\n");
}

function zsm_log(string $message): void
{
    @file_put_contents(ZSM_LOG, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

function zsm_zfs_bin(): string
{
    static $bin = null;
    if ($bin !== null) return $bin;
    foreach (['/sbin/zfs', '/usr/sbin/zfs', '/usr/local/sbin/zfs'] as $candidate) {
        if (is_executable($candidate)) return $bin = $candidate;
    }
    return $bin = 'zfs';
}

function zsm_exec(array $args, ?int &$code = null): string
{
    $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    $code = $rc;
    return trim(implode("\n", $out));
}

function zsm_exec_timeout(array $args, int $seconds, ?int &$code = null): string
{
    $seconds = max(1, min(600, $seconds));
    $timeout = null;
    foreach (['/usr/bin/timeout', '/bin/timeout'] as $candidate) {
        if (is_executable($candidate)) {
            $timeout = $candidate;
            break;
        }
    }
    if ($timeout !== null) {
        array_unshift($args, $seconds . 's');
        array_unshift($args, $timeout);
    }
    $out = zsm_exec($args, $rc);
    $code = $rc;
    if ($timeout !== null && $rc === 124) return 'Operation timed out after ' . $seconds . ' seconds';
    return $out;
}

function zsm_run(array $args, int $timeoutSeconds = 30): array
{
    if (!is_file(ZSM_MANAGER_SCRIPT)) return [127, 'ZFS Snapshot Manager backend script is missing'];
    $cmd = array_merge(['bash', ZSM_MANAGER_SCRIPT], array_map('strval', $args));
    $out = zsm_exec_timeout($cmd, $timeoutSeconds, $rc);
    return [$rc, $out];
}

function zsm_valid_dataset(string $name): bool
{
    return $name !== '' && !str_contains($name, '@') && preg_match('/^[A-Za-z0-9_.:\/-]+$/', $name) === 1;
}

function zsm_valid_snapshot_name(string $name): bool
{
    return $name !== '' && !str_contains($name, '@') && preg_match('/^[A-Za-z0-9_.:\-]+$/', $name) === 1;
}

function zsm_valid_snapshot(string $name): bool
{
    if (substr_count($name, '@') !== 1) return false;
    [$dataset, $snap] = explode('@', $name, 2);
    return zsm_valid_dataset($dataset) && zsm_valid_snapshot_name($snap);
}

function zsm_datasets(): array
{
    $out = zsm_exec([zsm_zfs_bin(), 'list', '-H', '-p', '-o', 'name,type,mountpoint,used,available', '-t', 'filesystem,volume'], $rc);
    if ($rc !== 0 || $out === '') return [];
    $rows = [];
    foreach (explode("\n", $out) as $line) {
        $p = explode("\t", $line);
        if (count($p) < 5) continue;
        $rows[] = [
            'name' => $p[0],
            'type' => $p[1],
            'mountpoint' => $p[2],
            'used' => (int)$p[3],
            'available' => (int)$p[4],
        ];
    }
    return $rows;
}

function zsm_snapshots(): array
{
    $properties = implode(',', [
        'name', 'creation', 'used', 'refer', 'userrefs',
        ZSM_MANAGED_PROP, ZSM_SOURCE_PROP, ZSM_POLICY_PROP
    ]);
    $out = zsm_exec([zsm_zfs_bin(), 'list', '-H', '-p', '-t', 'snapshot', '-o', $properties, '-s', 'creation'], $rc);
    if ($rc !== 0 || $out === '') return [];

    $rows = [];
    foreach (explode("\n", $out) as $line) {
        $p = explode("\t", $line);
        if (count($p) < 8 || !zsm_valid_snapshot($p[0])) continue;
        [$dataset, $snap] = explode('@', $p[0], 2);
        $userrefs = (int)$p[4];
        $pluginHeld = $userrefs > 0 ? zsm_is_held($p[0]) : false;
        $rows[] = [
            'name' => $p[0],
            'dataset' => $dataset,
            'snap' => $snap,
            'creation' => (int)$p[1],
            'used' => (int)$p[2],
            'refer' => (int)$p[3],
            'userrefs' => $userrefs,
            'held' => $pluginHeld,
            'any_held' => $userrefs > 0,
            'managed' => $p[5] === '1',
            'source' => $p[6] === '-' ? '' : $p[6],
            'policy' => $p[7] === '-' ? '' : $p[7],
        ];
    }
    return $rows;
}

function zsm_get_prop(string $target, string $prop): string
{
    $out = zsm_exec([zsm_zfs_bin(), 'get', '-H', '-o', 'value', $prop, $target], $rc);
    return $rc === 0 ? trim($out) : '-';
}

function zsm_recursive_generation_rows(string $dataset, string $snap): array
{
    $properties = implode(',', ['name', 'userrefs', ZSM_MANAGED_PROP, ZSM_POLICY_PROP]);
    $out = zsm_exec([zsm_zfs_bin(), 'list', '-H', '-p', '-t', 'snapshot', '-o', $properties, '-r', $dataset], $rc);
    if ($rc !== 0 || $out === '') return [];
    $suffix = '@' . $snap;
    $rows = [];
    foreach (explode("\n", $out) as $line) {
        $p = explode("\t", $line);
        if (count($p) < 4) continue;
        $name = trim($p[0]);
        if (!str_ends_with($name, $suffix) || !zsm_valid_snapshot($name)) continue;
        $rows[] = [
            'name' => $name,
            'userrefs' => (int)$p[1],
            'managed' => $p[2] === '1',
            'policy' => $p[3] === '-' ? '' : $p[3],
        ];
    }
    return $rows;
}

function zsm_recursive_generation(string $dataset, string $snap): array
{
    return array_column(zsm_recursive_generation_rows($dataset, $snap), 'name');
}

function zsm_is_held(string $snapshot): bool
{
    $out = zsm_exec([zsm_zfs_bin(), 'holds', '-H', $snapshot], $rc);
    if ($rc !== 0 || $out === '') return false;
    foreach (explode("\n", $out) as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (($p[1] ?? '') === ZSM_HOLD_TAG) return true;
    }
    return false;
}

function zsm_create_snapshot(string $dataset, string $snap, bool $recursive = false, string $source = 'manual', string $policy = ''): array
{
    if (!zsm_valid_dataset($dataset) || ($snap !== '' && !zsm_valid_snapshot_name($snap))) {
        return [false, 'Invalid dataset or snapshot name'];
    }
    [$rc, $out] = zsm_run([
        'create-snapshot', $dataset, $snap, $recursive ? '1' : '0', $source, $policy
    ], 30);
    if ($rc !== 0) return [false, preg_replace('/^ERROR:\s*/m', '', $out ?: 'zfs snapshot failed')];
    $created = preg_replace('/^Created\s+/', '', trim($out));
    zsm_log('CREATE ' . ($created ?: ($dataset . '@' . $snap)) . ($recursive ? ' recursive' : ''));
    return [true, $created ?: ($dataset . '@' . $snap)];
}

function zsm_delete_snapshot(string $snapshot): array
{
    if (!zsm_valid_snapshot($snapshot)) return [false, 'Invalid snapshot'];
    [$rc, $out] = zsm_run(['delete-snapshot', $snapshot], 30);
    if ($rc !== 0) return [false, preg_replace('/^ERROR:\s*/m', '', $out ?: 'zfs destroy failed')];
    zsm_log('DELETE ' . $snapshot);
    return [true, $snapshot];
}

function zsm_delete_managed_generation(string $dataset, string $snap, string $policy): array
{
    $generation = zsm_recursive_generation_rows($dataset, $snap);
    $managed = [];
    foreach ($generation as $row) {
        if (!$row['managed'] || $row['policy'] !== $policy) continue;
        if ($row['userrefs'] > 0) {
            if (zsm_is_held($row['name'])) return [false, 'Generation contains protected snapshot: ' . $row['name']];
            return [false, 'Generation contains a snapshot held by another tool: ' . $row['name']];
        }
        $managed[] = $row['name'];
    }
    if (!$managed) return [false, 'No matching managed snapshots found in generation'];
    usort($managed, fn($a, $b) => substr_count($b, '/') <=> substr_count($a, '/'));
    foreach ($managed as $item) {
        [$ok, $msg] = zsm_delete_snapshot($item);
        if (!$ok) return [false, $msg];
    }
    return [true, $dataset . '@' . $snap];
}

function zsm_hold(string $snapshot, bool $enable): array
{
    if (!zsm_valid_snapshot($snapshot)) return [false, 'Invalid snapshot'];
    [$rc, $out] = zsm_run([$enable ? 'hold' : 'release', $snapshot], 20);
    if ($rc !== 0) return [false, preg_replace('/^ERROR:\s*/m', '', $out ?: 'hold/release failed')];
    zsm_log(($enable ? 'HOLD ' : 'RELEASE ') . $snapshot);
    return [true, $snapshot];
}

function zsm_clone(string $snapshot, string $clone): array
{
    if (!zsm_valid_snapshot($snapshot) || !zsm_valid_dataset($clone)) return [false, 'Invalid snapshot or clone name'];
    [$rc, $out] = zsm_run(['clone-snapshot', $snapshot, $clone], 30);
    if ($rc !== 0) return [false, preg_replace('/^ERROR:\s*/m', '', $out ?: 'zfs clone failed')];
    zsm_log('CLONE ' . $snapshot . ' -> ' . $clone);
    return [true, $clone];
}

function zsm_safe_rollback(string $snapshot): array
{
    if (!zsm_valid_snapshot($snapshot)) return [false, 'Invalid snapshot'];
    [$rc, $out] = zsm_run(['rollback', $snapshot], 30);
    if ($rc !== 0) return [false, preg_replace('/^ERROR:\s*/m', '', $out ?: 'zfs rollback failed')];
    zsm_log('ROLLBACK ' . $snapshot);
    return [true, $snapshot];
}

function zsm_browse_url(string $snapshot, ?array $datasetInfo = null): ?string
{
    if (!zsm_valid_snapshot($snapshot)) return null;
    [$dataset, $snap] = explode('@', $snapshot, 2);
    $type = (string)($datasetInfo['type'] ?? '');
    $mountpoint = (string)($datasetInfo['mountpoint'] ?? '');
    if ($type === '') $type = zsm_get_prop($dataset, 'type');
    if ($mountpoint === '') $mountpoint = zsm_get_prop($dataset, 'mountpoint');
    if ($type === 'volume') return null;
    if ($mountpoint === '-' || $mountpoint === 'none' || $mountpoint === 'legacy' || $mountpoint === '') return null;
    return '/Shares/Browse?dir=' . rawurlencode(rtrim($mountpoint, '/') . '/.zfs/snapshot/' . $snap);
}

function zsm_load_schedules(): array
{
    zsm_init();
    $data = json_decode((string)@file_get_contents(ZSM_SCHEDULES), true);
    return is_array($data) ? array_values($data) : [];
}

function zsm_save_schedules(array $rows): bool
{
    zsm_init();
    $ok = @file_put_contents(
        ZSM_SCHEDULES,
        json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    ) !== false;
    if ($ok) zsm_regenerate_cron($rows);
    return $ok;
}

function zsm_valid_cron(string $cron): bool
{
    return preg_match('/^[0-9*\/,-]+\s+[0-9*\/,-]+\s+[0-9*\/,-]+\s+[0-9*\/,-]+\s+[0-9*\/,-]+$/', trim($cron)) === 1;
}

function zsm_regenerate_cron(?array $rows = null): void
{
    $rows ??= zsm_load_schedules();
    $cronFile = ZSM_CONFIG_DIR . '/zsnapshot-manager.cron';
    $lines = ['# Generated by ZFS Snapshot Manager'];
    foreach ($rows as $row) {
        if (empty($row['enabled']) || empty($row['id']) || !zsm_valid_cron((string)($row['cron'] ?? ''))) continue;
        $id = preg_replace('/[^a-f0-9-]/i', '', (string)$row['id']);
        $lines[] = trim((string)$row['cron']) . ' ' . ZSM_SCHEDULER_SCRIPT . ' ' . escapeshellarg($id) . ' >/dev/null 2>&1';
    }
    @file_put_contents($cronFile, implode("\n", $lines) . "\n", LOCK_EX);
    @shell_exec('/usr/local/sbin/update_cron >/dev/null 2>&1');
}

function zsm_retention_counts(array $retention): array
{
    return [
        'hourly' => max(0, min(10000, (int)($retention['hourly'] ?? 0))),
        'daily' => max(0, min(10000, (int)($retention['daily'] ?? 0))),
        'monthly' => max(0, min(10000, (int)($retention['monthly'] ?? 0))),
        'yearly' => max(0, min(10000, (int)($retention['yearly'] ?? 0))),
    ];
}

function zsm_smart_keep_set(array $snapshots, array $retention): array
{
    usort($snapshots, fn($a, $b) => ((int)($b['creation'] ?? 0)) <=> ((int)($a['creation'] ?? 0)));
    $counts = zsm_retention_counts($retention);
    $formats = ['hourly'=>'Y-m-d-H', 'daily'=>'Y-m-d', 'monthly'=>'Y-m', 'yearly'=>'Y'];
    $keep = [];
    foreach ($formats as $tier => $format) {
        $limit = $counts[$tier];
        if ($limit <= 0) continue;
        $seen = [];
        foreach ($snapshots as $row) {
            $full = (string)($row['full'] ?? '');
            $creation = (int)($row['creation'] ?? 0);
            if ($full === '' || $creation <= 0) continue;
            $bucket = date($format, $creation);
            if (isset($seen[$bucket])) continue;
            if (count($seen) >= $limit) break;
            $seen[$bucket] = true;
            $keep[$full] = true;
        }
    }
    if (!$keep && !empty($snapshots)) {
        $full = (string)($snapshots[0]['full'] ?? '');
        if ($full !== '') $keep[$full] = true;
    }
    return $keep;
}

function zsm_notify(string $subject, string $description, string $importance = 'normal'): void
{
    $notify = '/usr/local/emhttp/webGui/scripts/notify';
    if (!is_executable($notify)) return;
    zsm_exec([$notify, '-e', 'ZFS Snapshot Manager', '-s', $subject, '-d', $description, '-i', $importance], $rc);
}

function zsm_csrf_token(): string
{
    global $var;
    if (isset($var) && is_array($var) && !empty($var['csrf_token'])) return (string)$var['csrf_token'];
    $v = @parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW);
    return is_array($v) ? (string)($v['csrf_token'] ?? '') : '';
}

function zsm_csrf_valid(): bool
{
    $expected = zsm_csrf_token();
    $received = (string)($_POST['csrf_token'] ?? '');
    return $expected !== '' && $received !== '' && hash_equals($expected, $received);
}

zsm_init();
