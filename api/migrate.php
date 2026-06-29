<?php
/**
 * Jednorázová migrace dat z původních sdílených tabulek do time_ tabulek.
 * Bezpečné spustit opakovaně — používá INSERT IGNORE.
 *
 * BEZPEČNOST: Migrují se POUZE projekty prokazatelně patřící aplikaci Time:
 *   1. Primárně: projects.app_id = (id z apps WHERE app_key='time')
 *   2. Záloha:   project_id existující v time_schedules
 *   → Pokud nelze jednoznačně identifikovat, skript SELŽE (nevezme vše).
 *
 * Spustit: https://time.besix.cz/api/migrate.php
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

// ── 0. Ověření cílových tabulek ─────────────────────────────────────────────
foreach (['time_projects', 'time_project_members', 'time_schedules'] as $t) {
    if (!tableExists($pdo, $t)) {
        $errors[] = "Cílová tabulka $t neexistuje — nejprve načti aplikaci (provede CREATE TABLE IF NOT EXISTS).";
    }
}
if ($errors) {
    echo json_encode(['ok' => false, 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 1. Zjisti ID projektů patřících Time aplikaci ───────────────────────────
$timeProjectIds = [];
$idSource       = null;

// Metoda A: přes apps.app_key = 'time'
if (tableExists($pdo, 'apps') && tableExists($pdo, 'projects') && columnExists($pdo, 'projects', 'app_id')) {
    $appRow = $pdo->query("SELECT id FROM apps WHERE app_key = 'time' LIMIT 1")->fetch();
    if ($appRow) {
        $rows = $pdo->prepare("SELECT id FROM projects WHERE app_id = ?");
        $rows->execute([$appRow['id']]);
        $timeProjectIds = array_column($rows->fetchAll(), 'id');
        $idSource = "apps.app_key='time' (app_id=" . $appRow['id'] . ")";
    }
}

// Metoda B: záloha přes time_schedules (harmonogramy nemůžou patřit plans)
if (empty($timeProjectIds) && tableExists($pdo, 'time_schedules')) {
    $rows = $pdo->query("SELECT DISTINCT project_id FROM time_schedules")->fetchAll();
    $timeProjectIds = array_column($rows, 'project_id');
    $idSource = 'time_schedules.project_id';
}

// Žádná metoda nefungovala — BEZPEČNĚ SELŽI, nevezmi nic
if (empty($timeProjectIds)) {
    echo json_encode([
        'ok'     => false,
        'errors' => [
            'Nelze jednoznačně identifikovat projekty aplikace Time.',
            'Tabulka `apps` nebo sloupec `projects.app_id` neexistuje a `time_schedules` je prázdná.',
            'Migrace přerušena — žádná data nebyla změněna.',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$log[] = "Identifikováno " . count($timeProjectIds) . " projektů Time přes: $idSource.";
$log[] = "Project IDs: [" . implode(', ', $timeProjectIds) . "]";

// ── 2. Migrace projects → time_projects ─────────────────────────────────────
if (!tableExists($pdo, 'projects')) {
    $log[] = 'Tabulka `projects` neexistuje — přeskočeno.';
} else {
    $placeholders = implode(',', array_fill(0, count($timeProjectIds), '?'));
    $srcRows = $pdo->prepare("SELECT * FROM projects WHERE id IN ($placeholders)");
    $srcRows->execute($timeProjectIds);
    $projects = $srcRows->fetchAll();

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
    $placeholders = implode(',', array_fill(0, count($timeProjectIds), '?'));
    $members = $pdo->prepare(
        "SELECT * FROM project_members WHERE project_id IN ($placeholders)"
    );
    $members->execute($timeProjectIds);
    $members = $members->fetchAll();

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
$log[] = "time_schedules: $cnt záznamů (nebeze změny — data jsou již zde).";

// ── Výsledek ─────────────────────────────────────────────────────────────────
echo json_encode([
    'ok'     => true,
    'log'    => $log,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
