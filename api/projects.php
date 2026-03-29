<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/config.php';

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

    try {
        $pdo->beginTransaction();
        $inviteCode = bin2hex(random_bytes(6));
        $stmt = $pdo->prepare(
            "INSERT INTO projects (app_id, name, description, invite_code, created_by) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$app['id'], $name, trim($body['description'] ?? ''), $inviteCode, $userId]);
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
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- MEMBERS ---
if ($action === 'members') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    requireProjectRole($projectId, 'viewer');
    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email, u.avatar_color, pm.role
         FROM project_members pm
         JOIN users u ON u.id = pm.user_id
         WHERE pm.project_id = ?
         ORDER BY FIELD(pm.role,'owner','admin','member','viewer'), u.name"
    );
    $stmt->execute([$projectId]);
    echo json_encode(['ok' => true, 'members' => $stmt->fetchAll()]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Neznámá akce']);
