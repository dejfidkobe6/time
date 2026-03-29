<?php
require_once __DIR__ . '/api/config.php';
session_start();

// Už přihlášen → zpět na app
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
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Lora:wght@500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Montserrat', sans-serif;
  background: #1C1E1A;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.wrap {
  width: 100%;
  max-width: 400px;
}

.logo-area {
  text-align: center;
  margin-bottom: 36px;
}

.logo-mark {
  width: 52px; height: 52px;
  background: #4A5340;
  border-radius: 12px;
  display: inline-flex;
  align-items: center; justify-content: center;
  font-size: 18px; font-weight: 900; color: white;
  letter-spacing: -0.5px;
  margin-bottom: 14px;
}

.logo-name {
  font-family: 'Lora', serif;
  font-size: 26px;
  font-weight: 600;
  color: #F5F4F0;
  letter-spacing: -0.3px;
}

.logo-sub {
  font-size: 11px;
  font-weight: 600;
  color: #6B6B60;
  text-transform: uppercase;
  letter-spacing: 1.2px;
  margin-top: 4px;
}

.card {
  background: #FFFFFF;
  border-radius: 14px;
  padding: 36px 32px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.4);
}

.card-title {
  font-size: 15px;
  font-weight: 800;
  color: #1C1E1A;
  margin-bottom: 6px;
}

.card-sub {
  font-size: 12px;
  color: #9A9A8E;
  font-weight: 500;
  margin-bottom: 28px;
}

.field {
  margin-bottom: 16px;
}

label {
  display: block;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  color: #4A4A42;
  margin-bottom: 6px;
}

input[type="email"],
input[type="password"] {
  width: 100%;
  padding: 11px 14px;
  border: 1.5px solid #DDD9D0;
  border-radius: 6px;
  font-family: 'Montserrat', sans-serif;
  font-size: 13px;
  color: #1C1E1A;
  background: #FAFAF8;
  outline: none;
  transition: border-color 0.15s;
}

input:focus {
  border-color: #4A5340;
  background: #fff;
}

.error-msg {
  background: #F5C6C2;
  color: #C0392B;
  font-size: 12px;
  font-weight: 600;
  padding: 10px 14px;
  border-radius: 6px;
  margin-bottom: 18px;
  border: 1px solid #F0A8A2;
}

.btn-submit {
  width: 100%;
  padding: 12px;
  background: #4A5340;
  color: white;
  border: none;
  border-radius: 6px;
  font-family: 'Montserrat', sans-serif;
  font-size: 12px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  cursor: pointer;
  transition: background 0.15s;
  margin-top: 8px;
}

.btn-submit:hover { background: #3D4536; }

.footer-links {
  margin-top: 20px;
  text-align: center;
  font-size: 11px;
  color: #9A9A8E;
}

.footer-links a {
  color: #4A5340;
  text-decoration: none;
  font-weight: 600;
}

.footer-links a:hover { text-decoration: underline; }

.divider {
  border: none;
  border-top: 1px solid #EEECEA;
  margin: 22px 0;
}

.portal-link {
  text-align: center;
  font-size: 11px;
  color: #9A9A8E;
  margin-top: 24px;
  font-weight: 500;
}

.portal-link a {
  color: #8B9E7A;
  text-decoration: none;
  font-weight: 600;
}
</style>
</head>
<body>

<div class="wrap">
  <div class="logo-area">
    <div class="logo-mark">B6</div>
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
