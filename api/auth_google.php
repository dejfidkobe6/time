<?php
/**
 * Google OAuth 2.0 callback handler
 * Secrets required in api/secrets.php:
 *   define('GOOGLE_CLIENT_ID',     'xxx.apps.googleusercontent.com');
 *   define('GOOGLE_CLIENT_SECRET', 'GOCSPX-...');
 */
error_reporting(0);
ini_set('display_errors', 0);

// Config may fail if secrets.php missing — catch and redirect
try {
    require_once __DIR__ . '/config.php';
} catch (Throwable $e) {
    header('Location: /login.php?error=' . urlencode('Chyba konfigurace serveru: ' . $e->getMessage()));
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

$REDIRECT_URI = 'https://time.besix.cz/api/auth_google.php';

// ─── Step 1: Initiate — redirect to Google ───────────────────────────────────
if (isset($_GET['login'])) {
    if (!defined('GOOGLE_CLIENT_ID') || !GOOGLE_CLIENT_ID) {
        header('Location: /login.php?error=' . urlencode('Google OAuth není nakonfigurován na serveru.'));
        exit;
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_return'] = $_GET['return'] ?? '/';

    $params = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => $REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

// ─── Step 2: Callback ────────────────────────────────────────────────────────
try {
    $code  = $_GET['code']  ?? '';
    $state = $_GET['state'] ?? '';
    $error = $_GET['error'] ?? '';

    if ($error) {
        header('Location: /login.php?error=' . urlencode('Přihlášení přes Google bylo zrušeno.'));
        exit;
    }

    if (!$code || !$state || $state !== ($_SESSION['oauth_state'] ?? '')) {
        header('Location: /login.php?error=' . urlencode('Neplatný stav přihlášení. Zkuste to znovu.'));
        exit;
    }
    unset($_SESSION['oauth_state']);
    $returnUrl = $_SESSION['oauth_return'] ?? '/';
    unset($_SESSION['oauth_return']);

    if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) {
        header('Location: /login.php?error=' . urlencode('Google OAuth není nakonfigurován na serveru.'));
        exit;
    }

    // Exchange code for tokens
    $tokenRes = httpPost('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => $REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);

    if (!$tokenRes || empty($tokenRes['access_token'])) {
        header('Location: /login.php?error=' . urlencode('Nepodařilo se získat token od Google. Zkuste to znovu.'));
        exit;
    }

    // Fetch user profile
    $profile = httpGet('https://www.googleapis.com/oauth2/v3/userinfo', $tokenRes['access_token']);

    if (!$profile || empty($profile['email'])) {
        header('Location: /login.php?error=' . urlencode('Nepodařilo se načíst profil z Google.'));
        exit;
    }

    $googleId = $profile['sub']   ?? '';
    $email    = strtolower(trim($profile['email']));
    $name     = trim($profile['name'] ?? $email);

    if (empty($profile['email_verified'])) {
        header('Location: /login.php?error=' . urlencode('Google účet nemá ověřený e-mail.'));
        exit;
    }

    // ─── Find or create user ─────────────────────────────────────────────────

    // Ensure user_oauth table exists (no FK constraint — shared DB compatibility)
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_oauth (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        provider    VARCHAR(32) NOT NULL,
        provider_id VARCHAR(128) NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_provider (provider, provider_id),
        KEY idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 1. Find via oauth link
    $stmt = $pdo->prepare(
        "SELECT u.id FROM users u
         JOIN user_oauth uo ON uo.user_id = u.id
         WHERE uo.provider = 'google' AND uo.provider_id = ?"
    );
    $stmt->execute([$googleId]);
    $row = $stmt->fetch();

    if (!$row) {
        // 2. Match by email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if ($row) {
            // Link Google to existing account
            $pdo->prepare(
                "INSERT IGNORE INTO user_oauth (user_id, provider, provider_id) VALUES (?, 'google', ?)"
            )->execute([$row['id'], $googleId]);
        } else {
            // 3. Create new user
            $colors = ['#4A5340','#3a6b5a','#5a4a6b','#6b3a3a','#3a4a6b','#6b5a3a'];
            $avatarColor = $colors[array_rand($colors)];

            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO users (name, email, password_hash, is_verified, avatar_color)
                 VALUES (?, ?, NULL, 1, ?)"
            )->execute([$name, $email, $avatarColor]);
            $newUserId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO user_oauth (user_id, provider, provider_id) VALUES (?, 'google', ?)"
            )->execute([$newUserId, $googleId]);
            $pdo->commit();

            $row = ['id' => $newUserId];
        }
    }

    // Log in
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['id'];

    $safe = $returnUrl;
    if (!preg_match('#^/#', $safe) && parse_url($safe, PHP_URL_HOST) !== 'time.besix.cz') {
        $safe = '/';
    }
    header('Location: ' . $safe);
    exit;

} catch (Throwable $e) {
    // Catch all PHP errors and redirect with message instead of showing 500
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    header('Location: /login.php?error=' . urlencode('Chyba přihlášení: ' . $e->getMessage()));
    exit;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function httpPost(string $url, array $data): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($res && $code < 400) ? json_decode($res, true) : null;
}

function httpGet(string $url, string $accessToken): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($res && $code < 400) ? json_decode($res, true) : null;
}
