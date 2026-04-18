<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? '';
session_start();

if ($action === 'me') {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['user' => null]);
        exit;
    }
    $stmt = $pdo->prepare(
        "SELECT id, name, email, avatar_color FROM users WHERE id = ?"
    );
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
    echo json_encode(['user' => $user ?: null]);
    exit;
}

if ($action === 'logout') {
    // Clear remember-me token from DB and cookie
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        $hash = hash('sha256', $_COOKIE[REMEMBER_COOKIE]);
        $pdo->prepare("DELETE FROM remember_tokens WHERE token_hash = ?")->execute([$hash]);
        setcookie(REMEMBER_COOKIE, '', time() - 3600, '/', '.besix.cz', true, true);
    }
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Neznámá akce']);
