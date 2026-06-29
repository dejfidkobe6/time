<?php
/**
 * Migrace / obnova projektů z time_schedules JSON dat.
 * Bezpečné spustit opakovaně — INSERT IGNORE nepřepisuje existující záznamy.
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

// ── Ověření cílových tabulek ────────────────────────────────────────────────
foreach (['time_projects', 'time_project_members', 'time_schedules'] as $t) {
    if (!tableExists($pdo, $t)) {
        $errors[] = "Tabulka $t neexistuje.";
    }
}
if ($errors) {
    echo json_encode(['ok' => false, 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Načti všechny harmonogramy ───────────────────────────────────────────────
$schedules = $pdo->query("SELECT project_id, data, updated_by, created_at FROM time_schedules")->fetchAll();

if (empty($schedules)) {
    echo json_encode(['ok' => true, 'log' => ['time_schedules je prázdná — nic k migraci.']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$countP = 0;
$countM = 0;

foreach ($schedules as $s) {
    $projectId = (int)$s['project_id'];
    $updatedBy = $s['updated_by'] ? (int)$s['updated_by'] : null;
    $createdAt = $s['created_at'] ?? date('Y-m-d H:i:s');

    // Název projektu z JSON dat harmonogramu
    $json = json_decode($s['data'], true);
    $name = trim($json['name'] ?? '') ?: ('Projekt #' . $projectId);

    // Vlož projekt se zachovaným ID (INSERT IGNORE — nepřepíše pokud už existuje)
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO time_projects (id, name, created_by, created_at)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$projectId, $name, $updatedBy, $createdAt]);
    if ($stmt->rowCount()) {
        $countP++;
        $log[] = "time_projects: vložen projekt #$projectId \"$name\".";
    } else {
        $log[] = "time_projects: projekt #$projectId \"$name\" již existuje — přeskočen.";
    }

    // Vlož vlastníka (updated_by = poslední editor = de facto owner)
    if ($updatedBy) {
        $stmt2 = $pdo->prepare(
            "INSERT IGNORE INTO time_project_members (project_id, user_id, role)
             VALUES (?, ?, 'owner')"
        );
        $stmt2->execute([$projectId, $updatedBy]);
        if ($stmt2->rowCount()) {
            $countM++;
            $log[] = "time_project_members: uživatel #$updatedBy přidán jako owner projektu #$projectId.";
        } else {
            $log[] = "time_project_members: uživatel #$updatedBy → projekt #$projectId již existuje — přeskočen.";
        }
    } else {
        $errors[] = "Projekt #$projectId nemá updated_by — vlastník neznámý, přidej ho ručně.";
    }
}

$log[] = "Hotovo: $countP projektů obnoveno, $countM vlastníků přidáno.";

echo json_encode([
    'ok'     => empty($errors),
    'log'    => $log,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
