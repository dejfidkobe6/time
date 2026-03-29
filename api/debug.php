<?php
// Základní test bez config.php
header('Content-Type: application/json');

$result = [
  'secrets_exists' => file_exists(__DIR__ . '/secrets.php'),
  'php_version'    => PHP_VERSION,
  'db_test'        => null,
  'error'          => null,
];

if ($result['secrets_exists']) {
  include __DIR__ . '/secrets.php';
  $result['db_pass_set'] = defined('DB_PASS') && DB_PASS !== '';
  try {
    $pdo = new PDO(
      'mysql:host=127.0.0.1;dbname=besixcz;charset=utf8mb4',
      'besixcz',
      defined('DB_PASS') ? DB_PASS : '',
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->query('SELECT 1');
    $result['db_test'] = 'OK';
  } catch (Exception $e) {
    $result['db_test'] = 'FAIL';
    $result['error']   = $e->getMessage();
  }
}

echo json_encode($result, JSON_PRETTY_PRINT);
