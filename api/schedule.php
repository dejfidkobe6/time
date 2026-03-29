<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

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
    if (!$body || !json_decode($body)) {
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
