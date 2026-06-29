<?php
require_once __DIR__ . '/secrets.php';

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'besixcz');
define('DB_USER', 'besixcz001');

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

// ── Auto-create vlastní tabulky aplikace Time ───────────────────────────────
// Sdílené tabulky (users, remember_tokens) se zde nevytváří.

$pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token_hash),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS time_projects (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  bg_color    VARCHAR(32) DEFAULT NULL,
  invite_code VARCHAR(32) DEFAULT NULL,
  created_by  INT DEFAULT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS time_project_members (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  user_id    INT NOT NULL,
  role       ENUM('owner','admin','member','viewer') NOT NULL DEFAULT 'member',
  joined_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_member (project_id, user_id),
  KEY idx_user (user_id),
  KEY idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS time_schedules (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  data       LONGTEXT NOT NULL,
  updated_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Přidej google_id do users pokud chybí (sdílená tabulka, migrujeme jen sloupec)
try {
    $_ucols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(), 'Field');
    if (!in_array('google_id', $_ucols))
        $pdo->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(64) DEFAULT NULL, ADD KEY idx_google_id (google_id)");
    unset($_ucols);
} catch (Exception $e) { /* users tabulka neexistuje zatím — přeskočit */ }

// ── Session (sdílená cookie přes celé besix.cz) ────────────────────────────
session_name('BESIX_SESS');
ini_set('session.cookie_domain',   '.besix.cz');
ini_set('session.cookie_secure',   '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

define('REMEMBER_COOKIE', 'besix_remember');

function tryRememberLogin(): void {
    global $pdo;
    if (empty($_COOKIE[REMEMBER_COOKIE])) return;
    $raw  = $_COOKIE[REMEMBER_COOKIE];
    $hash = hash('sha256', $raw);
    $stmt = $pdo->prepare(
        "SELECT user_id FROM remember_tokens WHERE token_hash = ? AND expires_at > NOW()"
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        setcookie(REMEMBER_COOKIE, '', time() - 3600, '/', '.besix.cz', true, true);
        return;
    }
    $_SESSION['user_id'] = $row['user_id'];
    $newRaw  = bin2hex(random_bytes(32));
    $newHash = hash('sha256', $newRaw);
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $pdo->prepare("UPDATE remember_tokens SET token_hash = ?, expires_at = ? WHERE token_hash = ?")
        ->execute([$newHash, $expires, $hash]);
    setcookie(REMEMBER_COOKIE, $newRaw, strtotime('+30 days'), '/', '.besix.cz', true, true);
}

function requireAuth(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) tryRememberLogin();
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Nepřihlášen']);
        exit;
    }
    return (int)$_SESSION['user_id'];
}

function requireProjectRole(int $projectId, string $minRole = 'viewer'): void {
    global $pdo;
    $roles  = ['viewer' => 0, 'member' => 1, 'admin' => 2, 'owner' => 3];
    $userId = (int)$_SESSION['user_id'];
    $stmt   = $pdo->prepare(
        "SELECT role FROM time_project_members WHERE project_id = ? AND user_id = ?"
    );
    $stmt->execute([$projectId, $userId]);
    $row = $stmt->fetch();
    if (!$row || ($roles[$row['role']] ?? -1) < ($roles[$minRole] ?? 0)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Nedostatečná oprávnění']);
        exit;
    }
}
