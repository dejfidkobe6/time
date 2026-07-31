<?php
/**
 * Diagnostika prostředí. Prozrazuje verzi PHP, stav databáze a session,
 * takže je přístupná jen s ADMIN_TOKEN (viz admin_guard.php).
 *   https://time.besix.cz/api/debug.php?token=<ADMIN_TOKEN>
 */
require_once __DIR__ . '/admin_guard.php';
requireAdmin();

header('Content-Type: application/json');

$result = [
  'secrets_exists'       => file_exists(__DIR__ . '/secrets.php'),
  'php_version'          => PHP_VERSION,
  'db_test'              => null,
  'session_id'           => null,
  'user_id'              => null,
  'user'                 => null,
  'tables'               => [],
  'error'                => null,
];

if ($result['secrets_exists']) {
  include __DIR__ . '/secrets.php';
  try {
    // Vlastní připojení (ne config.php) — smyslem je zjistit, proč DB nejede
    $pdo = new PDO(
      'mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1')
        . ';port='   . (defined('DB_PORT') ? DB_PORT : 3306)
        . ';dbname=' . (defined('DB_NAME') ? DB_NAME : 'besixcz')
        . ';charset=utf8mb4',
      defined('DB_USER') ? DB_USER : 'besixcz001',
      defined('DB_PASS') ? DB_PASS : '',
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $result['db_test'] = 'OK';

    session_name('BESIX_SESS');
    if (session_status() === PHP_SESSION_NONE) session_start();
    $result['session_id'] = session_id();
    $result['user_id']    = $_SESSION['user_id'] ?? null;

    if (!empty($_SESSION['user_id'])) {
      $s = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
      $s->execute([$_SESSION['user_id']]);
      $result['user'] = $s->fetch();
    }

    // Check which time_ tables exist
    foreach (['time_projects','time_project_members','time_schedules','remember_tokens'] as $t) {
      $r = $pdo->query("SHOW TABLES LIKE '$t'")->fetch();
      $result['tables'][$t] = $r ? 'exists' : 'MISSING';
    }

  } catch (Exception $e) {
    $result['db_test'] = 'FAIL';
    $result['error']   = $e->getMessage();
  }
}

echo json_encode($result, JSON_PRETTY_PRINT);
