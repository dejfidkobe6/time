<?php
require_once __DIR__ . '/api/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = $pdo->prepare(
            "SELECT id, name, password_hash, is_verified FROM users WHERE email = ?"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if (!$user['is_verified']) {
                $error = 'Účet není ověřen. Zkontrolujte e-mail.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                header('Location: /');
                exit;
            }
        } else {
            $error = 'Nesprávný e-mail nebo heslo.';
        }
    } else {
        $error = 'Vyplňte e-mail a heslo.';
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Přihlášení — BeSix Time</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  background: #F2F2F7;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

.wrap {
  width: 100%;
  max-width: 380px;
}

.logo-area {
  text-align: center;
  margin-bottom: 32px;
}

.logo-img {
  height: 52px;
  width: auto;
  margin-bottom: 16px;
  filter: brightness(0);
}

.logo-name {
  font-size: 28px;
  font-weight: 700;
  color: #1C1C1E;
  letter-spacing: -0.5px;
}

.logo-sub {
  font-size: 13px;
  font-weight: 400;
  color: #8E8E93;
  margin-top: 4px;
}

.card {
  background: #FFFFFF;
  border-radius: 20px;
  padding: 32px 28px;
  box-shadow: 0 2px 20px rgba(0,0,0,0.08);
}

.card-title {
  font-size: 22px;
  font-weight: 700;
  color: #1C1C1E;
  margin-bottom: 4px;
}

.card-sub {
  font-size: 14px;
  color: #8E8E93;
  font-weight: 400;
  margin-bottom: 28px;
}

.field {
  margin-bottom: 14px;
}

label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  color: #3A3A3C;
  margin-bottom: 7px;
}

input[type="email"],
input[type="password"] {
  width: 100%;
  padding: 13px 16px;
  border: 1px solid #E5E5EA;
  border-radius: 12px;
  font-family: inherit;
  font-size: 15px;
  color: #1C1C1E;
  background: #F9F9FB;
  outline: none;
  transition: border-color 0.15s, background 0.15s;
  -webkit-appearance: none;
}

input:focus {
  border-color: #4A5340;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(74,83,64,0.1);
}

.error-msg {
  background: #FFF2F2;
  color: #C0392B;
  font-size: 13px;
  font-weight: 500;
  padding: 11px 14px;
  border-radius: 10px;
  margin-bottom: 18px;
  border: 1px solid #FFCDD0;
}

.btn-submit {
  width: 100%;
  padding: 14px;
  background: #4A5340;
  color: white;
  border: none;
  border-radius: 12px;
  font-family: inherit;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s, transform 0.1s;
  margin-top: 10px;
  -webkit-appearance: none;
}

.btn-submit:hover { background: #3D4536; }
.btn-submit:active { transform: scale(0.98); }

.divider {
  border: none;
  border-top: 1px solid #F2F2F7;
  margin: 24px 0;
}

.footer-links {
  text-align: center;
  font-size: 13px;
  color: #8E8E93;
}

.footer-links a {
  color: #4A5340;
  text-decoration: none;
  font-weight: 600;
}

.portal-link {
  text-align: center;
  font-size: 13px;
  color: #8E8E93;
  margin-top: 20px;
}

.portal-link a {
  color: #4A5340;
  text-decoration: none;
  font-weight: 600;
}
</style>
</head>
<body>

<div class="wrap">
  <div class="logo-area">
    <img src="/besix_logo_highres_transparent.png" alt="BeSix" class="logo-img">
    <div class="logo-name">BeSix Time</div>
    <div class="logo-sub">Harmonogram stavby</div>
  </div>

  <div class="card">
    <div class="card-title">Přihlaste se</div>
    <div class="card-sub">Použijte svůj BeSix účet</div>

    <?php if ($error): ?>
      <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="on">
      <div class="field">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               placeholder="vas@email.cz" autofocus required>
      </div>
      <div class="field">
        <label for="password">Heslo</label>
        <input type="password" id="password" name="password"
               placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn-submit">Přihlásit se</button>
    </form>

    <hr class="divider">

    <div class="footer-links">
      Zapomněli jste heslo? <a href="https://plans.besix.cz/forgot.php">Obnovit heslo</a>
    </div>
  </div>

  <div class="portal-link">
    Nemáte účet? <a href="https://plans.besix.cz/register.php">Registrovat se</a>
  </div>
</div>

</body>
</html>
