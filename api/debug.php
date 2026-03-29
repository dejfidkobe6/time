<?php
header('Content-Type: application/json');

$result = [
  'secrets_exists' => file_exists(__DIR__ . '/secrets.php'),
  'php_version'    => PHP_VERSION,
  'db_test'        => null,
  'session_id'     => null,
  'user_id'        => null,
  'user'           => null,
  'apps_time'      => null,
  'error'          => null,
];

if ($result['secrets_exists']) {
  include __DIR__ . '/secrets.php';
  try {
    $pdo = new PDO(
      'mysql:host=127.0.0.1;dbname=besixcz;charset=utf8mb4',
      'besixcz001',
      defined('DB_PASS') ? DB_PASS : '',
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $result['db_test'] = 'OK';

    // Session test
    session_name('BESIX_SESS');
    if (session_status() === PHP_SESSION_NONE) session_start();
    $result['session_id'] = session_id();
    $result['user_id']    = $_SESSION['user_id'] ?? null;

    if (!empty($_SESSION['user_id'])) {
      $s = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
      $s->execute([$_SESSION['user_id']]);
      $result['user'] = $s->fetch();
    }

    // Check apps table
    $s = $pdo->prepare("SELECT id, app_key FROM apps WHERE app_key = 'time'");
    $s->execute();
    $result['apps_time'] = $s->fetch();

  } catch (Exception $e) {
    $result['db_test'] = 'FAIL';
    $result['error']   = $e->getMessage();
  }
}

echo json_encode($result, JSON_PRETTY_PRINT);
