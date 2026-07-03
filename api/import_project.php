<?php
/**
 * Import projektu z plans_projects do time_ tabulek.
 * Spustit jednorázově, pak smazat.
 *
 * !! BEZPEČNOST: tento skript POUZE ČTE z plans_* tabulek (jen SELECT) !!
 * !! Nikdy NEUPRAVUJE ani NEMAŽE žádná data plans aplikace              !!
 * !! Zapisuje výhradně do: time_projects, time_project_members, time_schedules !!
 *
 * GET  ?action=inspect&project_id=15  — zobrazí data projektu + dostupné task tabulky
 * POST ?action=import&project_id=15   — provede import (přidá se přihlášený uživatel jako owner)
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

$userId    = requireAuth();
$action    = $_GET['action']     ?? 'inspect';
$sourceId  = (int)($_GET['project_id'] ?? 15);

function tblExists(PDO $pdo, string $t): bool {
    return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
}
function tblColumns(PDO $pdo, string $t): array {
    return array_column($pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
}
// Najde první shodný sloupec ze seznamu kandidátů
function findCol(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (in_array($c, $cols, true)) return $c;
    }
    return null;
}

// ── INSPECT ──────────────────────────────────────────────────────────────────
if ($action === 'inspect') {
    $out = [];

    if (!tblExists($pdo, 'plans_projects')) {
        echo json_encode(['ok' => false, 'error' => 'Tabulka plans_projects neexistuje'], JSON_PRETTY_PRINT);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM `plans_projects` WHERE id = ?");
    $stmt->execute([$sourceId]);
    $project = $stmt->fetch();
    $out['plans_projects_row'] = $project;

    // Zkontroluj potenciální task tabulky
    $taskCandidates = [
        'plans_tasks', 'plans_items', 'plans_task', 'tasks',
        'plans_phases', 'plans_columns', 'plans_cards',
    ];
    $found = [];
    foreach ($taskCandidates as $t) {
        if (!tblExists($pdo, $t)) continue;
        $cols = tblColumns($pdo, $t);
        $projectCol = findCol($cols, ['project_id', 'plans_project_id', 'board_id']);
        $count = 0;
        if ($projectCol) {
            $count = (int)$pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE `$projectCol` = ?")
                ->execute([$sourceId]) ? $pdo->query("SELECT COUNT(*) FROM `$t` WHERE `$projectCol` = $sourceId")->fetchColumn() : 0;
        }
        $found[$t] = ['columns' => $cols, 'project_col' => $projectCol, 'rows_for_project' => $count];
    }
    $out['task_tables'] = $found;
    $out['hint'] = 'Pošli POST ?action=import&project_id=' . $sourceId . ' pro import';

    echo json_encode(['ok' => true] + $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── IMPORT ────────────────────────────────────────────────────────────────────
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tblExists($pdo, 'plans_projects')) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Tabulka plans_projects neexistuje']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM `plans_projects` WHERE id = ?");
    $stmt->execute([$sourceId]);
    $src = $stmt->fetch();
    if (!$src) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => "plans_projects #$sourceId nenalezen"]);
        exit;
    }

    $name = trim($src['name'] ?? '') ?: "Projekt #$sourceId";

    // Kontrola duplicity
    $dup = $pdo->prepare("SELECT id FROM time_projects WHERE name = ?");
    $dup->execute([$name]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => "Projekt \"$name\" v Time již existuje."]);
        exit;
    }

    // ── Pokus o import úkolů z plans_tasks ──────────────────────────────────
    $phases = [];
    $nextId = 1;
    $log    = [];

    // Zkusíme plans_tasks (nejpravděpodobnější název)
    $taskTbl = null;
    foreach (['plans_tasks', 'plans_items', 'tasks'] as $t) {
        if (tblExists($pdo, $t)) { $taskTbl = $t; break; }
    }

    if ($taskTbl) {
        $tCols      = tblColumns($pdo, $taskTbl);
        $projectCol = findCol($tCols, ['project_id', 'plans_project_id']);
        $log[]      = "Task tabulka: $taskTbl, projekt sloupec: " . ($projectCol ?? 'nenalezen');

        if ($projectCol) {
            $rows = $pdo->prepare("SELECT * FROM `$taskTbl` WHERE `$projectCol` = ? ORDER BY id");
            $rows->execute([$sourceId]);
            $taskRows = $rows->fetchAll();
            $log[] = count($taskRows) . ' řádků nalezeno';

            // Mapování sloupců
            $colName     = findCol($tCols, ['name', 'title', 'task_name', 'nazev']);
            $colStart    = findCol($tCols, ['start_date', 'start', 'date_start', 'zahajeni']);
            $colEnd      = findCol($tCols, ['end_date', 'end', 'due_date', 'date_end', 'dokonceni', 'deadline']);
            $colProgress = findCol($tCols, ['progress', 'percent', 'completion', 'hotovo']);
            $colNote     = findCol($tCols, ['note', 'notes', 'description', 'poznamka']);
            $colPhase    = findCol($tCols, ['phase', 'phase_id', 'phase_name', 'group', 'group_id', 'faze']);
            $colSort     = findCol($tCols, ['sort', 'sort_order', 'position', 'order']);
            $colBudget   = findCol($tCols, ['budget', 'price', 'cost', 'rozpocet']);

            $log[] = "Mapování: name=$colName start=$colStart end=$colEnd progress=$colProgress phase=$colPhase";

            // Seskupení do fází
            $byPhase = [];
            foreach ($taskRows as $row) {
                $phaseKey = $colPhase ? ($row[$colPhase] ?? 'Bez fáze') : 'Bez fáze';
                $byPhase[$phaseKey][] = $row;
            }

            $phaseColors = [
                '#5B8A5E','#4A7BA7','#C9A84C','#A05C5C','#6B7FA8',
                '#7A8C5A','#A07850','#5C7A8C','#8C5C7A','#5C8C7A',
            ];
            $pi = 0;
            foreach ($byPhase as $phaseName => $phaseTasks) {
                $phId   = 'ph_' . $nextId++;
                $tasks  = [];

                foreach ($phaseTasks as $row) {
                    $taskName = $colName ? trim($row[$colName] ?? '') : '';
                    if ($taskName === '') $taskName = 'Úkol ' . $nextId;

                    $startDate = null;
                    $endDate   = null;
                    if ($colStart && !empty($row[$colStart])) {
                        $startDate = date('Y-m-d', strtotime($row[$colStart]));
                    }
                    if ($colEnd && !empty($row[$colEnd])) {
                        $endDate = date('Y-m-d', strtotime($row[$colEnd]));
                    }

                    $task = [
                        'id'           => 't_' . $nextId++,
                        'name'         => $taskName,
                        'startDate'    => $startDate,
                        'endDate'      => $endDate,
                        'progress'     => $colProgress ? (int)($row[$colProgress] ?? 0) : 0,
                        'predecessors' => [],
                        'type'         => 'task',
                    ];
                    if ($colNote && !empty($row[$colNote])) {
                        $task['note'] = $row[$colNote];
                    }
                    if ($colBudget && !empty($row[$colBudget])) {
                        $task['budget'] = (float)$row[$colBudget];
                    }
                    $tasks[] = $task;
                }

                $phases[] = [
                    'id'        => $phId,
                    'name'      => (string)$phaseName,
                    'collapsed' => false,
                    'bg_color'  => $phaseColors[$pi % count($phaseColors)],
                    'tasks'     => $tasks,
                ];
                $pi++;
            }
        }
    } else {
        $log[] = 'Žádná task tabulka nenalezena — vytváří se prázdný harmonogram';
    }

    // Pokud žádné úkoly, vytvoř aspoň jednu prázdnou fázi
    if (empty($phases)) {
        $phases[] = [
            'id'        => 'ph_1',
            'name'      => 'Fáze 1',
            'collapsed' => false,
            'tasks'     => [],
        ];
        $nextId = 2;
    }

    $schedJson = json_encode([
        'name'   => $name,
        'nextId' => $nextId,
        'phases' => $phases,
    ], JSON_UNESCAPED_UNICODE);

    // ── Uložení do DB ──────────────────────────────────────────────────────────
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            "INSERT INTO time_projects (name, description, bg_color, invite_code, created_by)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $name,
            $src['description'] ?? null,
            $src['bg_color'] ?? null,
            bin2hex(random_bytes(6)),
            $userId,
        ]);
        $newId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO time_project_members (project_id, user_id, role) VALUES (?, ?, 'owner')"
        )->execute([$newId, $userId]);

        $pdo->prepare(
            "INSERT INTO time_schedules (project_id, data, updated_by) VALUES (?, ?, ?)"
        )->execute([$newId, $schedJson, $userId]);

        $pdo->commit();

        $taskCount = array_sum(array_map(fn($ph) => count($ph['tasks']), $phases));

        echo json_encode([
            'ok'         => true,
            'project'    => ['id' => $newId, 'name' => $name, 'role' => 'owner'],
            'phases'     => count($phases),
            'tasks'      => $taskCount,
            'log'        => $log,
            'message'    => "✓ Projekt \"$name\" importován jako Time #$newId ($taskCount úkolů). Soubor import_project.php nyní smaž.",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Použij ?action=inspect nebo POST ?action=import']);
