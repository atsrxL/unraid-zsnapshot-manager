<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/include/lib.php';

function zsm_schedule_json(bool $ok, string $message, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') zsm_schedule_json(false, 'POST required', 405);
if (!zsm_csrf_valid()) zsm_schedule_json(false, 'Security token validation failed. Refresh the page and try again.', 403);

try {
    $action = (string)($_POST['action'] ?? '');
    $schedules = zsm_load_schedules();

    if ($action === 'add') {
        $target = trim((string)($_POST['target'] ?? ''));
        $cron = trim((string)($_POST['cron'] ?? ''));
        $retention = zsm_retention_counts([
            'hourly' => (int)($_POST['retention_hourly'] ?? 24),
            'daily' => (int)($_POST['retention_daily'] ?? 7),
            'monthly' => (int)($_POST['retention_monthly'] ?? 12),
            'yearly' => (int)($_POST['retention_yearly'] ?? 3),
        ]);
        if (!zsm_valid_dataset($target)) zsm_schedule_json(false, 'Invalid target dataset/zvol', 400);
        if (!zsm_valid_cron($cron)) zsm_schedule_json(false, 'Invalid cron expression (5 fields required)', 400);

        $id = bin2hex(random_bytes(8));
        $schedules[] = [
            'id' => $id,
            'name' => trim((string)($_POST['name'] ?? 'Policy')) ?: 'Policy',
            'target' => $target,
            'cron' => $cron,
            'retention_mode' => 'smart',
            'retention' => $retention,
            'recursive' => !empty($_POST['recursive']),
            'enabled' => true,
            'notify' => (($_POST['notify'] ?? 'failure') === 'always' ? 'always' : 'failure'),
        ];
        if (!zsm_save_schedules($schedules)) zsm_schedule_json(false, 'Failed to save schedules', 500);
        zsm_schedule_json(true, 'Schedule created');
    }

    if ($action === 'delete') {
        $id = (string)($_POST['id'] ?? '');
        $before = count($schedules);
        $schedules = array_values(array_filter($schedules, fn($r) => ($r['id'] ?? '') !== $id));
        if (count($schedules) === $before) zsm_schedule_json(false, 'Schedule not found', 404);
        if (!zsm_save_schedules($schedules)) zsm_schedule_json(false, 'Failed to save schedules', 500);
        zsm_schedule_json(true, 'Schedule deleted');
    }

    if ($action === 'toggle') {
        $id = (string)($_POST['id'] ?? '');
        $found = false;
        foreach ($schedules as &$row) {
            if (($row['id'] ?? '') !== $id) continue;
            $row['enabled'] = empty($row['enabled']);
            $found = true;
            break;
        }
        unset($row);
        if (!$found) zsm_schedule_json(false, 'Schedule not found', 404);
        if (!zsm_save_schedules($schedules)) zsm_schedule_json(false, 'Failed to save schedules', 500);
        zsm_schedule_json(true, 'Schedule updated');
    }

    if ($action === 'run') {
        $id = preg_replace('/[^a-f0-9]/i', '', (string)($_POST['id'] ?? ''));
        if ($id === '') zsm_schedule_json(false, 'Invalid policy id', 400);
        $found = false;
        foreach ($schedules as $row) {
            if (($row['id'] ?? '') === $id) { $found = true; break; }
        }
        if (!$found) zsm_schedule_json(false, 'Schedule not found', 404);
        $out = zsm_exec_timeout([ZSM_SCHEDULER_SCRIPT, $id], 120, $rc);
        if ($rc !== 0) zsm_schedule_json(false, $out ?: 'Policy execution failed', 400);
        zsm_schedule_json(true, 'Policy executed successfully');
    }

    zsm_schedule_json(false, 'Unknown action', 400);
} catch (Throwable $e) {
    zsm_log('SCHEDULE ACTION fatal: ' . $e->getMessage());
    zsm_schedule_json(false, 'Internal error: ' . $e->getMessage(), 500);
}
