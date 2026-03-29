<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/api/config.php';

$code = trim($_GET['code'] ?? '');

// Start session and check login
if (session_status() === PHP_SESSION_NONE) session_start();
$loggedUserId = $_SESSION['user_id'] ?? null;

// If not logged in, redirect to login with return URL
if (!$loggedUserId) {
    header('Location: /login.php?return=' . urlencode('/invite.php?code=' . urlencode($code)));
    exit;
}

$error = '';
$project = null;
$alreadyMember = false;
$accepted = false;
$inviteRole = 'member';

if (!$code) {
    $error = 'Neplatný odkaz pozvánky.';
} else {
    // Find project by invite_code
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.description, a.app_key
         FROM projects p
         JOIN apps a ON a.id = p.app_id
         WHERE p.invite_code = ? AND a.app_key = 'time'"
    );
    $stmt->execute([$code]);
    $project = $stmt->fetch();

    if (!$project) {
        $error = 'Pozvánka nebyla nalezena nebo vypršela.';
    } else {
        // Check if already member
        $stmt = $pdo->prepare("SELECT role FROM project_members WHERE project_id = ? AND user_id = ?");
        $stmt->execute([$project['id'], $loggedUserId]);
        $mem = $stmt->fetch();
        if ($mem) {
            $alreadyMember = true;
            $inviteRole = $mem['role'];
        }
    }
}

// Handle accept
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept']) && $project && !$alreadyMember && !$error) {
    try {
        $stmt = $pdo->prepare("INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, 'member')");
        $stmt->execute([$project['id'], $loggedUserId]);
        $accepted = true;
    } catch (Exception $e) {
        $error = 'Nepodařilo se přijmout pozvánku. Zkuste to prosím znovu.';
    }
}

// Get current user name
$stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$loggedUserId]);
$currentUser = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pozvánka — BeSix Time</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Montserrat', sans-serif;
  background: #1C1E1A;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}
.card {
  background: #2E3029;
  border-radius: 16px;
  padding: 40px 36px;
  width: 100%;
  max-width: 480px;
  border: 1px solid #3D4138;
  box-shadow: 0 20px 60px rgba(0,0,0,0.5);
}
.logo-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 32px;
  gap: 8px;
}
.logo-img {
  height: 44px;
  width: auto;
  display: block;
}
.logo-title {
  font-size: 18px;
  font-weight: 800;
  color: #fff;
  letter-spacing: -0.3px;
}
.logo-sub {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 2px;
  color: #8B9E7A;
}
.divider { height: 1px; background: #3D4138; margin: 0 0 28px; }
.label {
  font-size: 9px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1.2px;
  color: #9A9A8E;
  margin-bottom: 6px;
}
.project-name {
  font-size: 22px;
  font-weight: 800;
  color: #fff;
  margin-bottom: 24px;
  line-height: 1.2;
}
.role-box {
  background: #3D4138;
  border-radius: 10px;
  padding: 16px 18px;
  margin-bottom: 20px;
  border-left: 3px solid #8B9E7A;
}
.role-box .label { margin-bottom: 4px; }
.role-name {
  font-size: 17px;
  font-weight: 700;
  color: #fff;
}
.role-desc {
  font-size: 11px;
  color: #9A9A8E;
  margin-top: 4px;
  font-weight: 500;
}
.desc-text {
  font-size: 12px;
  color: #9A9A8E;
  line-height: 1.6;
  margin-bottom: 24px;
}
.btn-accept {
  width: 100%;
  padding: 14px;
  background: #4A5340;
  color: white;
  border: none;
  border-radius: 10px;
  font-family: 'Montserrat', sans-serif;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.15s;
  text-transform: uppercase;
  letter-spacing: 0.8px;
}
.btn-accept:hover { background: #5a6350; }
.btn-link {
  display: block;
  width: 100%;
  padding: 14px;
  background: #4A5340;
  color: white;
  border-radius: 10px;
  text-decoration: none;
  font-size: 13px;
  font-weight: 700;
  text-align: center;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  transition: background 0.15s;
  margin-top: 10px;
}
.btn-link:hover { background: #5a6350; }
.error-box {
  background: rgba(192,57,43,0.15);
  border: 1px solid rgba(192,57,43,0.3);
  border-radius: 8px;
  padding: 14px 16px;
  color: #e88;
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 20px;
}
.success-box {
  background: rgba(74,83,64,0.3);
  border: 1px solid rgba(139,158,122,0.4);
  border-radius: 8px;
  padding: 16px;
  text-align: center;
  margin-bottom: 20px;
}
.success-box .icon { font-size: 28px; margin-bottom: 8px; }
.success-box .title { font-size: 15px; font-weight: 800; color: #8B9E7A; margin-bottom: 4px; }
.success-box .text { font-size: 12px; color: #9A9A8E; }
.footer-note {
  margin-top: 20px;
  text-align: center;
  font-size: 10px;
  color: #9A9A8E;
  line-height: 1.6;
}
.already-tag {
  display: inline-block;
  background: #3D4138;
  color: #8B9E7A;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 16px;
}
</style>
</head>
<body>
<div class="card">
  <div class="logo-wrap">
    <img class="logo-img" src="/besix_logo_highres_transparent.png" alt="BeSix"
         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
    <div style="display:none;width:44px;height:44px;background:#4A5340;border-radius:8px;align-items:center;justify-content:center;font-weight:900;color:white;font-size:14px;">B</div>
    <div class="logo-title">BeSix Time</div>
    <div class="logo-sub">Harmonogram stavby</div>
  </div>

  <div class="divider"></div>

  <?php if ($error): ?>
    <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <a href="/" class="btn-link">Zpět do aplikace</a>

  <?php elseif ($accepted): ?>
    <div class="success-box">
      <div class="icon">✓</div>
      <div class="title">Pozvánka přijata!</div>
      <div class="text">Nyní máte přístup k projektu.</div>
    </div>
    <div class="label">Projekt</div>
    <div class="project-name"><?= htmlspecialchars($project['name']) ?></div>
    <a href="/" class="btn-link">Otevřít projekt</a>

  <?php elseif ($alreadyMember): ?>
    <div class="label">Pozvánka do projektu</div>
    <div class="project-name"><?= htmlspecialchars($project['name']) ?></div>
    <span class="already-tag">Již jste členem</span>
    <p class="desc-text">Jste přihlášen jako <strong style="color:#fff;"><?= htmlspecialchars($currentUser['name'] ?? '') ?></strong> a máte přístup k tomuto projektu.</p>
    <a href="/" class="btn-link">Otevřít projekt</a>

  <?php else: ?>
    <div class="label">Pozvánka do projektu</div>
    <div class="project-name"><?= htmlspecialchars($project['name']) ?></div>

    <div class="role-box">
      <div class="label">Vaše role</div>
      <div class="role-name">Člen</div>
      <div class="role-desc">Člen může zobrazovat a upravovat harmonogram projektu.</div>
    </div>

    <p class="desc-text">
      Přihlášen jako <strong style="color:#fff;"><?= htmlspecialchars($currentUser['name'] ?? '') ?></strong>.<br>
      Klikněte na tlačítko níže pro přijetí pozvánky a přístup k projektu.
    </p>

    <form method="POST">
      <input type="hidden" name="accept" value="1">
      <button type="submit" class="btn-accept">Přijmout pozvánku</button>
    </form>

    <div class="footer-note">
      Pokud tuto pozvánku neočekáváte, ignorujte tento odkaz.<br>
      Přihlášeni: <?= htmlspecialchars($currentUser['name'] ?? '') ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
