<?php
/**
 * Migrace dat do time_ tabulek.
 * Prohledá plans_projects, projects i time_schedules.
 * Bezpečné spustit opakovaně — INSERT IGNORE.
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

function tblExists(PDO $pdo, string $t): bool {
    return (bool)$pdo->query("SHOW TABLES LIKE '$t'")->fetch();
}

// ── Ověř cílové tabulky ──────────────────────────────────────────────────────
foreach (['time_projects','time_project_members','time_schedules'] as $t) {
    if (!tblExists($pdo, $t)) {
        $errors[] = "Cílová tabulka $t neexistuje — nejprve načti aplikaci.";
    }
}
if ($errors) {
    echo json_encode(['ok'=>false,'errors'=>$errors], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Zjisti IDs projektů v time_schedules ─────────────────────────────────────
$scheduleIds = array_column(
    $pdo->query("SELECT DISTINCT project_id FROM time_schedules")->fetchAll(), 'project_id'
);
$log[] = 'time_schedules obsahuje project_ids: [' . implode(', ', $scheduleIds) . ']';

// ── Funkce pro migraci jednoho projektu ──────────────────────────────────────
function migrateProject(PDO $pdo, array $p, array &$log, array &$errors): void {
    $id   = (int)$p['id'];
    $name = $p['name'] ?? ('Projekt #' . $id);

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO time_projects (id, name, description, bg_color, invite_code, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $id, $name,
        $p['description'] ?? null,
        $p['bg_color']    ?? null,
        $p['invite_code'] ?? null,
        $p['created_by']  ?? null,
        $p['created_at']  ?? date('Y-m-d H:i:s'),
    ]);
    $log[] = $stmt->rowCount()
        ? "time_projects ← vložen #$id \"$name\""
        : "time_projects: #$id \"$name\" již existuje";
}

function migrateMembers(PDO $pdo, int $projectId, array $members, array &$log): void {
    foreach ($members as $m) {
        $role = in_array($m['role']??'', ['owner','admin','member','viewer']) ? $m['role'] : 'member';
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO time_project_members (project_id, user_id, role, joined_at)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $projectId, (int)$m['user_id'], $role,
            $m['joined_at'] ?? $m['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
        if ($stmt->rowCount())
            $log[] = "time_project_members ← user #{$m['user_id']} jako $role → projekt #$projectId";
    }
}

// ── 1. Migrace z plans_projects ───────────────────────────────────────────────
if (tblExists($pdo, 'plans_projects')) {
    $log[] = '--- Zdroj: plans_projects ---';
    $cols  = array_column($pdo->query("SHOW COLUMNS FROM plans_projects")->fetchAll(), 'Field');

    // Vezmi VŠECHNY projekty z plans_projects (uživatel si vybere co je jeho)
    $rows = $pdo->query("SELECT * FROM plans_projects")->fetchAll();
    $log[] = 'Nalezeno ' . count($rows) . ' projektů v plans_projects.';

    foreach ($rows as $row) {
        migrateProject($pdo, $row, $log, $errors);

        // Členové z plans_project_members
        if (tblExists($pdo, 'plans_project_members')) {
            $mems = $pdo->prepare("SELECT * FROM plans_project_members WHERE project_id = ?");
            $mems->execute([$row['id']]);
            migrateMembers($pdo, (int)$row['id'], $mems->fetchAll(), $log);
        }

        // Pokud projekt nemá harmonogram v time_schedules, založ prázdný záznam
        if (!in_array((int)$row['id'], $scheduleIds)) {
            $log[] = "Poznámka: projekt #{$row['id']} nemá harmonogram v time_schedules.";
        }
    }
} else {
    $log[] = 'Tabulka plans_projects neexistuje — přeskočeno.';
}

// ── 2. Migrace z projects (starý společný název) ──────────────────────────────
if (tblExists($pdo, 'projects')) {
    $log[] = '--- Zdroj: projects ---';
    $rows  = $pdo->query("SELECT * FROM projects")->fetchAll();
    $log[] = 'Nalezeno ' . count($rows) . ' projektů v projects.';
    foreach ($rows as $row) {
        migrateProject($pdo, $row, $log, $errors);
        if (tblExists($pdo, 'project_members')) {
            $mems = $pdo->prepare("SELECT * FROM project_members WHERE project_id = ?");
            $mems->execute([$row['id']]);
            migrateMembers($pdo, (int)$row['id'], $mems->fetchAll(), $log);
        }
    }
} else {
    $log[] = 'Tabulka projects neexistuje — přeskočeno.';
}

// ── 3. Záloha: projekty z time_schedules bez záznamu v time_projects ─────────
if ($scheduleIds) {
    $ph   = implode(',', array_fill(0, count($scheduleIds), '?'));
    $rows = $pdo->prepare("SELECT id FROM time_projects WHERE id IN ($ph)");
    $rows->execute($scheduleIds);
    $existing = array_column($rows->fetchAll(), 'id');
    $missing  = array_diff($scheduleIds, $existing);

    foreach ($missing as $pid) {
        $sched = $pdo->prepare("SELECT data, updated_by, created_at FROM time_schedules WHERE project_id = ?");
        $sched->execute([$pid]);
        $s     = $sched->fetch();
        $json  = json_decode($s['data'] ?? '{}', true);
        $name  = trim($json['name'] ?? '') ?: ('Projekt #' . $pid);

        $pdo->prepare(
            "INSERT IGNORE INTO time_projects (id, name, created_by, created_at) VALUES (?,?,?,?)"
        )->execute([$pid, $name, $s['updated_by'] ?? null, $s['created_at'] ?? date('Y-m-d H:i:s')]);

        if ($s['updated_by']) {
            $pdo->prepare(
                "INSERT IGNORE INTO time_project_members (project_id, user_id, role) VALUES (?,?,'owner')"
            )->execute([$pid, $s['updated_by']]);
        }
        $log[] = "Záloha: projekt #$pid \"$name\" obnoven z time_schedules.";
    }
}

// ── Výsledek ──────────────────────────────────────────────────────────────────
$cntP = $pdo->query("SELECT COUNT(*) AS c FROM time_projects")->fetch()['c'];
$cntM = $pdo->query("SELECT COUNT(*) AS c FROM time_project_members")->fetch()['c'];
$cntS = $pdo->query("SELECT COUNT(*) AS c FROM time_schedules")->fetch()['c'];
$log[] = "=== Stav tabulek: time_projects=$cntP, time_project_members=$cntM, time_schedules=$cntS ===";

echo json_encode([
    'ok'     => empty($errors),
    'log'    => $log,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
