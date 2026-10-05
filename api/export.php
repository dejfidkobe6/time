<?php
/**
 * BeSix Time — read-only JSON export harmonogramu
 *
 * GET /api/export.php?project=<ID|název>[&from=YYYY-MM-DD][&to=YYYY-MM-DD]
 * Token: hlavička "Authorization: Bearer <TOKEN>" (preferováno)
 *        nebo query parametr "token=<TOKEN>" (přechodně)
 */
declare(strict_types=1);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* ── Pouze GET ──────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

/* ── Secrets (token + DB heslo) ─────────────────────────────────────────── */
$secretsFile = __DIR__ . '/secrets.php';
if (!file_exists($secretsFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'server_configuration_error']);
    exit;
}
require_once $secretsFile;   // definuje EXPORT_TOKEN a DB_PASS

/* ── Autorizace tokenem ─────────────────────────────────────────────────── */
// Preferujeme Bearer hlavičku; query ?token= přijímáme přechodně.
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
    $requestToken = $m[1];
} else {
    $requestToken = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
}

function rejectUnauthorized(): never {
    // Pevné zpomalení při chybném tokenu — brzdí brute-force
    usleep(300_000);
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

if (
    !defined('EXPORT_TOKEN') ||
    !is_string(EXPORT_TOKEN) ||
    strlen(EXPORT_TOKEN) < 32 ||
    $requestToken === '' ||
    !hash_equals(EXPORT_TOKEN, $requestToken)
) {
    rejectUnauthorized();
}

/* ── Parametry ──────────────────────────────────────────────────────────── */
$projectParam = is_string($_GET['project'] ?? null) ? trim($_GET['project']) : '';
if ($projectParam === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_parameter_project']);
    exit;
}

$fromParam = is_string($_GET['from'] ?? null) ? $_GET['from'] : '';
$toParam   = is_string($_GET['to']   ?? null) ? $_GET['to']   : '';

// Validace datumových parametrů
function validDate(string $d): bool {
    if ($d === '') return false;
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}
$filterDates = false;
$filterFrom  = '';
$filterTo    = '';
if ($fromParam !== '' || $toParam !== '') {
    if (!validDate($fromParam) && $fromParam !== '') {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_from_date']);
        exit;
    }
    if (!validDate($toParam) && $toParam !== '') {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_to_date']);
        exit;
    }
    $filterDates = true;
    $filterFrom  = $fromParam !== '' ? $fromParam : '0000-01-01';
    $filterTo    = $toParam   !== '' ? $toParam   : '9999-12-31';
}

/* ── DB – minimální připojení bez session ───────────────────────────────── */
try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;dbname=besixcz;charset=utf8mb4',
        'besixcz001',
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('export.php DB connect: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'database_unavailable']);
    exit;
}

/* ── Načtení projektu (ID nebo přesný název) ────────────────────────────── */
try {
    if (ctype_digit($projectParam)) {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.name, s.data
             FROM time_projects p
             LEFT JOIN time_schedules s ON s.project_id = p.id
             WHERE p.id = ?
             LIMIT 1'
        );
        $stmt->execute([(int)$projectParam]);
        $rows = $stmt->fetchAll();
    } else {
        // LIMIT 2 — při shodě dvou názvů vrátíme 409 místo náhodného řádku
        $stmt = $pdo->prepare(
            'SELECT p.id, p.name, s.data
             FROM time_projects p
             LEFT JOIN time_schedules s ON s.project_id = p.id
             WHERE p.name = ?
             ORDER BY p.id
             LIMIT 2'
        );
        $stmt->execute([$projectParam]);
        $rows = $stmt->fetchAll();
        if (count($rows) > 1) {
            http_response_code(409);
            echo json_encode(['error' => 'ambiguous_project_name']);
            exit;
        }
    }
    $row = $rows[0] ?? null;
} catch (PDOException $e) {
    error_log('export.php DB query: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'database_error']);
    exit;
}

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'project_not_found']);
    exit;
}

$projectId   = (int)$row['id'];
$projectName = $row['name'];
$rawData     = $row['data'] ?? null;

/* ── Parsování JSON harmonogramu ────────────────────────────────────────── */
$schedule = null;
if ($rawData !== null) {
    $schedule = json_decode($rawData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode(['error' => 'invalid_schedule_data']);
        exit;
    }
}
$phases = (is_array($schedule) && isset($schedule['phases'])) ? $schedule['phases'] : [];

