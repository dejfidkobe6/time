<?php
require_once __DIR__ . '/secrets.php';

// Lokální vývoj (php -S localhost:8000) — pozná se podle hostitele.
// Na ostrém serveru je IS_LOCAL vždy false a nic se nemění.
$_host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''))[0]);
define('IS_LOCAL', in_array($_host, ['localhost', '127.0.0.1', '[::1]', '::1'], true));
unset($_host);

// Hodnoty lze přebít v api/secrets.php (gitignorováno) — tak si je nastavuje dev.
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', 3306);
defined('DB_NAME') || define('DB_NAME', 'besixcz');
defined('DB_USER') || define('DB_USER', 'besixcz001');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
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
  prev_hash  VARCHAR(64) DEFAULT NULL,
  prev_until DATETIME DEFAULT NULL,
  last_seen  DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token_hash),
  KEY idx_user (user_id),
  KEY idx_prev (prev_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Doplň sloupce pro rotaci tokenů (tabulka je sdílená napříč besix.cz aplikacemi)
try {
    $_rcols = array_column($pdo->query("SHOW COLUMNS FROM remember_tokens")->fetchAll(), 'Field');
    if (!in_array('prev_hash', $_rcols))
        $pdo->exec("ALTER TABLE remember_tokens ADD COLUMN prev_hash VARCHAR(64) DEFAULT NULL, ADD KEY idx_prev (prev_hash)");
    if (!in_array('prev_until', $_rcols))
        $pdo->exec("ALTER TABLE remember_tokens ADD COLUMN prev_until DATETIME DEFAULT NULL");
    if (!in_array('last_seen', $_rcols))
        $pdo->exec("ALTER TABLE remember_tokens ADD COLUMN last_seen DATETIME DEFAULT NULL");
    unset($_rcols);
} catch (Exception $e) { /* nelze migrovat — přeskočit */ }

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

// ── Přihlášení zapamatované na zařízení ────────────────────────────────────
define('REMEMBER_COOKIE', 'besix_remember');
define('REMEMBER_DAYS',   14);            // jak dlouho zůstane zařízení přihlášené
define('REMEMBER_ROTATE_AFTER', 86400);   // token se přegeneruje nejvýš 1× za den
define('REMEMBER_GRACE',        120);     // starý token platí ještě 2 min (souběžné requesty)
// Na localhostu nesmí být doména .besix.cz ani Secure — prohlížeč by cookie zahodil
define('COOKIE_DOMAIN', IS_LOCAL ? ''    : '.besix.cz');
define('COOKIE_SECURE', IS_LOCAL ? false : true);

// ── Session (sdílená cookie přes celé besix.cz) ────────────────────────────
session_name('BESIX_SESS');
ini_set('session.cookie_domain',   COOKIE_DOMAIN);
ini_set('session.cookie_secure',   COOKIE_SECURE ? '1' : '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
// Session přežije zavření prohlížeče — jinak by se na každé spuštění muselo
// sahat po remember-cookie (a na iOS PWA by uživatel padal na login screen).
ini_set('session.cookie_lifetime', (string)(REMEMBER_DAYS * 86400));
ini_set('session.gc_maxlifetime',  (string)(REMEMBER_DAYS * 86400));

function setRememberCookie(string $value, int $expires): void {
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => COOKIE_DOMAIN,
        'secure'   => COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Vystaví nový remember-token pro TOTO zařízení (ostatní zařízení zůstanou přihlášená). */
function issueRememberToken(int $userId): void {
    global $pdo;
    $raw     = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_DAYS * 86400;

    // Uklidit prošlé tokeny uživatele, aktivní na jiných zařízeních nechat být
    $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ? AND expires_at < NOW()")
        ->execute([$userId]);

    $pdo->prepare(
        "INSERT INTO remember_tokens (user_id, token_hash, expires_at, last_seen)
         VALUES (?, ?, ?, NOW())"
    )->execute([$userId, hash('sha256', $raw), date('Y-m-d H:i:s', $expires)]);

    setRememberCookie($raw, $expires);
}

/** Smaže token tohoto zařízení (odhlášení). Ostatní zařízení zůstanou přihlášená. */
function clearRememberToken(): void {
    global $pdo;
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        $hash = hash('sha256', $_COOKIE[REMEMBER_COOKIE]);
        $pdo->prepare("DELETE FROM remember_tokens WHERE token_hash = ? OR prev_hash = ?")
            ->execute([$hash, $hash]);
    }
    setRememberCookie('', time() - 3600);
}

function tryRememberLogin(): void {
    global $pdo;
    if (empty($_COOKIE[REMEMBER_COOKIE])) return;

    $raw  = $_COOKIE[REMEMBER_COOKIE];
    $hash = hash('sha256', $raw);

    // Platí buď aktuální token, nebo právě rotovaný předchozí (grace okno)
    $stmt = $pdo->prepare(
        "SELECT id, user_id, token_hash, last_seen
           FROM remember_tokens
          WHERE expires_at > NOW()
            AND (token_hash = ? OR (prev_hash = ? AND prev_until > NOW()))
          LIMIT 1"
    );
    $stmt->execute([$hash, $hash]);
    $row = $stmt->fetch();

    if (!$row) {                      // neznámý / prošlý token → zahodit cookie
        setRememberCookie('', time() - 3600);
        return;
    }

    $_SESSION['user_id'] = (int)$row['user_id'];

    // Grace okno: cookie ještě nese starý token, nerotovat znovu
    if ($row['token_hash'] !== $hash) return;

    $expires = time() + REMEMBER_DAYS * 86400;   // klouzavá platnost

    $age = $row['last_seen'] ? time() - strtotime($row['last_seen']) : PHP_INT_MAX;
    if ($age < REMEMBER_ROTATE_AFTER) {
        // Jen posunout expiraci, token nechat — žádná rotace při každém requestu
        $pdo->prepare("UPDATE remember_tokens SET expires_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s', $expires), $row['id']]);
        setRememberCookie($raw, $expires);
        return;
    }

    // Rotace (max 1× denně) — starý token krátce ponechat kvůli souběžným requestům
    $newRaw = bin2hex(random_bytes(32));
    $upd    = $pdo->prepare(
        "UPDATE remember_tokens
            SET token_hash = ?, expires_at = ?, prev_hash = ?, prev_until = ?, last_seen = NOW()
          WHERE id = ? AND token_hash = ?"
    );
    $upd->execute([
        hash('sha256', $newRaw),
        date('Y-m-d H:i:s', $expires),
        $hash,
        date('Y-m-d H:i:s', time() + REMEMBER_GRACE),
        $row['id'],
        $hash,
    ]);

    // Rotoval už souběžný request → naši cookie needitovat, dojede v grace okně
    if ($upd->rowCount() > 0) setRememberCookie($newRaw, $expires);
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
