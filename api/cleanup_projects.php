<?php
/**
 * Odstraní duplicitní projekty se stejným názvem — zachová vždy jen nejstarší (nejnižší ID).
 * Spustit jednorázově: https://time.besix.cz/api/cleanup_projects.php
 * Po spuštění soubor smaž nebo přejmenuj.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/secrets.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=besixcz;charset=utf8mb4',
    'besixcz001',
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$log = [];

// Najdi duplicity — projekty se stejným názvem, zachovat nejnižší ID
$rows = $pdo->query("SELECT name, MIN(id) AS keep_id, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS ids
    FROM time_projects
    GROUP BY name
    HAVING cnt > 1")->fetchAll();

if (!$rows) {
    echo json_encode(['ok' => true, 'log' => ['Žádné duplicity nenalezeny.']]);
    exit;
}

foreach ($rows as $row) {
    $allIds = explode(',', $row['ids']);
    $keepId = (int)$row['keep_id'];
    $deleteIds = array_filter(array_map('intval', $allIds), fn($id) => $id !== $keepId);

    foreach ($deleteIds as $delId) {
        // Přesuň harmonogram na zachovaný projekt (pokud existuje pro mazaný)
        $sched = $pdo->prepare("SELECT id FROM time_schedules WHERE project_id = ?");
        $sched->execute([$delId]);
        if ($sched->fetch()) {
            $keepSched = $pdo->prepare("SELECT id FROM time_schedules WHERE project_id = ?");
            $keepSched->execute([$keepId]);
            if (!$keepSched->fetch()) {
                // Přemluv harmonogram na zachovaný ID
                $pdo->prepare("UPDATE time_schedules SET project_id = ? WHERE project_id = ?")
                    ->execute([$keepId, $delId]);
                $log[] = "↷ Harmonogram přesunut z #$delId → #$keepId";
            } else {
                $pdo->prepare("DELETE FROM time_schedules WHERE project_id = ?")->execute([$delId]);
                $log[] = "✗ Duplicitní harmonogram #$delId smazán (zachovaný #$keepId již má harmonogram)";
            }
        }
        $pdo->prepare("DELETE FROM time_project_members WHERE project_id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM time_projects WHERE id = ?")->execute([$delId]);
        $log[] = "✗ Odstraněn duplicitní projekt #$delId \"{$row['name']}\" (zachován #$keepId)";
    }
}

$cntP = $pdo->query("SELECT COUNT(*) AS c FROM time_projects")->fetch()['c'];
$log[] = "=== Výsledek: time_projects=$cntP ===";

echo json_encode(['ok' => true, 'log' => $log], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
