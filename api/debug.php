<?php
// Dočasný debug endpoint — smazat po vyřešení problémů
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
ob_end_clean();

session_start();

$out = [
  'session_name'    => session_name(),
  'session_id'      => session_id(),
  'user_id'         => $_SESSION['user_id'] ?? null,
  'db_ok'           => false,
  'user'            => null,
  'projects_count'  => null,
  'error'           => null,
];

try {
  $pdo->query('SELECT 1');
  $out['db_ok'] = true;

  if (!empty($_SESSION['user_id'])) {
    $s = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $s->execute([$_SESSION['user_id']]);
    $out['user'] = $s->fetch();

    $s = $pdo->prepare(
      "SELECT COUNT(*) as cnt FROM projects p
       JOIN project_members pm ON pm.project_id = p.id
       JOIN apps a ON a.id = p.app_id
       WHERE pm.user_id = ? AND a.app_key = 'time'"
    );
    $s->execute([$_SESSION['user_id']]);
    $out['projects_count'] = (int)$s->fetch()['cnt'];
  }
} catch (Exception $e) {
  $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT);
