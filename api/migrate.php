<?php
/**
 * Migrace dat do time_ tabulek.
 * Z plans_projects bere POUZE projekty jejichž ID je v time_schedules.
 * Tím se plans.besix.cz vůbec nedotýká.
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

// ── IDs projektů které Time zná (z harmonogramů) ─────────────────────────────
$scheduleIds = array_column(
    $pdo->query("SELECT DISTINCT project_id FROM time_schedules")->fetchAll(),
    'project_id'
);

if (empty($scheduleIds)) {
    echo json_encode(['ok'=>true,'log'=>['time_schedules je prázdná — nic k migraci.']], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}

$log[] = 'Harmonogramy existují pro project_ids: [' . implode(', ', $scheduleIds) . ']';
$ph    = implode(',', array_fill(0, count($scheduleIds), '?'));

// ── Pomocné funkce ───────────────────────────────────────────────────────────
function insertProject(PDO $pdo, array $p, array &$log): void {
    $id   = (int)$p['id'];
    $name = $p['name'] ?? ('Projekt #' . $id);
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO time_projects (id, name, description, bg_color, invite_code, created_by, created_at)
         VALUES (?,?,?,?,?,?,?)"
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
        ? "✓ time_projects ← #$id \"$name\""
        : "— time_projects: #$id \"$name\" již existuje";
}

function insertMembers(PDO $pdo, int $pid, array $members, array &$log): void {
    foreach ($members as $m) {
        $role = in_array($m['role']??'', ['owner','admin','member','viewer']) ? $m['role'] : 'member';
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO time_project_members (project_id, user_id, role, joined_at)
             VALUES (?,?,?,?)"
        );
        $stmt->execute([
            $pid, (int)$m['user_id'], $role,
            $m['joined_at'] ?? $m['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
        if ($stmt->rowCount())
            $log[] = "  ✓ člen user#{$m['user_id']} ($role) → projekt #$pid";
    }
}

// ── 1. plans_projects — POUZE projekty jejichž ID je v time_schedules ────────
if (tblExists($pdo, 'plans_projects')) {
    $rows = $pdo->prepare("SELECT * FROM plans_projects WHERE id IN ($ph)");
    $rows->execute($scheduleIds);
    $found = $rows->fetchAll();
    $log[] = '--- plans_projects: nalezeno ' . count($found) . ' projektů patřících Time ---';

    foreach ($found as $row) {
        insertProject($pdo, $row, $log);

        // Členové
        $memTbl = tblExists($pdo, 'plans_project_members') ? 'plans_project_members' : null;
        if ($memTbl) {
            $mems = $pdo->prepare("SELECT * FROM $memTbl WHERE project_id = ?");
            $mems->execute([$row['id']]);
            insertMembers($pdo, (int)$row['id'], $mems->fetchAll(), $log);
        }
    }

    // Které IDs z time_schedules nebyly v plans_projects?
    $foundIds = array_column($found, 'id');
    $missing  = array_diff($scheduleIds, $foundIds);
    if ($missing) $log[] = 'Poznámka: IDs ' . implode(', ', $missing) . ' nejsou v plans_projects.';
} else {
    $log[] = 'Tabulka plans_projects neexistuje.';
}

// ── 2. projects (starý název) — POUZE IDs z time_schedules ───────────────────
if (tblExists($pdo, 'projects')) {
    $rows = $pdo->prepare("SELECT * FROM projects WHERE id IN ($ph)");
    $rows->execute($scheduleIds);
    $found = $rows->fetchAll();
    if ($found) {
        $log[] = '--- projects: nalezeno ' . count($found) . ' projektů ---';
        foreach ($found as $row) {
            insertProject($pdo, $row, $log);
            if (tblExists($pdo, 'project_members')) {
                $mems = $pdo->prepare("SELECT * FROM project_members WHERE project_id = ?");
                $mems->execute([$row['id']]);
                insertMembers($pdo, (int)$row['id'], $mems->fetchAll(), $log);
            }
        }
    }
}

// ── 3. Záloha: projekty stále chybějící → rekonstrukce z JSON ────────────────
$existRows = $pdo->prepare("SELECT id FROM time_projects WHERE id IN ($ph)");
$existRows->execute($scheduleIds);
$stillMissing = array_diff($scheduleIds, array_column($existRows->fetchAll(), 'id'));

foreach ($stillMissing as $pid) {
    $s    = $pdo->prepare("SELECT data, updated_by, created_at FROM time_schedules WHERE project_id = ?");
    $s->execute([$pid]);
    $row  = $s->fetch();
    $json = json_decode($row['data'] ?? '{}', true);
    $name = trim($json['name'] ?? '') ?: ('Projekt #' . $pid);

    $pdo->prepare(
        "INSERT IGNORE INTO time_projects (id, name, created_by, created_at) VALUES (?,?,?,?)"
    )->execute([$pid, $name, $row['updated_by'] ?? null, $row['created_at'] ?? date('Y-m-d H:i:s')]);

    if ($row['updated_by']) {
        $pdo->prepare(
            "INSERT IGNORE INTO time_project_members (project_id, user_id, role) VALUES (?,?,'owner')"
        )->execute([$pid, $row['updated_by']]);
    }
    $log[] = "✓ Záloha: #$pid \"$name\" rekonstruován z time_schedules JSON";
}

// ── Souhrn ────────────────────────────────────────────────────────────────────
$cntP = $pdo->query("SELECT COUNT(*) AS c FROM time_projects")->fetch()['c'];
$cntM = $pdo->query("SELECT COUNT(*) AS c FROM time_project_members")->fetch()['c'];
$log[] = "=== Výsledek: time_projects=$cntP, time_project_members=$cntM, time_schedules=" . count($scheduleIds) . " ===";

echo json_encode(['ok'=>empty($errors),'log'=>$log,'errors'=>$errors],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
