<?php
ob_start();
error_reporting(0);
require_once __DIR__ . '/config.php';
ob_end_clean();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$userId = requireAuth();
$action = $_GET['action'] ?? '';

// --- LIST ---
if ($action === 'list') {
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.description, p.bg_color, pm.role
         FROM projects p
         JOIN project_members pm ON pm.project_id = p.id
         JOIN apps a ON a.id = p.app_id
         WHERE pm.user_id = ? AND a.app_key = 'time'
         ORDER BY p.name"
    );
    $stmt->execute([$userId]);
    echo json_encode(['ok' => true, 'projects' => $stmt->fetchAll()]);
    exit;
}

// --- CREATE ---
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($body['name'] ?? '');
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Název je povinný']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM apps WHERE app_key = 'time'");
    $stmt->execute();
    $app = $stmt->fetch();
    if (!$app) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Aplikace time není registrována v DB (spusťte setup.sql)']);
        exit;
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "INSERT INTO projects (app_id, name, description, created_by) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$app['id'], $name, trim($body['description'] ?? ''), $userId]);
    $projectId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
        "INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, 'owner')"
    );
    $stmt->execute([$projectId, $userId]);
    $pdo->commit();

    echo json_encode([
        'ok'      => true,
        'project' => ['id' => $projectId, 'name' => $name, 'role' => 'owner'],
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Neznámá akce']);
