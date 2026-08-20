<?php

declare(strict_types=1);

const ZSM_CONFIG_DIR = '/boot/config/plugins/zsnapshot.manager';
const ZSM_SCHEDULES = ZSM_CONFIG_DIR . '/schedules.json';
const ZSM_LOG = '/var/log/zsnapshot-manager.log';
const ZSM_HOLD_TAG = 'zsm-protect';
const ZSM_MANAGED_PROP = 'io.github.atsrxl:managed';
const ZSM_SOURCE_PROP = 'io.github.atsrxl:source';
const ZSM_POLICY_PROP = 'io.github.atsrxl:policy';

function zsm_init(): void
{
    if (!is_dir(ZSM_CONFIG_DIR)) @mkdir(ZSM_CONFIG_DIR, 0755, true);
    if (!file_exists(ZSM_SCHEDULES)) @file_put_contents(ZSM_SCHEDULES, "[]\n");
}

function zsm_log(string $message): void
{
    @file_put_contents(ZSM_LOG, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
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

function zsm_valid_dataset(string $name): bool
{
    return $name !== '' && !str_contains($name, '@') && preg_match('/^[A-Za-z0-9_.:\\/-]+$/', $name) === 1;
}

function zsm_valid_snapshot_name(string $name): bool
{
    return $name !== '' && !str_contains($name, '@') && preg_match('/^[A-Za-z0-9_.:\\-]+$/', $name) === 1;
}

function zsm_valid_snapshot(string $name): bool
{
    if (substr_count($name, '@') !== 1) return false;
    [$dataset, $snap] = explode('@', $name, 2);
    return zsm_valid_dataset($dataset) && zsm_valid_snapshot_name($snap);
}

function zsm_datasets(): array
{
    $out = zsm_exec(['/sbin/zfs', 'list', '-H', '-p', '-o', 'name,type,mountpoint,used,available', '-t', 'filesystem,volume'], $rc);
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
    $out = zsm_exec(['/sbin/zfs', 'list', '-H', '-p', '-t', 'snapshot', '-o', 'name,creation,used,refer', '-s', 'creation'], $rc);
    if ($rc !== 0 || $out === '') return [];

    $rows = [];
    foreach (explode("\n", $out) as $line) {
        $p = explode("\t", $line);
        if (count($p) < 4 || !zsm_valid_snapshot($p[0])) continue;
        [$dataset, $snap] = explode('@', $p[0], 2);
        $managed = zsm_get_prop($p[0], ZSM_MANAGED_PROP);
        $source = zsm_get_prop($p[0], ZSM_SOURCE_PROP);
        $policy = zsm_get_prop($p[0], ZSM_POLICY_PROP);
        $rows[] = [
            'name' => $p[0],
            'dataset' => $dataset,
            'snap' => $snap,
            'creation' => (int)$p[1],
            'used' => (int)$p[2],
            'refer' => (int)$p[3],
            'held' => zsm_is_held($p[0]),
            'managed' => $managed === '1',
            'source' => $source === '-' ? '' : $source,
            'policy' => $policy === '-' ? '' : $policy,
        ];
    }
    return $rows;
}

function zsm_get_prop(string $target, string $prop): string
{
    $out = zsm_exec(['/sbin/zfs', 'get', '-H', '-o', 'value', $prop, $target], $rc);
    return $rc === 0 ? trim($out) : '-';
}

function zsm_set_metadata(string $snapshot, string $source, string $policy = ''): void
{
    zsm_exec(['/sbin/zfs', 'set', ZSM_MANAGED_PROP . '=1', $snapshot], $r1);
    zsm_exec(['/sbin/zfs', 'set', ZSM_SOURCE_PROP . '=' . $source, $snapshot], $r2);
    if ($policy !== '') zsm_exec(['/sbin/zfs', 'set', ZSM_POLICY_PROP . '=' . $policy, $snapshot], $r3);

    if (($r1 ?? 1) !== 0 || ($r2 ?? 1) !== 0 || ($policy !== '' && ($r3 ?? 1) !== 0)) {
        zsm_log('WARN metadata could not be fully written for ' . $snapshot);
    }
}

function zsm_recursive_generation(string $dataset, string $snap): array
{
    $out = zsm_exec(['/sbin/zfs', 'list', '-H', '-t', 'snapshot', '-o', 'name', '-r', $dataset], $rc);
    if ($rc !== 0 || $out === '') return [];

    $suffix = '@' . $snap;
    $rows = [];
    foreach (explode("\n", $out) as $name) {
        $name = trim($name);
        if (str_ends_with($name, $suffix) && zsm_valid_snapshot($name)) $rows[] = $name;
    }
    return $rows;
}

function zsm_is_held(string $snapshot): bool
{
    $out = zsm_exec(['/sbin/zfs', 'holds', '-H', $snapshot], $rc);
    if ($rc !== 0 || $out === '') return false;

    foreach (explode("\n", $out) as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (($p[1] ?? '') === ZSM_HOLD_TAG) return true;
    }
    return false;
}

function zsm_create_snapshot(string $dataset, string $snap, bool $recursive = false, string $source = 'manual', string $policy = ''): array
{
    if (!zsm_valid_dataset($dataset) || !zsm_valid_snapshot_name($snap)) {
        return [false, 'Invalid dataset or snapshot name'];
    }

    $full = $dataset . '@' . $snap;
    $args = ['/sbin/zfs', 'snapshot'];
    if ($recursive) $args[] = '-r';
    $args[] = $full;

    $out = zsm_exec($args, $rc);
    if ($rc !== 0) return [false, $out ?: 'zfs snapshot failed'];

    if ($recursive) {
        foreach (zsm_recursive_generation($dataset, $snap) as $created) {
            zsm_set_metadata($created, $source, $policy);
        }
    } else {
        zsm_set_metadata($full, $source, $policy);
    }

    zsm_log("CREATE $full" . ($recursive ? ' recursive' : ''));
    return [true, $full];
}

function zsm_delete_snapshot(string $snapshot): array
{
    if (!zsm_valid_snapshot($snapshot)) return [false, 'Invalid snapshot'];
    if (zsm_is_held($snapshot)) return [false, 'Snapshot is protected by hold'];

    $out = zsm_exec(['/sbin/zfs', 'destroy', $snapshot], $rc);
    if ($rc !== 0) return [false, $out ?: 'zfs destroy failed'];

    zsm_log("DELETE $snapshot");
    return [true, $snapshot];
}

function zsm_delete_managed_generation(string $dataset, string $snap, string $policy): array
{
    $generation = zsm_recursive_generation($dataset, $snap);
    $managed = [];

    foreach ($generation as $item) {
        if (zsm_get_prop($item, ZSM_MANAGED_PROP) !== '1') continue;
        if (zsm_get_prop($item, ZSM_POLICY_PROP) !== $policy) continue;
        if (zsm_is_held($item)) return [false, 'Generation contains protected snapshot: ' . $item];
        $managed[] = $item;
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

    $args = $enable
        ? ['/sbin/zfs', 'hold', ZSM_HOLD_TAG, $snapshot]
        : ['/sbin/zfs', 'release', ZSM_HOLD_TAG, $snapshot];
    $out = zsm_exec($args, $rc);
    if ($rc !== 0) return [false, $out ?: 'hold/release failed'];

    zsm_log(($enable ? 'HOLD ' : 'RELEASE ') . $snapshot);
    return [true, $snapshot];
}

function zsm_clone(string $snapshot, string $clone): array
{
    if (!zsm_valid_snapshot($snapshot) || !zsm_valid_dataset($clone)) return [false, 'Invalid snapshot or clone name'];

    $out = zsm_exec(['/sbin/zfs', 'clone', $snapshot, $clone], $rc);
    if ($rc !== 0) return [false, $out ?: 'zfs clone failed'];

    zsm_log("CLONE $snapshot -> $clone");
    return [true, $clone];
}

function zsm_safe_rollback(string $snapshot): array
{
    if (!zsm_valid_snapshot($snapshot)) return [false, 'Invalid snapshot'];
    [$dataset] = explode('@', $snapshot, 2);

    $out = zsm_exec(['/sbin/zfs', 'list', '-H', '-p', '-t', 'snapshot', '-o', 'name', '-S', 'creation', '-d', '1', $dataset], $rc);
    if ($rc !== 0) return [false, $out ?: 'Unable to inspect snapshots'];

    $newest = trim(explode("\n", $out)[0] ?? '');
    if ($newest !== $snapshot) return [false, 'Safety guard: rollback is only allowed to the newest snapshot'];

    $out = zsm_exec(['/sbin/zfs', 'rollback', $snapshot], $rc);
    if ($rc !== 0) return [false, $out ?: 'zfs rollback failed'];

    zsm_log("ROLLBACK $snapshot");
    return [true, $snapshot];
}

function zsm_browse_url(string $snapshot): ?string
{
    if (!zsm_valid_snapshot($snapshot)) return null;
    [$dataset, $snap] = explode('@', $snapshot, 2);

    if (zsm_get_prop($dataset, 'type') === 'volume') return null;
    $mountpoint = zsm_get_prop($dataset, 'mountpoint');
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
        $lines[] = trim((string)$row['cron']) . ' /usr/local/emhttp/plugins/zsnapshot.manager/scripts/zsm-scheduler ' . escapeshellarg($id) . ' >/dev/null 2>&1';
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
    $formats = [
        'hourly' => 'Y-m-d-H',
        'daily' => 'Y-m-d',
        'monthly' => 'Y-m',
        'yearly' => 'Y',
    ];
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
    $v = @parse_ini_file('/var/local/emhttp/var.ini');
    return is_array($v) ? (string)($v['csrf_token'] ?? '') : '';
}

function zsm_csrf_guard(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $expected = zsm_csrf_token();
    $received = (string)($_POST['csrf_token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $received)) {
        http_response_code(403);
        die('Security token validation failed. Refresh the page and try again.');
    }
}

zsm_init();
if (PHP_SAPI !== 'cli') zsm_csrf_guard();
