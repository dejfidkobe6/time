<?php
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
        "SELECT id, name, email, avatar_color FROM users WHERE id = ? AND is_verified = 1"
    );
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
    echo json_encode(['user' => $user ?: null]);
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Neznámá akce']);
