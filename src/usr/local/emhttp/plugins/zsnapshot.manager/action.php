<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/include/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST required']);
    exit;
}

$action = (string)($_POST['action'] ?? '');
$ok = false;
$msg = 'Unknown action';

try {
    if ($action === 'create') {
        [$ok, $msg] = zsm_create_snapshot(
            (string)($_POST['dataset'] ?? ''),
            (string)($_POST['snapname'] ?? ''),
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
    }
} catch (Throwable $e) {
    zsm_log('ACTION fatal: ' . $e->getMessage());
    $ok = false;
    $msg = 'Internal error: ' . $e->getMessage();
}

if (!$ok) http_response_code(400);
echo json_encode(['ok' => $ok, 'message' => $msg], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
