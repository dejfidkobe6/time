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

// --- INVITE (by email) ---
if ($action === 'invite' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $projectId = (int)($body['project_id'] ?? 0);
    $email     = trim($body['email'] ?? '');
    $role      = in_array($body['role'] ?? '', ['admin','member','viewer']) ? $body['role'] : 'member';

    requireProjectRole($projectId, 'admin');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Neplatný e-mail']);
        exit;
    }

    // Find user by email
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['ok' => false, 'error' => 'Uživatel s tímto e-mailem není registrován v BeSix']);
        exit;
    }

    // Check if already member
    $stmt = $pdo->prepare("SELECT id FROM project_members WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$projectId, $user['id']]);
    if ($stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Tento uživatel je již členem projektu']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)");
    $stmt->execute([$projectId, $user['id'], $role]);

    echo json_encode(['ok' => true, 'message' => $user['name'] . ' byl přidán jako ' . $role]);
    exit;
}

// --- CHANGE ROLE ---
if ($action === 'change_role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $projectId = (int)($body['project_id'] ?? 0);
    $memberId  = (int)($body['user_id'] ?? 0);
    $newRole   = in_array($body['role'] ?? '', ['admin','member','viewer']) ? $body['role'] : null;

    requireProjectRole($projectId, 'admin');

    if (!$newRole || !$memberId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Chybí parametry']);
        exit;
    }

    // Cannot change owner's role
    $stmt = $pdo->prepare("SELECT role FROM project_members WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$projectId, $memberId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Člen nenalezen']);
        exit;
    }
    if ($existing['role'] === 'owner') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Roli vlastníka nelze změnit']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE project_members SET role = ? WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$newRole, $projectId, $memberId]);
    echo json_encode(['ok' => true]);
    exit;
}

// --- REMOVE MEMBER ---
if ($action === 'remove_member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $projectId = (int)($body['project_id'] ?? 0);
    $memberId  = (int)($body['user_id'] ?? 0);

    requireProjectRole($projectId, 'admin');

    // Cannot remove owner
    $stmt = $pdo->prepare("SELECT role FROM project_members WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$projectId, $memberId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Člen nenalezen']);
        exit;
    }
    if ($existing['role'] === 'owner') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Vlastníka nelze odebrat']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$projectId, $memberId]);
    echo json_encode(['ok' => true]);
    exit;
}

// --- INVITE LINK (get project invite code) ---
if ($action === 'invite_link') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    requireProjectRole($projectId, 'admin');

    $stmt = $pdo->prepare("SELECT invite_code FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Projekt nenalezen']);
        exit;
    }
    echo json_encode(['ok' => true, 'invite_code' => $row['invite_code']]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Neznámá akce']);
