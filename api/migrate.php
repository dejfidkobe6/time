<?php
/**
 * Jednorázová migrace dat z původních sdílených tabulek do time_ tabulek.
 * Bezpečné spustit opakovaně — používá INSERT IGNORE, takže nepřepisuje existující záznamy.
 *
 * Spustit v prohlížeči: https://time.besix.cz/api/migrate.php
 * (nebo z příkazové řádky: php api/migrate.php)
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/secrets.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=besixcz;charset=utf8mb4',
    'besixcz001',
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$log    = [];
$errors = [];

function tableExists(PDO $pdo, string $table): bool {
    return (bool) $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
}

function columnExists(PDO $pdo, string $table, string $col): bool {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
    return in_array($col, $cols);
}

// ── 1. Ujisti se, že cílové tabulky existují ────────────────────────────────
foreach (['time_projects', 'time_project_members', 'time_schedules'] as $t) {
    if (!tableExists($pdo, $t)) {
        $errors[] = "Cílová tabulka $t neexistuje — nejprve načti aplikaci, aby se vytvořila.";
    }
}
if ($errors) {
    echo json_encode(['ok' => false, 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 2. Migrace projects → time_projects ─────────────────────────────────────
if (!tableExists($pdo, 'projects')) {
    $log[] = 'Tabulka `projects` neexistuje — přeskočeno.';
} else {
    // Zjisti app_id pro 'time' (pokud apps tabulka existuje)
    $appId = null;
    if (tableExists($pdo, 'apps')) {
        $row   = $pdo->query("SELECT id FROM apps WHERE app_key = 'time' LIMIT 1")->fetch();
        $appId = $row ? (int)$row['id'] : null;
    }

    // Vyber projekty patřící aplikaci time
    $hasAppId = columnExists($pdo, 'projects', 'app_id');
    if ($hasAppId && $appId !== null) {
        $srcProjects = $pdo->prepare("SELECT * FROM projects WHERE app_id = ?");
        $srcProjects->execute([$appId]);
    } else {
        // Pokud app_id sloupec nebo apps tabulka chybí, vezmi všechny projekty
        $srcProjects = $pdo->query("SELECT * FROM projects");
    }
    $projects = $srcProjects->fetchAll();

    $countP = 0;
    foreach ($projects as $p) {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO time_projects
               (id, name, description, bg_color, invite_code, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $p['id'],
            $p['name'],
            $p['description'] ?? null,
            $p['bg_color']    ?? null,
            $p['invite_code'] ?? null,
            $p['created_by']  ?? null,
            $p['created_at']  ?? date('Y-m-d H:i:s'),
        ]);
        if ($stmt->rowCount()) $countP++;
    }
    $log[] = "time_projects: vloženo $countP nových záznamů (z " . count($projects) . " zdrojových).";
}

// ── 3. Migrace project_members → time_project_members ───────────────────────
if (!tableExists($pdo, 'project_members')) {
    $log[] = 'Tabulka `project_members` neexistuje — přeskočeno.';
} else {
    // Vezmi jen členy projektů, které jsou teď v time_projects
    $members = $pdo->query(
        "SELECT pm.* FROM project_members pm
         WHERE pm.project_id IN (SELECT id FROM time_projects)"
    )->fetchAll();

    $countM = 0;
    foreach ($members as $m) {
        $role = in_array($m['role'], ['owner','admin','member','viewer']) ? $m['role'] : 'member';
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO time_project_members
               (project_id, user_id, role, joined_at)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $m['project_id'],
            $m['user_id'],
            $role,
            $m['joined_at'] ?? $m['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
        if ($stmt->rowCount()) $countM++;
    }
    $log[] = "time_project_members: vloženo $countM nových záznamů (z " . count($members) . " zdrojových).";
}

// ── 4. time_schedules — beze změny ──────────────────────────────────────────
$cnt = $pdo->query("SELECT COUNT(*) AS c FROM time_schedules")->fetch()['c'];
$log[] = "time_schedules: $cnt záznamů (tabulka se nemění — data jsou already here).";

// ── 5. Souhrn ────────────────────────────────────────────────────────────────
echo json_encode([
    'ok'     => true,
    'log'    => $log,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
