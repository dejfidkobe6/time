<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$userId = requireAuth();
$action = $_GET['action'] ?? '';

// --- LIST ---
if ($action === 'list') {
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.description, p.bg_color, pm.role
         FROM time_projects p
         JOIN time_project_members pm ON pm.project_id = p.id
         WHERE pm.user_id = ?
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

    try {
        $pdo->beginTransaction();
        $inviteCode = bin2hex(random_bytes(6));
        $pdo->prepare(
            "INSERT INTO time_projects (name, description, invite_code, created_by) VALUES (?, ?, ?, ?)"
        )->execute([$name, trim($body['description'] ?? ''), $inviteCode, $userId]);
        $projectId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO time_project_members (project_id, user_id, role) VALUES (?, ?, 'owner')"
        )->execute([$projectId, $userId]);
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
         FROM time_project_members pm
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
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $projectId = (int)($body['project_id'] ?? 0);
    $email     = trim($body['email'] ?? '');
    $role      = in_array($body['role'] ?? '', ['admin','member','viewer']) ? $body['role'] : 'member';

    requireProjectRole($projectId, 'admin');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Neplatný e-mail']);
        exit;
    }

    $stmtP = $pdo->prepare("SELECT name, invite_code FROM time_projects WHERE id = ?");
    $stmtP->execute([$projectId]);
    $project = $stmtP->fetch();
    if (!$project) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Projekt nenalezen']);
        exit;
    }

    $stmtI = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmtI->execute([$userId]);
    $inviter     = $stmtI->fetch();
    $inviterName = $inviter['name'] ?? 'BeSix';

    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $inviteUrl = 'https://time.besix.cz/invite.php?code=' . urlencode($project['invite_code']);
        $html = buildInviteEmail($inviterName, $project['name'], $inviteUrl);
        $sent = sendBrevoEmail($email, $email, 'Pozvánka do projektu ' . $project['name'] . ' – BeSix Time', $html);
        echo json_encode([
            'ok'      => true,
            'message' => 'Pozvánka odeslána na ' . $email . ($sent ? '' : ' (e-mail se nepodařilo odeslat)'),
            'invited' => false,
        ]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM time_project_members WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$projectId, $user['id']]);
    if ($stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Tento uživatel je již členem projektu']);
        exit;
    }

    $pdo->prepare("INSERT INTO time_project_members (project_id, user_id, role) VALUES (?, ?, ?)")
        ->execute([$projectId, $user['id'], $role]);

    $projectUrl = 'https://time.besix.cz/';
    $html = buildAddedToProjectEmail($inviterName, $project['name'], $projectUrl);
    sendBrevoEmail($email, $user['name'], 'Byli jste přidáni do projektu ' . $project['name'] . ' – BeSix Time', $html);

    echo json_encode(['ok' => true, 'message' => $user['name'] . ' byl přidán jako ' . $role, 'invited' => true]);
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

    $stmt = $pdo->prepare("SELECT role FROM time_project_members WHERE project_id = ? AND user_id = ?");
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

    $pdo->prepare("UPDATE time_project_members SET role = ? WHERE project_id = ? AND user_id = ?")
        ->execute([$newRole, $projectId, $memberId]);
    echo json_encode(['ok' => true]);
    exit;
}

// --- REMOVE MEMBER ---
if ($action === 'remove_member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $projectId = (int)($body['project_id'] ?? 0);
    $memberId  = (int)($body['user_id'] ?? 0);

    requireProjectRole($projectId, 'admin');

    $stmt = $pdo->prepare("SELECT role FROM time_project_members WHERE project_id = ? AND user_id = ?");
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

    $pdo->prepare("DELETE FROM time_project_members WHERE project_id = ? AND user_id = ?")
        ->execute([$projectId, $memberId]);
    echo json_encode(['ok' => true]);
    exit;
}

// --- INVITE LINK ---
if ($action === 'invite_link') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    requireProjectRole($projectId, 'admin');

    $stmt = $pdo->prepare("SELECT invite_code FROM time_projects WHERE id = ?");
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

// --- RENAME ---
if ($action === 'rename' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $projectId   = (int)($body['project_id'] ?? 0);
    $name        = trim($body['name'] ?? '');
    $description = trim($body['description'] ?? '');

    requireProjectRole($projectId, 'admin');

    if ($name === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Název je povinný']);
        exit;
    }

    $pdo->prepare("UPDATE time_projects SET name = ?, description = ? WHERE id = ?")
        ->execute([$name, $description ?: null, $projectId]);
    echo json_encode(['ok' => true, 'name' => $name]);
    exit;
}

// --- DELETE ---
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $projectId = (int)($body['project_id'] ?? 0);

    requireProjectRole($projectId, 'owner');

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM time_schedules WHERE project_id = ?")->execute([$projectId]);
        $pdo->prepare("DELETE FROM time_project_members WHERE project_id = ?")->execute([$projectId]);
        $pdo->prepare("DELETE FROM time_projects WHERE id = ?")->execute([$projectId]);
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Chyba při mazání projektu']);
    }
    exit;
}

// --- DUPLICATE ---
if ($action === 'duplicate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $projectId = (int)($body['project_id'] ?? 0);

    requireProjectRole($projectId, 'viewer');

    try {
        $pdo->beginTransaction();

        // Load source project
        $src = $pdo->prepare("SELECT name, description, bg_color FROM time_projects WHERE id = ?");
        $src->execute([$projectId]);
        $srcRow = $src->fetch();
        if (!$srcRow) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Zdrojový projekt nenalezen']);
            exit;
        }

        // Load source schedule JSON
        $sched = $pdo->prepare("SELECT data FROM time_schedules WHERE project_id = ?");
        $sched->execute([$projectId]);
        $schedRow = $sched->fetch();

        // Create new project
        $newName     = 'Kopie — ' . $srcRow['name'];
        $inviteCode  = bin2hex(random_bytes(6));
        $pdo->prepare(
            "INSERT INTO time_projects (name, description, bg_color, invite_code, created_by) VALUES (?, ?, ?, ?, ?)"
        )->execute([$newName, $srcRow['description'], $srcRow['bg_color'], $inviteCode, $userId]);
        $newId = (int)$pdo->lastInsertId();

        // Add current user as owner
        $pdo->prepare(
            "INSERT INTO time_project_members (project_id, user_id, role) VALUES (?, ?, 'owner')"
        )->execute([$newId, $userId]);

        // Copy schedule JSON (update project name inside JSON if present)
        if ($schedRow && $schedRow['data']) {
            $json = json_decode($schedRow['data'], true);
            if (is_array($json)) {
                $json['name'] = $newName;
            }
            $pdo->prepare(
                "INSERT INTO time_schedules (project_id, data, updated_by) VALUES (?, ?, ?)"
            )->execute([$newId, json_encode($json, JSON_UNESCAPED_UNICODE), $userId]);
        }

        $pdo->commit();
        echo json_encode([
            'ok'      => true,
            'project' => ['id' => $newId, 'name' => $newName, 'role' => 'owner'],
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Neznámá akce']);
