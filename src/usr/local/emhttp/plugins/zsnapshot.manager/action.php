<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/include/lib.php';

function zsm_json(bool $ok, string $message, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') zsm_json(false, 'POST required', 405);
if (!zsm_csrf_valid()) zsm_json(false, 'Security token validation failed. Refresh the page and try again.', 403);

$action = (string)($_POST['zsm_action'] ?? $_POST['action'] ?? '');

try {
    if ($action === 'create') {
        [$ok, $msg] = zsm_create_snapshot(
            (string)($_POST['dataset'] ?? ''),
            trim((string)($_POST['snapname'] ?? '')),
            !empty($_POST['recursive'])
        );
    } elseif ($action === 'delete') {
        [$ok, $msg] = zsm_delete_snapshot((string)($_POST['snapshot'] ?? ''));
    } elseif ($action === 'hold') {
        [$ok, $msg] = zsm_hold((string)($_POST['snapshot'] ?? ''), true);
    } elseif ($action === 'release') {
        [$ok, $msg] = zsm_hold((string)($_POST['snapshot'] ?? ''), false);
    } elseif ($action === 'clone') {
        [$ok, $msg] = zsm_clone((string)($_POST['snapshot'] ?? ''), (string)($_POST['clone'] ?? ''));
    } elseif ($action === 'rollback') {
        [$ok, $msg] = zsm_safe_rollback((string)($_POST['snapshot'] ?? ''));
    } else {
        zsm_json(false, 'Unknown action', 400);
    }

    zsm_json($ok, $msg, $ok ? 200 : 400);
} catch (Throwable $e) {
    zsm_log('ACTION fatal: ' . $e->getMessage());
    zsm_json(false, 'Internal error: ' . $e->getMessage(), 500);
}
