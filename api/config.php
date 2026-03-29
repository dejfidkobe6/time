<?php
require_once __DIR__ . '/secrets.php';

// Sdílené nastavení DB — stejná databáze jako plans.besix.cz
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'besixcz');
define('DB_USER', 'besixcz');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

// Sdílená session cookie přes celé besix.cz
session_name('BESIX_SESS');
ini_set('session.cookie_domain',   '.besix.cz');
ini_set('session.cookie_secure',   '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

function requireAuth(): int {
    session_start();
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Nepřihlášen']);
        exit;
    }
    return (int)$_SESSION['user_id'];
}

function requireProjectRole(int $projectId, string $minRole = 'viewer'): void {
    global $pdo;
    $roles = ['viewer' => 0, 'member' => 1, 'admin' => 2, 'owner' => 3];
    $userId = (int)$_SESSION['user_id'];
    $stmt = $pdo->prepare(
        "SELECT pm.role FROM project_members pm
         JOIN projects p ON p.id = pm.project_id
         JOIN apps a ON a.id = p.app_id
         WHERE pm.project_id = ? AND pm.user_id = ? AND a.app_key = 'time'"
    );
    $stmt->execute([$projectId, $userId]);
    $row = $stmt->fetch();
    if (!$row || ($roles[$row['role']] ?? -1) < ($roles[$minRole] ?? 0)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Nedostatečná oprávnění']);
        exit;
    }
}
