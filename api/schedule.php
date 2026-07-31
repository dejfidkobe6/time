<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

define('MAX_SCHEDULE_BYTES', 8 * 1024 * 1024);   // 8 MB na harmonogram

$userId = requireAuth();
$action = $_GET['action'] ?? '';

// --- LOAD ---
if ($action === 'load' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    requireProjectRole($projectId, 'viewer');

    $stmt = $pdo->prepare("SELECT data FROM time_schedules WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();

    echo json_encode([
        'ok'   => true,
        'data' => $row ? json_decode($row['data'], true) : null,
    ]);
    exit;
}

// --- SAVE ---
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    requireProjectRole($projectId, 'member');

    $body = file_get_contents('php://input');

    if ($body === false || $body === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Prázdná data']);
        exit;
    }

    // Strop velikosti — ochrana DB i paměti serveru
    if (strlen($body) > MAX_SCHEDULE_BYTES) {
        http_response_code(413);
        echo json_encode([
            'ok'    => false,
            'error' => 'Harmonogram je příliš velký (limit '
                     . round(MAX_SCHEDULE_BYTES / 1048576) . ' MB).',
        ]);
        exit;
    }

    // Musí to být platný JSON objekt/pole — ne "null", "false" ani holé číslo
    $decoded = json_decode($body, true, 64);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Neplatná data']);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO time_schedules (project_id, data, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE data = VALUES(data), updated_by = VALUES(updated_by)"
    );
    $stmt->execute([$projectId, $body, $userId]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Neznámá akce']);