/* ── Výpočet datumů summary úkolů z dětí (fallback pokud nejsou uloženy) ── */
function computeSummaryDates(array &$task): void {
    if (($task['type'] ?? '') !== 'summary' || empty($task['children'])) return;
    foreach ($task['children'] as &$child) {
        if (($child['type'] ?? '') === 'summary') computeSummaryDates($child);
    }
    $starts = array_filter(array_column($task['children'], 'startDate'));
    $ends   = array_filter(array_column($task['children'], 'endDate'));
    if ($starts && ($task['startDate'] ?? '') === '') {
        $task['startDate'] = min($starts);
    }
    if ($ends && ($task['endDate'] ?? '') === '') {
        $task['endDate'] = max($ends);
    }
}
foreach ($phases as &$ph) {
    foreach ($ph['tasks'] ?? [] as &$t) {
        if (($t['type'] ?? '') === 'summary') computeSummaryDates($t);
    }
}
unset($ph, $t);

/* ── Přiřazení integer ID fázím a úkolům ───────────────────────────────── */
$phaseIdMap = [];   // phaseUUID → int
$taskIdMap  = [];   // taskUUID  → int
$phaseCounter = 0;
$taskCounter  = 0;

foreach ($phases as $ph) {
    $phaseCounter++;
    $phaseIdMap[$ph['id']] = $phaseCounter;

    $walkQueue = function (array $tasks) use (&$taskCounter, &$taskIdMap, &$walkQueue): void {
        foreach ($tasks as $t) {
            $taskCounter++;
            $taskIdMap[$t['id']] = $taskCounter;
            if (!empty($t['children'])) {
                $walkQueue($t['children']);
            }
        }
    };
    $walkQueue($ph['tasks'] ?? []);
}

/* ── Sestavení výstupu ──────────────────────────────────────────────────── */
$oddily = [];
$ukoly  = [];

foreach ($phases as $phIdx => $ph) {
    $phIntId   = $phaseIdMap[$ph['id']] ?? ($phIdx + 1);
    $phaseName = $ph['name'] ?? '';

    $taskOrderInPhase = 0;
    $included         = false;

    $flattenTasks = function (
        array $tasks,
        int   $phIntId,
        string $phaseName
    ) use (
        &$flattenTasks,
        &$ukoly,
        &$taskOrderInPhase,
        &$included,
        &$taskIdMap,
        $filterDates,
        $filterFrom,
        $filterTo
    ): void {
        foreach ($tasks as $t) {
            $isMilestone = ($t['type'] ?? '') === 'milestone';
            $start       = $t['startDate'] ?? '';
            $end         = $t['endDate']   ?? ($start);
            if (!$isMilestone && $start !== '' && $end === '') {
                $end = $start;
            }
            if ($isMilestone && $end === '') {
                $end = $start;
            }

            if ($filterDates) {
                $taskStart = $start !== '' ? $start : '0000-01-01';
                $taskEnd   = $end   !== '' ? $end   : '9999-12-31';
                if ($taskStart > $filterTo || $taskEnd < $filterFrom) {
                    if (!empty($t['children'])) {
                        $flattenTasks($t['children'], $phIntId, $phaseName);
                    }
                    continue;
                }
            }

            $taskOrderInPhase++;
            $included  = true;
            $taskIntId = $taskIdMap[$t['id']] ?? 0;

            $predchudci = [];
            foreach ($t['predecessors'] ?? [] as $p) {
                $predUuid = $p['taskId'] ?? '';
                if (isset($taskIdMap[$predUuid])) {
                    $predchudci[] = $taskIdMap[$predUuid];
                }
            }

            $ukoly[] = [
                'id'         => $taskIntId,
                'oddil_id'   => $phIntId,
                'oddil'      => $phaseName,
                'nazev'      => $t['name'] ?? '',
                'start'      => $start,
                'konec'      => $end,
                'hotovo'     => min(100, max(0, (int)($t['progress'] ?? 0))),
                'milnik'     => $isMilestone || ($start !== '' && $start === $end),
                'predchudci' => $predchudci,
                'poradi'     => $taskOrderInPhase,
                'poznamka'   => $t['note'] ?? '',
            ];

            if (!empty($t['children'])) {
                $flattenTasks($t['children'], $phIntId, $phaseName);
            }
        }
    };

    $flattenTasks($ph['tasks'] ?? [], $phIntId, $phaseName);

    if ($included || !$filterDates) {
        $oddily[] = [
            'id'     => $phIntId,
            'nazev'  => $phaseName,
            'poradi' => $phIdx + 1,
        ];
    }
}

/* ── Výstup ─────────────────────────────────────────────────────────────── */
$output = [
    'projekt'    => $projectName,
    'projekt_id' => $projectId,
    'export'     => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'oddily'     => $oddily,
    'ukoly'      => $ukoly,
];

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
