<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/metrics_glossary.php';
require_once 'includes/lactate_zones.php';
require_once 'includes/nat_tests.php';
require_login();

$pdo = get_db_connection();

$athlete_id = isset($_GET['id']) ? $_GET['id'] : '';
if ($athlete_id === '') {
    header('Location: dashboard.php');
    exit;
}

// Период за графиките (дни)
$allowed_periods = [30, 90, 180];
$period = isset($_GET['period']) ? (int)$_GET['period'] : 90;
if (!in_array($period, $allowed_periods, true)) {
    $period = 90;
}
$since = date('Y-m-d', strtotime("-$period days"));

// Данни за атлета
$stmt = $pdo->prepare("
    SELECT athlete_name FROM daily_metrics
    WHERE athlete_id = ? LIMIT 1
");
$stmt->execute([$athlete_id]);
$athlete_row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$athlete_row) {
    header('Location: dashboard.php');
    exit;
}
$athlete_name = $athlete_row['athlete_name'];

// Дневни метрики за периода
$stmt = $pdo->prepare("
    SELECT date, ctl, atl, acwr, acwr_status, hrv, sleep_secs, stress, resting_hr
    FROM daily_metrics
    WHERE athlete_id = ? AND date >= ?
    ORDER BY date ASC
");
$stmt->execute([$athlete_id, $since]);
$metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Последен запис (за header тайловете)
$latest = $metrics ? $metrics[count($metrics) - 1] : null;

// История на ранкинга за периода.
// world_triathlon се пълни с World Triathlon ID, а daily_metrics — с intervals ID,
// затова съединяваме по athlete_name (и двете идват от config/athletes.yaml).
$stmt = $pdo->prepare("
    SELECT date(fetched_at) AS date, world_ranking, regional_ranking
    FROM world_triathlon
    WHERE athlete_name = ? AND date(fetched_at) >= ?
    GROUP BY date(fetched_at)
    ORDER BY fetched_at ASC
");
$stmt->execute([$athlete_name, $since]);
$rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Тайловете показват последната налична стойност независимо от избрания период
$stmt = $pdo->prepare("
    SELECT world_ranking, regional_ranking
    FROM world_triathlon
    WHERE athlete_name = ?
    ORDER BY fetched_at DESC LIMIT 1
");
$stmt->execute([$athlete_name]);
$latest_ranking = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

// История на алармите
$stmt = $pdo->prepare("
    SELECT event_date, alert_type, message, detected_at
    FROM alert_events
    WHERE athlete_id = ?
    ORDER BY event_date DESC, detected_at DESC
    LIMIT 50
");
$stmt->execute([$athlete_id]);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Резултати от състезания (world_triathlon_results се пълни от
// fetch_world_triathlon.py; join по athlete_name — същата причина като
// world_triathlon по-горе: таблицата ползва World Triathlon ID).
// Таблицата може още да не съществува при стара база — прескачаме тихо.
$race_results = [];
try {
    $stmt = $pdo->prepare("
        SELECT event_date, event_title, position, total_time, event_country,
               swim_split, t1_split, bike_split, t2_split, run_split,
               swim_position, t1_position, bike_position, t2_position, run_position
        FROM world_triathlon_results
        WHERE athlete_name = ? AND event_date IS NOT NULL
        ORDER BY event_date DESC
    ");
    $stmt->execute([$athlete_name]);
    $race_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $race_results = [];
}

// Местни състезания (fetch_local_results.py, от Google Sheet; join по
// athlete_name по същата причина като world_triathlon/lactate_tests по-долу —
// Sheet-ът не познава нито intervals, нито World Triathlon ID).
// Таблицата може още да не съществува при стара база — прескачаме тихо.
$local_results = [];
try {
    $stmt = $pdo->prepare("
        SELECT lr.*, le.event_date, le.name AS event_name, le.city, le.organizer, le.source_url
        FROM local_results lr
        JOIN local_events le ON lr.event_id = le.event_id
        WHERE lr.athlete_name = ?
        ORDER BY le.event_date DESC
    ");
    $stmt->execute([$athlete_name]);
    $local_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $local_results = [];
}

$local_result_years = array_values(array_unique(array_map(
    fn($r) => substr($r['event_date'], 0, 4),
    $local_results
)));
$local_default_year = $local_result_years[0] ?? null;

// Лактатни тестове (fetch_lab_data.py, от Google Sheet; join по athlete_name
// по същата причина като world_triathlon по-горе — Sheet-ът не познава intervals ID).
// Таблицата може още да не съществува при стара база — прескачаме тихо.
$lactate_tests = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM lactate_tests
        WHERE athlete_name = ?
        ORDER BY test_date DESC
    ");
    $stmt->execute([$athlete_name]);
    $lactate_tests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $lactate_tests = [];
}

// Национални функционални тестове (НЦ) — fetch_nat_tests.py, от Google Sheet;
// join по athlete_name по същата причина като lactate_tests по-горе.
// Отделно от lactate_tests нарочно — протоколите не са сравними
// (nat_tests_comparable() в includes/nat_tests.php).
$nat_tests = [];
$nat_protocols = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM nat_functional_tests
        WHERE athlete_name = ?
        ORDER BY test_date DESC
    ");
    $stmt->execute([$athlete_name]);
    $nat_tests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT * FROM nat_test_protocols");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $nat_protocols[$p['protocol']] = $p;
    }
} catch (PDOException $e) {
    $nat_tests = [];
    $nat_protocols = [];
}
$nat_tests_by_protocol = nat_tests_group_by_protocol($nat_tests);

// Общ, хронологично сортиран списък дати за двете графики (VO2max/kg,
// W_max/kg) — всеки протокол получава собствена серия със null там, където
// няма тест на тази дата, по същия принцип като HRV/сън по-горе в чарта.
$nat_chart_dates = array_values(array_unique(array_map(fn($t) => $t['test_date'], $nat_tests)));
sort($nat_chart_dates);
$nat_chart_series = [];
foreach ($nat_tests_by_protocol as $protocol => $tests) {
    $by_date = [];
    foreach ($tests as $t) {
        $by_date[$t['test_date']] = $t;
    }
    $vo2 = []; $wmax = [];
    foreach ($nat_chart_dates as $d) {
        $vo2[]  = isset($by_date[$d]) && $by_date[$d]['vo2max_kg'] !== null ? (float)$by_date[$d]['vo2max_kg'] : null;
        $wmax[] = isset($by_date[$d]) && $by_date[$d]['w_max_kg'] !== null ? (float)$by_date[$d]['w_max_kg'] : null;
    }
    $nat_chart_series[$protocol] = [
        'label' => $nat_protocols[$protocol]['device'] ?? $protocol,
        'vo2max_kg' => $vo2,
        'w_max_kg' => $wmax,
    ];
}

// Наличните години (за бутоните), най-новата първа = избрана по подразбиране
$result_years = array_values(array_unique(array_map(
    fn($r) => substr($r['event_date'], 0, 4),
    $race_results
)));
$default_year = $result_years[0] ?? null;

// Последни 14 дни за таблицата (най-новите първи)
$table_rows = array_reverse(array_slice($metrics, -14));

function status_badge($status) {
    $colors = ['ok' => '#2e7d32', 'low' => '#f57c00', 'high' => '#c62828', 'no_data' => '#999'];
    $labels = ['ok' => 'Нормално', 'low' => 'Детрениране', 'high' => 'Риск', 'no_data' => 'Няма данни'];
    $color = $colors[$status] ?? '#999';
    $label = $labels[$status] ?? $status;
    return "<span class=\"badge\" style=\"background:$color;\">$label</span>";
}

function fmt($value, $decimals = 1) {
    return $value === null ? '—' : number_format((float)$value, $decimals);
}

// Етикети на етапите по дисциплина — огледало на LEG_MAP в fetch_local_results.py.
// Трите резултатни таба се сливат в generic leg1/leg2/leg3 в базата именно за да
// не иска нова таблица/нов PHP код всеки път при нова дисциплина — тук е
// единственото място, което превежда позицията обратно в четимо име на етапа.
function local_leg_labels($sport) {
    $map = [
        'triathlon' => ['leg1' => 'Плуване',  'leg2' => 'Колело', 'leg3' => 'Бягане'],
        'duathlon'  => ['leg1' => 'Бягане 1',  'leg2' => 'Колело', 'leg3' => 'Бягане 2'],
        'aquathlon' => ['leg1' => 'Бягане 1',  'leg2' => 'Плуване', 'leg3' => 'Бягане 2'],
    ];
    return $map[$sport] ?? ['leg1' => 'Етап 1', 'leg2' => 'Етап 2', 'leg3' => 'Етап 3'];
}

function local_sport_label($sport) {
    $labels = ['triathlon' => 'Триатлон', 'duathlon' => 'Дуатлон', 'aquathlon' => 'Акватлон'];
    return $labels[$sport] ?? $sport;
}

// LT1/LT2 за overview таблицата: ръчна стойност от Sheet-а както си е,
// естимирана (интерполирана) стойност — с приглушен "(est.)" суфикс,
// за да не се бъркат двете на пръв поглед.
function fmt_lt($value, $estimated) {
    if ($value === null) return '—';
    $text = round($value) . 'W';
    if ($estimated) {
        $text .= ' <span class="lt-est">(est.)</span>';
    }
    return $text;
}

// lactate_step_watts() идва от includes/lactate_zones.php — споделена и с
// api_lactate.php/lactate_analysis.php, за да не се разминат изчисленията.

// Traffic-light праг за лактат: <2 аеробно, 2-4 преходна зона, >4 анаеробно.
function lactate_level_class($la) {
    if ($la === null) return '';
    if ($la < 2) return 'lt-low';
    if ($la <= 4) return 'lt-mid';
    return 'lt-high';
}

// Стъпката с мощност, най-близка до даден LT праг (за оцветяване на колоната).
function lactate_nearest_step($active_steps, $protocol, $target_watts) {
    if ($target_watts === null || !$active_steps) return null;
    $best = null;
    $best_diff = null;
    foreach ($active_steps as $i) {
        $w = lactate_step_watts($protocol, $i);
        if ($w === null) continue;
        $diff = abs($w - $target_watts);
        if ($best_diff === null || $diff < $best_diff) {
            $best_diff = $diff;
            $best = $i;
        }
    }
    return $best;
}

// Данни за Chart.js
$chart_data = [
    'labels'      => array_column($metrics, 'date'),
    'ctl'         => array_map(fn($r) => $r['ctl'] !== null ? (float)$r['ctl'] : null, $metrics),
    'atl'         => array_map(fn($r) => $r['atl'] !== null ? (float)$r['atl'] : null, $metrics),
    'acwr'        => array_map(fn($r) => $r['acwr'] !== null ? (float)$r['acwr'] : null, $metrics),
    'hrv'         => array_map(fn($r) => $r['hrv'] !== null ? (float)$r['hrv'] : null, $metrics),
    'sleep'       => array_map(fn($r) => $r['sleep_secs'] !== null ? round($r['sleep_secs'] / 3600, 2) : null, $metrics),
    'rankLabels'  => array_column($rankings, 'date'),
    'world'       => array_map(fn($r) => $r['world_ranking'] !== null ? (int)$r['world_ranking'] : null, $rankings),
    'regional'    => array_map(fn($r) => $r['regional_ranking'] !== null ? (int)$r['regional_ranking'] : null, $rankings),
];

$alert_type_labels = [
    'acwr_high'        => 'Висок ACWR',
    'acwr_low'         => 'Нисък ACWR',
    'acwr_normalized'  => 'ACWR нормализиран',
    'comment_keyword'  => 'Оплакване в коментар',
    'late_start'       => 'Късна тренировка',
    'readiness_sleep'  => 'Недостатъчен сън',
    'readiness_hrv'    => 'Пад в HRV',
    'readiness_stress' => 'Повишен стрес',
];
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($athlete_name) ?> — Athlete Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --series-1: #2a78d6;   /* синьо — основна серия */
            --series-2: #1baf7a;   /* аква — втора серия */
            --ink: #0b0b0b;
            --ink-2: #52514e;
            --muted: #898781;
            --grid: #e1e0d9;
            --surface: #ffffff;
        }
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: var(--ink); }
        a { color: #2250e3; text-decoration: none; }
        .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 6px; }
        h1 { margin: 0; font-size: 26px; }
        .badge { color: white; padding: 4px 12px; border-radius: 12px; font-size: 14px; vertical-align: middle; }
        .subheader { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
        .period-nav a { padding: 5px 12px; border-radius: 14px; font-size: 14px; }
        .period-nav a.active { background: #2250e3; color: white; }
        .year-nav { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
        .year-nav button { padding: 5px 12px; border-radius: 14px; font-size: 14px; border: none; background: #eceae4; color: var(--ink-2); cursor: pointer; }
        .year-nav button.active { background: #2250e3; color: white; }
        /* Резултати по година — WT-results визуален език */
        #results-table td { padding-top: 10px; padding-bottom: 10px; }
        .result-row { cursor: pointer; }
        .result-row td.event-date { color: var(--ink-2); }
        .result-row td.event-name { font-weight: 600; color: var(--ink); }
        .result-row td.total-time { font-weight: 700; font-size: 15px; }
        .result-row:hover td, .result-row:focus-visible td { background: #f5f7ff; }
        .result-row.open td { background: #eef1fb; border-bottom-color: transparent; }
        .pos-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 28px; padding: 0 9px; border-radius: 8px; font-weight: 700; font-size: 15px; background: #eef1fb; color: #2250e3; }
        .pos-badge.pos-gold   { background: #f6ecc8; color: #8a6d1a; }
        .pos-badge.pos-silver { background: #ececec; color: #5f5f5f; }
        .pos-badge.pos-bronze { background: #f3e2d0; color: #8a5a2a; }
        .pos-badge.pos-dnx    { background: #f9e9e9; color: #b03a3a; font-size: 12px; letter-spacing: 0.03em; }
        .result-detail td { background: transparent; padding: 0 0 14px; }
        .split-panel { background: #fafbff; border: 1px solid #e4e8f7; border-left: 3px solid #2250e3; border-radius: 10px; padding: 14px 18px; }
        .splits-grid { display: grid; grid-template-columns: repeat(5, minmax(90px, 1fr)); gap: 12px 18px; }
        .split-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); margin-bottom: 3px; }
        .split-time { font-size: 17px; font-weight: 600; font-variant-numeric: tabular-nums; color: var(--ink); }
        .split-time--empty { color: var(--muted); font-weight: 400; }
        /* Позиция в дисциплината под времето — WT-results стил: "(3)" / "(=1)" */
        .split-pos { font-size: 12px; color: var(--muted); margin-top: 2px; font-variant-numeric: tabular-nums; }
        .no-splits { color: var(--muted); font-style: italic; font-size: 13px; }

        /* Лактатен тест: header лента с дата/FTP/W-kg над таблицата */
        .lactate-panel-header { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 16px; margin-bottom: 8px; }
        .lactate-panel-header .lt-date { font-size: 15px; font-weight: 700; color: var(--ink); }
        .lactate-panel-header .lt-stat { font-size: 13px; color: var(--ink-2); }
        .lactate-panel-header .lt-stat strong { color: #2250e3; font-size: 14px; }
        .lactate-bio-line { margin: 0 0 14px; color: var(--muted); font-size: 12px; }

        /* Класически лактатен отчет: Мощност/HR/Lactate като редове, стъпките като колони.
           Първата колона (row label) е sticky, за да остане видима при хоризонтален scroll. */
        .lactate-table-wrap { overflow-x: auto; border-radius: 8px; border: 1px solid var(--grid); }
        .lactate-steps-table { border-collapse: collapse; font-size: 12px; width: 100%; }
        .lactate-steps-table th {
            position: sticky; left: 0; z-index: 1;
            text-align: left; font-weight: 700; padding: 8px 14px 8px 10px;
            white-space: nowrap; color: var(--ink-2);
        }
        .lactate-steps-table td {
            text-align: center; min-width: 54px; padding: 7px 8px;
            font-variant-numeric: tabular-nums; white-space: nowrap;
            border-left: 1px solid var(--grid);
        }
        /* Мощност: удебелен, header-подобен фон, отделен с акцентна линия отдолу */
        .lt-row-watts { background: #eef1fb; }
        .lt-row-watts th { background: #eef1fb; color: var(--ink); }
        .lt-row-watts td { font-weight: 700; color: var(--ink); border-bottom: 2px solid #2250e3; padding-bottom: 6px; }
        /* HR: среден тон, тънки zebra колони за хоризонтално четене */
        .lt-row-hr th { background: var(--surface); color: var(--ink-2); font-weight: 600; }
        .lt-row-hr td { color: var(--ink-2); font-weight: 500; }
        .lt-row-hr td:nth-child(even) { background: #fafbff; }
        /* Lactate: badge-ове вместо суров текст, traffic-light цветове */
        .lt-row-la th { background: var(--surface); color: var(--ink-2); font-weight: 600; }
        .lt-row-la td { padding: 5px 6px; }
        .lt-row-la td:nth-child(even) { background: #fafbff; }
        .lt-badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 38px; padding: 3px 8px; border-radius: 7px;
            font-weight: 700; font-size: 12px; font-variant-numeric: tabular-nums;
        }
        .lt-badge.lt-low  { background: #e5f3e6; color: #2e7d32; }
        .lt-badge.lt-mid  { background: #fdecd2; color: #b8600a; }
        .lt-badge.lt-high { background: #fbe2e2; color: #c62828; }
        /* LT1/LT2 маркери: цветно подчертаване на цялата колона + малък таг под ватовете */
        .lactate-steps-table td.lt1-col { box-shadow: inset 0 0 0 999px rgba(245, 124, 0, 0.10); }
        .lactate-steps-table td.lt2-col { box-shadow: inset 0 0 0 999px rgba(198, 40, 40, 0.10); }
        .lt-row-watts td.lt1-col { border-bottom-color: #f57c00; }
        .lt-row-watts td.lt2-col { border-bottom-color: #c62828; }
        .lt-tag {
            display: block; margin: 2px auto 0; width: fit-content;
            font-size: 9px; font-weight: 700; letter-spacing: 0.03em;
            border-radius: 4px; padding: 1px 5px; color: white;
        }
        .lt-tag.lt1-tag { background: #f57c00; }
        .lt-tag.lt2-tag { background: #c62828; }
        .lt-est { font-style: italic; color: var(--muted); font-weight: 400; font-size: 11px; }
        .lt-analysis-btn {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            background: #eef1fb; color: #2250e3; font-size: 12px; font-weight: 600;
            white-space: nowrap;
        }
        .lt-analysis-btn:hover { background: #2250e3; color: #ffffff; }
        @media (max-width: 480px) {
            .lactate-steps-table { font-size: 11px; }
            .lactate-steps-table td { padding: 5px 5px; min-width: 44px; }
            .lactate-steps-table th { padding: 6px 10px 6px 6px; }
        }
        @media (max-width: 560px) {
            .splits-grid { grid-template-columns: repeat(2, 1fr); }
            .split-time { font-size: 16px; }
            .split-panel { padding: 12px 14px; }
        }
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .tile { background: var(--surface); border-radius: 8px; padding: 14px 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .tile .label { font-size: 13px; color: var(--ink-2); }
        .tile .value { font-size: 26px; font-weight: 600; margin-top: 2px; }
        .charts { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .chart-card { background: var(--surface); border-radius: 8px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .chart-card h2 { margin: 0 0 4px; font-size: 16px; color: var(--ink); }
        .chart-card .hint, .hint { font-size: 12px; color: var(--muted); margin: 0 0 10px; }
        .chart-wrap { position: relative; height: 240px; }
        .tables { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; }
        .table-card { background: var(--surface); border-radius: 8px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow-x: auto; }
        .table-card h2 { margin: 0 0 10px; font-size: 16px; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; }
        th { text-align: left; color: var(--ink-2); font-weight: 600; padding: 6px 10px 6px 0; border-bottom: 1px solid var(--grid); white-space: nowrap; }
        td { padding: 6px 10px 6px 0; border-bottom: 1px solid #f0efec; font-variant-numeric: tabular-nums; white-space: nowrap; }
        td.msg { white-space: normal; }
        .empty { color: var(--muted); font-style: italic; font-size: 13px; }
        @media (max-width: 480px) {
            body { padding: 12px; }
            .chart-wrap { height: 200px; }
            .period-nav a { padding: 4px 8px; font-size: 13px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>
            <?= htmlspecialchars($athlete_name) ?>
            <?= $latest ? status_badge($latest['acwr_status']) : '' ?>
        </h1>
        <a href="dashboard.php">&larr; Всички атлети</a>
    </div>
    <div class="subheader">
        <span style="color:var(--ink-2);font-size:14px;">
            Последни данни: <?= $latest ? htmlspecialchars($latest['date']) : '—' ?>
        </span>
        <nav class="period-nav">
            <?php foreach ($allowed_periods as $p): ?>
                <a href="?id=<?= urlencode($athlete_id) ?>&amp;period=<?= $p ?>"
                   class="<?= $p === $period ? 'active' : '' ?>"><?= $p ?> дни</a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="tiles">
        <div class="tile"><div class="label">ACWR</div><div class="value"><?= $latest ? fmt($latest['acwr'], 2) : '—' ?></div></div>
        <div class="tile"><div class="label">CTL (Fitness)</div><div class="value"><?= $latest ? fmt($latest['ctl']) : '—' ?></div></div>
        <div class="tile"><div class="label">ATL (Fatigue)</div><div class="value"><?= $latest ? fmt($latest['atl']) : '—' ?></div></div>
        <div class="tile"><div class="label">HRV</div><div class="value"><?= $latest ? fmt($latest['hrv']) : '—' ?></div></div>
        <div class="tile"><div class="label">World Ranking</div><div class="value"><?= $latest_ranking && $latest_ranking['world_ranking'] !== null ? '#' . (int)$latest_ranking['world_ranking'] : '—' ?></div></div>
        <div class="tile"><div class="label">Regional Ranking</div><div class="value"><?= $latest_ranking && $latest_ranking['regional_ranking'] !== null ? '#' . (int)$latest_ranking['regional_ranking'] : '—' ?></div></div>
    </div>

    <div class="charts">
        <div class="chart-card">
            <h2>ACWR</h2>
            <p class="hint">Сивата зона (0.8–1.3) е оптималният диапазон</p>
            <div class="chart-wrap"><canvas id="chartAcwr"></canvas></div>
        </div>
        <div class="chart-card">
            <h2>Тренировъчно натоварване</h2>
            <p class="hint">CTL = хронично (форма), ATL = остро (умора)</p>
            <div class="chart-wrap"><canvas id="chartLoad"></canvas></div>
        </div>
        <div class="chart-card">
            <h2>HRV</h2>
            <p class="hint">Вариабилност на сърдечния ритъм (ms)</p>
            <div class="chart-wrap"><canvas id="chartHrv"></canvas></div>
        </div>
        <div class="chart-card">
            <h2>Сън</h2>
            <p class="hint">Часове сън на нощ</p>
            <div class="chart-wrap"><canvas id="chartSleep"></canvas></div>
        </div>
        <div class="chart-card">
            <h2>World Ranking</h2>
            <p class="hint">По-ниско = по-добре (оста е обърната)</p>
            <div class="chart-wrap"><canvas id="chartWorld"></canvas></div>
        </div>
        <div class="chart-card">
            <h2>Regional Ranking</h2>
            <p class="hint">По-ниско = по-добре (оста е обърната)</p>
            <div class="chart-wrap"><canvas id="chartRegional"></canvas></div>
        </div>
    </div>

    <div class="tables">
        <div class="table-card">
            <h2>Последни 14 дни</h2>
            <?php if ($table_rows): ?>
            <table>
                <thead>
                    <tr>
                        <th>Дата</th><th>ACWR</th><th>Статус</th><th>CTL</th><th>ATL</th>
                        <th>HRV</th><th>Сън (ч)</th><th>Пулс покой</th><th>Стрес</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table_rows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['date']) ?></td>
                        <td><?= fmt($row['acwr'], 2) ?></td>
                        <td><?= status_badge($row['acwr_status']) ?></td>
                        <td><?= fmt($row['ctl']) ?></td>
                        <td><?= fmt($row['atl']) ?></td>
                        <td><?= fmt($row['hrv']) ?></td>
                        <td><?= $row['sleep_secs'] !== null ? number_format($row['sleep_secs'] / 3600, 1) : '—' ?></td>
                        <td><?= fmt($row['resting_hr']) ?></td>
                        <td><?= fmt($row['stress']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p class="empty">Няма данни</p>
            <?php endif; ?>
        </div>

        <div class="table-card">
            <h2>История на алармите</h2>
            <?php if ($alerts): ?>
            <table>
                <thead>
                    <tr><th>Дата</th><th>Тип</th><th>Съобщение</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($alerts as $alert): ?>
                    <tr>
                        <td><?= htmlspecialchars($alert['event_date']) ?></td>
                        <td><?= htmlspecialchars($alert_type_labels[$alert['alert_type']] ?? $alert['alert_type']) ?></td>
                        <td class="msg"><?= htmlspecialchars($alert['message']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p class="empty">Няма аларми</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-card" style="margin-top:20px;">
        <h2>Резултати по година</h2>
        <?php if ($race_results): ?>
        <nav class="year-nav" aria-label="Филтър по година">
            <?php foreach ($result_years as $year): ?>
                <button type="button" data-year="<?= htmlspecialchars($year) ?>"
                        class="<?= $year === $default_year ? 'active' : '' ?>"><?= htmlspecialchars($year) ?></button>
            <?php endforeach; ?>
        </nav>
        <table id="results-table">
            <thead>
                <tr><th>Дата</th><th>Състезание</th><th>Позиция</th><th>Време</th></tr>
            </thead>
            <tbody>
                <?php foreach ($race_results as $r):
                    $row_year = substr($r['event_date'], 0, 4);
                    $splits = [
                        'Swim' => ['time' => $r['swim_split'] ?? null, 'pos' => $r['swim_position'] ?? null],
                        'T1'   => ['time' => $r['t1_split'] ?? null,   'pos' => $r['t1_position'] ?? null],
                        'Bike' => ['time' => $r['bike_split'] ?? null, 'pos' => $r['bike_position'] ?? null],
                        'T2'   => ['time' => $r['t2_split'] ?? null,   'pos' => $r['t2_position'] ?? null],
                        'Run'  => ['time' => $r['run_split'] ?? null,  'pos' => $r['run_position'] ?? null],
                    ];
                    $has_splits = count(array_filter($splits, fn($s) => $s['time'] !== null && $s['time'] !== '')) > 0;

                    // Позицията като badge: подиумът получава злато/сребро/бронз
                    // тониране, DNF/DSQ/LAP — приглушено червено, останалите — синьо.
                    $pos = $r['position'];
                    $pos_class = 'pos-badge';
                    if ($pos !== null && $pos !== '' && is_numeric($pos)) {
                        $p = (int)$pos;
                        if ($p === 1) $pos_class .= ' pos-gold';
                        elseif ($p === 2) $pos_class .= ' pos-silver';
                        elseif ($p === 3) $pos_class .= ' pos-bronze';
                    } elseif ($pos !== null && $pos !== '') {
                        $pos_class .= ' pos-dnx';
                    }
                ?>
                <tr class="result-row" data-year="<?= htmlspecialchars($row_year) ?>"
                    tabindex="0" role="button" aria-expanded="false"
                    <?= $row_year !== $default_year ? 'style="display:none;"' : '' ?>>
                    <td class="event-date"><?= htmlspecialchars($r['event_date']) ?></td>
                    <td class="msg event-name"><?= htmlspecialchars($r['event_title'] ?? '—') ?></td>
                    <td><?= $pos !== null && $pos !== ''
                        ? '<span class="' . $pos_class . '">' . htmlspecialchars($pos) . '</span>'
                        : '—' ?></td>
                    <td class="total-time"><?= $r['total_time'] !== null && $r['total_time'] !== '' ? htmlspecialchars($r['total_time']) : '—' ?></td>
                </tr>
                <tr class="result-detail" data-year="<?= htmlspecialchars($row_year) ?>" style="display:none;">
                    <td colspan="4">
                        <div class="split-panel">
                            <?php if ($has_splits): ?>
                            <div class="splits-grid">
                                <?php foreach ($splits as $label => $s):
                                    $has_time = $s['time'] !== null && $s['time'] !== '';
                                ?>
                                <div class="split-cell">
                                    <div class="split-label"><?= $label ?></div>
                                    <div class="split-time<?= $has_time ? '' : ' split-time--empty' ?>">
                                        <?= $has_time ? htmlspecialchars($s['time']) : '—' ?>
                                    </div>
                                    <?php if ($has_time && $s['pos'] !== null && $s['pos'] !== ''): ?>
                                    <div class="split-pos">(<?= htmlspecialchars($s['pos']) ?>)</div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                                <div class="no-splits">Няма детайлни данни</div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty">Няма резултати от състезания</p>
        <?php endif; ?>
    </div>

    <div class="table-card" style="margin-top:20px;">
        <h2>Местни състезания</h2>
        <?php if ($local_results): ?>
        <nav class="year-nav local-year-nav" aria-label="Филтър по година — местни състезания">
            <?php foreach ($local_result_years as $year): ?>
                <button type="button" data-year="<?= htmlspecialchars($year) ?>"
                        class="<?= $year === $local_default_year ? 'active' : '' ?>"><?= htmlspecialchars($year) ?></button>
            <?php endforeach; ?>
        </nav>
        <table id="local-results-table">
            <thead>
                <tr><th>Дата</th><th>Състезание</th><th>Дисциплина</th><th>Позиция</th><th>Време</th></tr>
            </thead>
            <tbody>
                <?php foreach ($local_results as $r):
                    $row_year = substr($r['event_date'], 0, 4);
                    $legs = local_leg_labels($r['sport']);
                    $splits = [
                        $legs['leg1'] => ['time' => $r['leg1'], 'pos' => $r['pos_leg1']],
                        'T1'          => ['time' => $r['t1'],   'pos' => null],
                        $legs['leg2'] => ['time' => $r['leg2'], 'pos' => $r['pos_leg2']],
                        'T2'          => ['time' => $r['t2'],   'pos' => null],
                        $legs['leg3'] => ['time' => $r['leg3'], 'pos' => $r['pos_leg3']],
                    ];
                    // Акватлонът няма отделни транзиции (t1/t2 винаги NULL от sync-а) —
                    // без тях, вместо два безсмислени "—" етапа в грида.
                    if ($r['sport'] === 'aquathlon') {
                        unset($splits['T1'], $splits['T2']);
                    }
                    $has_splits = count(array_filter($splits, fn($s) => $s['time'] !== null && $s['time'] !== '')) > 0;

                    // Същата podium-badge логика като WT резултатите по-горе.
                    $pos = $r['place'];
                    $pos_class = 'pos-badge';
                    if ($pos !== null && $pos !== '' && is_numeric($pos)) {
                        $p = (int)$pos;
                        if ($p === 1) $pos_class .= ' pos-gold';
                        elseif ($p === 2) $pos_class .= ' pos-silver';
                        elseif ($p === 3) $pos_class .= ' pos-bronze';
                    } elseif ($pos !== null && $pos !== '') {
                        $pos_class .= ' pos-dnx';
                    }
                ?>
                <tr class="result-row local-result-row" data-year="<?= htmlspecialchars($row_year) ?>"
                    tabindex="0" role="button" aria-expanded="false"
                    <?= $row_year !== $local_default_year ? 'style="display:none;"' : '' ?>>
                    <td class="event-date"><?= htmlspecialchars($r['event_date']) ?></td>
                    <td class="msg event-name"><?= htmlspecialchars($r['event_name']) ?><?= !empty($r['city']) ? ' (' . htmlspecialchars($r['city']) . ')' : '' ?></td>
                    <td><?= htmlspecialchars(local_sport_label($r['sport'])) ?></td>
                    <td><?= $pos !== null && $pos !== ''
                        ? '<span class="' . $pos_class . '">' . htmlspecialchars($pos) . '</span>'
                        : '—' ?></td>
                    <td class="total-time"><?= $r['total_time'] !== null && $r['total_time'] !== '' ? htmlspecialchars($r['total_time']) : '—' ?></td>
                </tr>
                <tr class="result-detail local-result-detail" data-year="<?= htmlspecialchars($row_year) ?>" style="display:none;">
                    <td colspan="5">
                        <div class="split-panel">
                            <p style="margin:0 0 10px;color:var(--ink-2);font-size:13px;">
                                Категория: <?= htmlspecialchars($r['category'] ?? '—') ?> ·
                                Класиране: <?= $pos !== null && $pos !== '' ? htmlspecialchars($pos) : '—' ?> от <?= $r['field_size'] !== null ? (int)$r['field_size'] : '—' ?> ·
                                Клуб: <?= htmlspecialchars($r['club'] ?? '—') ?>
                            </p>
                            <?php if ($has_splits): ?>
                            <div class="splits-grid">
                                <?php foreach ($splits as $label => $s):
                                    $has_time = $s['time'] !== null && $s['time'] !== '';
                                ?>
                                <div class="split-cell">
                                    <div class="split-label"><?= htmlspecialchars($label) ?></div>
                                    <div class="split-time<?= $has_time ? '' : ' split-time--empty' ?>">
                                        <?= $has_time ? htmlspecialchars($s['time']) : '—' ?>
                                    </div>
                                    <?php if ($has_time && $s['pos'] !== null && $s['pos'] !== ''): ?>
                                    <div class="split-pos">(<?= htmlspecialchars($s['pos']) ?>)</div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                                <div class="no-splits">Няма детайлни данни</div>
                            <?php endif; ?>
                            <?php if (!empty($r['source_url'])): ?>
                            <p style="margin:10px 0 0;font-size:12px;">
                                <a href="<?= htmlspecialchars($r['source_url']) ?>" target="_blank" rel="noopener">Официални резултати ↗</a>
                            </p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty">Няма резултати от местни състезания</p>
        <?php endif; ?>
    </div>

    <div class="table-card" style="margin-top:20px;">
        <h2>Лактатни тестове</h2>
        <?php if ($lactate_tests): ?>
        <table id="lactate-table">
            <thead>
                <tr><th>Дата</th><th>Протокол</th><th>FTP</th><th>W/kg</th><th>LT1 (W)</th><th>LT2 (W)</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($lactate_tests as $t): ?>
                <?php
                    // Изчислено веднъж на тест — служи и на summary реда (LT1/LT2
                    // колоните), и на detail панела по-долу (table + column highlight).
                    $active_steps = [];
                    $lt_points = [];
                    for ($i = 1; $i <= 10; $i++) {
                        if ($t["step{$i}_hr"] !== null || $t["step{$i}_la"] !== null) {
                            $active_steps[] = $i;
                        }
                        $lt_points[] = ['watts' => lactate_step_watts($t['protocol'], $i), 'la' => $t["step{$i}_la"]];
                    }

                    $lt1_w = $t['lt1_w'];
                    $lt1_estimated = false;
                    if ($lt1_w === null) {
                        $lt1_w = lactate_interpolate_threshold($lt_points, 2.0);
                        $lt1_estimated = $lt1_w !== null;
                    }
                    $lt2_w = $t['lt2_w'];
                    $lt2_estimated = false;
                    if ($lt2_w === null) {
                        $lt2_w = lactate_interpolate_threshold($lt_points, 4.0);
                        $lt2_estimated = $lt2_w !== null;
                    }

                    $lt1_step = lactate_nearest_step($active_steps, $t['protocol'], $lt1_w);
                    $lt2_step = lactate_nearest_step($active_steps, $t['protocol'], $lt2_w);
                ?>
                <tr class="result-row lactate-row" tabindex="0" role="button" aria-expanded="false">
                    <td class="event-date"><?= htmlspecialchars($t['test_date']) ?></td>
                    <td><?= htmlspecialchars($t['protocol'] ?? '—') ?></td>
                    <td><?= fmt($t['ftp'], 0) ?></td>
                    <td><?= fmt($t['w_kg'], 2) ?></td>
                    <td><?= fmt_lt($lt1_w, $lt1_estimated) ?></td>
                    <td><?= fmt_lt($lt2_w, $lt2_estimated) ?></td>
                    <td>
                        <a class="lt-analysis-btn"
                           href="lactate_analysis.php?test_id=<?= (int)$t['id'] ?>&amp;athlete_id=<?= urlencode($athlete_id) ?>"
                           target="_blank" rel="noopener">📊 Анализ</a>
                    </td>
                </tr>
                <tr class="result-detail lactate-detail" style="display:none;">
                    <td colspan="7">
                        <div class="split-panel">
                            <div class="lactate-panel-header">
                                <span class="lt-date"><?= htmlspecialchars($t['test_date']) ?></span>
                                <span class="lt-stat">FTP <strong><?= fmt($t['ftp'], 0) ?> W</strong></span>
                                <span class="lt-stat"><strong><?= fmt($t['w_kg'], 2) ?></strong> W/kg</span>
                            </div>
                            <p class="lactate-bio-line">
                                Ръст: <?= fmt($t['height_cm'], 0) ?> см ·
                                Тегло: <?= fmt($t['weight_kg'], 1) ?> кг ·
                                Възраст: <?= $t['age'] !== null ? (int)$t['age'] : '—' ?> ·
                                Лактат старт: <?= fmt($t['lactate_start'], 1) ?> mmol ·
                                Пулс старт: <?= fmt($t['hr_start'], 0) ?> HR
                            </p>
                            <?php if ($active_steps): ?>
                            <div class="lactate-table-wrap">
                                <table class="lactate-steps-table">
                                    <tbody>
                                        <tr class="lt-row-watts">
                                            <th>Мощност</th>
                                            <?php foreach ($active_steps as $i):
                                                $watts = lactate_step_watts($t['protocol'], $i);
                                                $col_class = trim(($i === $lt1_step ? ' lt1-col' : '') . ($i === $lt2_step ? ' lt2-col' : ''));
                                            ?>
                                            <td class="<?= $col_class ?>">
                                                <?= $watts !== null ? $watts . 'W' : 'Стъпка ' . $i ?>
                                                <?php if ($i === $lt1_step): ?><span class="lt-tag lt1-tag">LT1</span><?php endif; ?>
                                                <?php if ($i === $lt2_step): ?><span class="lt-tag lt2-tag">LT2</span><?php endif; ?>
                                            </td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <tr class="lt-row-hr">
                                            <th>HR</th>
                                            <?php foreach ($active_steps as $i):
                                                $col_class = trim(($i === $lt1_step ? ' lt1-col' : '') . ($i === $lt2_step ? ' lt2-col' : ''));
                                            ?>
                                            <td class="<?= $col_class ?>"><?= fmt($t["step{$i}_hr"], 0) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <tr class="lt-row-la">
                                            <th>Lactate</th>
                                            <?php foreach ($active_steps as $i):
                                                $la = $t["step{$i}_la"];
                                                $col_class = trim(($i === $lt1_step ? ' lt1-col' : '') . ($i === $lt2_step ? ' lt2-col' : ''));
                                            ?>
                                            <td class="<?= $col_class ?>">
                                                <?php if ($la !== null): ?>
                                                <span class="lt-badge <?= lactate_level_class($la) ?>"><?= fmt($la, 1) ?></span>
                                                <?php else: ?>—<?php endif; ?>
                                            </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($t['notes'])): ?>
                            <p style="margin:10px 0 0;color:var(--ink-2);font-size:13px;"><strong>Бележки:</strong> <?= htmlspecialchars($t['notes']) ?></p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty">Няма лактатни тестове</p>
        <?php endif; ?>
    </div>

    <div class="table-card" style="margin-top:20px;">
        <h2>Национални функционални тестове (НЦ)</h2>
        <?php if ($nat_tests): ?>
        <p class="hint" style="margin:0 0 16px;">
            Отделно от клубните лактатни тестове по-горе — различен протокол, стойностите не са пряко сравними между вело и тредбанд (виж бележките под всяка таблица).
        </p>

        <div class="charts">
            <div class="chart-card">
                <h2>VO2max/kg</h2>
                <p class="hint">Отделна серия за всеки протокол — не се свързват с права линия</p>
                <div class="chart-wrap"><canvas id="chartNatVo2max"></canvas></div>
            </div>
            <div class="chart-card">
                <h2>W_max/kg</h2>
                <p class="hint">Само вело протоколи (тредбандът няма W_max)</p>
                <div class="chart-wrap"><canvas id="chartNatWmax"></canvas></div>
            </div>
        </div>

        <?php foreach ($nat_tests_by_protocol as $protocol => $tests):
            $protocol_row = $nat_protocols[$protocol] ?? null;
            $device_label = $protocol_row['device'] ?? $protocol;
            $description = nat_protocol_description($protocol_row);
        ?>
        <h3 style="margin:24px 0 4px;font-size:15px;"><?= htmlspecialchars($device_label) ?></h3>
        <?php if ($description): ?>
        <p class="hint" style="margin:0 0 10px;"><?= htmlspecialchars($description) ?></p>
        <?php endif; ?>
        <table class="nat-tests-table">
            <thead>
                <tr><th>Дата</th><th><?= ($protocol_row['metric'] ?? '') === 'W' ? 'W_max' : 'S_max' ?></th><th>VO2max/kg</th><th>HR_max</th><th>ЕПЗ</th></tr>
            </thead>
            <tbody>
                <?php foreach ($tests as $t): ?>
                <tr class="result-row nat-test-row" tabindex="0" role="button" aria-expanded="false">
                    <td class="event-date"><?= htmlspecialchars($t['test_date']) ?></td>
                    <td><?= nat_primary_metric($t, $protocol_row) ?></td>
                    <td><?= fmt($t['vo2max_kg'], 2) ?></td>
                    <td><?= $t['hr_max'] !== null ? (int)$t['hr_max'] : '—' ?></td>
                    <td><?= $t['epz_from'] !== null && $t['epz_to'] !== null ? (int)$t['epz_from'] . '–' . (int)$t['epz_to'] : '—' ?></td>
                </tr>
                <tr class="result-detail nat-test-detail" style="display:none;">
                    <td colspan="5">
                        <div class="split-panel">
                            <p style="margin:0 0 10px;color:var(--ink-2);font-size:13px;">
                                Ръст: <?= fmt($t['height_cm'], 0) ?> см ·
                                Разтег: <?= fmt($t['arm_span_cm'], 0) ?> см ·
                                Тегло: <?= fmt($t['weight_kg'], 1) ?> кг ·
                                АТМ: <?= fmt($t['lean_mass_kg'], 1) ?> кг
                            </p>
                            <p style="margin:0 0 10px;color:var(--ink-2);font-size:13px;">
                                Мазнини: <?= fmt($t['fat_pct'], 1) ?>% (<?= fmt($t['fat_kg'], 1) ?> кг) ·
                                Мускули: <?= fmt($t['muscle_pct'], 1) ?>% (<?= fmt($t['muscle_kg'], 1) ?> кг) ·
                                Продължителност: <?= fmt($t['duration_min'], 1) ?> мин
                            </p>
                            <p style="margin:0;color:var(--ink-2);font-size:13px;">
                                Лактат/пулс: La2 <?= fmt($t['la_2'], 1) ?> / HR2 <?= $t['hr_2'] !== null ? (int)$t['hr_2'] : '—' ?> ·
                                La6 <?= fmt($t['la_6'], 1) ?> / HR6 <?= $t['hr_6'] !== null ? (int)$t['hr_6'] : '—' ?> ·
                                La15 <?= fmt($t['la_15'], 1) ?>
                            </p>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endforeach; ?>
        <?php else: ?>
            <p class="empty">Няма национални функционални тестове</p>
        <?php endif; ?>
    </div>

    <?php render_metrics_legend(); ?>

    <script>
    const DATA = <?= json_encode($chart_data, JSON_UNESCAPED_UNICODE) ?>;
    const NAT_DATA = <?= json_encode([
        'dates' => $nat_chart_dates,
        'series' => array_values($nat_chart_series),
    ], JSON_UNESCAPED_UNICODE) ?>;

    const INK2 = '#52514e', MUTED = '#898781', GRID = '#e1e0d9';
    const BLUE = '#2a78d6', AQUA = '#1baf7a';

    Chart.defaults.font.family = 'Arial, sans-serif';
    Chart.defaults.color = MUTED;
    Chart.defaults.animation = false;

    // Плъгин: сива референтна зона по y (за оптималния ACWR диапазон)
    const bandPlugin = {
        id: 'yBand',
        beforeDatasetsDraw(chart, args, opts) {
            if (!opts || opts.from === undefined) return;
            const { ctx, chartArea, scales: { y } } = chart;
            const top = y.getPixelForValue(opts.to);
            const bottom = y.getPixelForValue(opts.from);
            ctx.save();
            ctx.fillStyle = 'rgba(137, 135, 129, 0.12)';
            ctx.fillRect(chartArea.left, top, chartArea.right - chartArea.left, bottom - top);
            ctx.restore();
        }
    };
    Chart.register(bandPlugin);

    function series(label, data, color) {
        // Самотна точка (без съседи) е невидима при pointRadius 0 — дай ѝ радиус,
        // иначе графика с 1 запис изглежда празна
        const lonely = (v, i) => v !== null
            && (i === 0 || data[i - 1] === null)
            && (i === data.length - 1 || data[i + 1] === null);
        return {
            label, data,
            borderColor: color,
            backgroundColor: color,
            borderWidth: 2,
            pointRadius: data.map((v, i) => lonely(v, i) ? 4 : 0),
            pointHoverRadius: 5,
            pointHoverBorderColor: '#ffffff',
            pointHoverBorderWidth: 2,
            spanGaps: false,
            tension: 0.25
        };
    }

    function baseOptions({ legend = false, reverse = false, yBand, suggestedMin, suggestedMax } = {}) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: legend
                    ? { display: true, labels: { color: INK2, boxWidth: 12, boxHeight: 12, usePointStyle: true, pointStyle: 'line' } }
                    : { display: false },
                tooltip: { boxPadding: 4 },
                yBand: yBand || {}
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { color: GRID },
                    ticks: {
                        maxTicksLimit: window.innerWidth < 600 ? 4 : 6,
                        maxRotation: 0,
                        autoSkip: true
                    }
                },
                y: {
                    reverse,
                    suggestedMin,
                    suggestedMax,
                    grid: { color: GRID, drawTicks: false },
                    border: { display: false },
                    ticks: { padding: 8, maxTicksLimit: 6 }
                }
            }
        };
    }

    new Chart(document.getElementById('chartAcwr'), {
        type: 'line',
        data: { labels: DATA.labels, datasets: [series('ACWR', DATA.acwr, BLUE)] },
        options: baseOptions({ yBand: { from: 0.8, to: 1.3 }, suggestedMin: 0.5, suggestedMax: 1.6 })
    });

    new Chart(document.getElementById('chartLoad'), {
        type: 'line',
        data: {
            labels: DATA.labels,
            datasets: [
                series('CTL (Fitness)', DATA.ctl, BLUE),
                series('ATL (Fatigue)', DATA.atl, AQUA)
            ]
        },
        options: baseOptions({ legend: true })
    });

    new Chart(document.getElementById('chartHrv'), {
        type: 'line',
        data: { labels: DATA.labels, datasets: [series('HRV', DATA.hrv, BLUE)] },
        options: baseOptions()
    });

    new Chart(document.getElementById('chartSleep'), {
        type: 'line',
        data: { labels: DATA.labels, datasets: [series('Сън (ч)', DATA.sleep, BLUE)] },
        options: baseOptions()
    });

    new Chart(document.getElementById('chartWorld'), {
        type: 'line',
        data: { labels: DATA.rankLabels, datasets: [series('World Ranking', DATA.world, BLUE)] },
        options: baseOptions({ reverse: true })
    });

    new Chart(document.getElementById('chartRegional'), {
        type: 'line',
        data: { labels: DATA.rankLabels, datasets: [series('Regional Ranking', DATA.regional, BLUE)] },
        options: baseOptions({ reverse: true })
    });

    // НЦ функционални тестове: отделна серия за всеки протокол — nat_tests_comparable()
    // (includes/nat_tests.php) е причината да НЕ свързваме различни протоколи с една
    // линия: тредбанд/вело дават различен VO2max за същия атлет по физиологични причини.
    if (NAT_DATA.dates && NAT_DATA.dates.length) {
        const NAT_COLORS = [BLUE, AQUA, '#c62828', '#f57c00'];
        const vo2Datasets = NAT_DATA.series.map((s, i) => series(s.label, s.vo2max_kg, NAT_COLORS[i % NAT_COLORS.length]));
        const wmaxDatasets = NAT_DATA.series
            .filter(s => s.w_max_kg.some(v => v !== null))
            .map((s, i) => series(s.label, s.w_max_kg, NAT_COLORS[i % NAT_COLORS.length]));

        new Chart(document.getElementById('chartNatVo2max'), {
            type: 'line',
            data: { labels: NAT_DATA.dates, datasets: vo2Datasets },
            options: baseOptions({ legend: true })
        });

        if (wmaxDatasets.length) {
            new Chart(document.getElementById('chartNatWmax'), {
                type: 'line',
                data: { labels: NAT_DATA.dates, datasets: wmaxDatasets },
                options: baseOptions({ legend: true })
            });
        }
    }

    // НЦ функционални тестове: просто expand/collapse, без year filter
    // (тестовете са малко на брой годишно — не си заслужава допълнителна навигация).
    (function () {
        function toggleRow(row) {
            const detail = row.nextElementSibling;
            if (!detail || !detail.classList.contains('nat-test-detail')) return;
            const willOpen = detail.style.display === 'none';
            detail.style.display = willOpen ? '' : 'none';
            row.classList.toggle('open', willOpen);
            row.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        }
        document.querySelectorAll('.nat-tests-table tbody tr.nat-test-row').forEach(function (row) {
            row.addEventListener('click', function () { toggleRow(row); });
            row.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    toggleRow(row);
                }
            });
        });
    }());

    // "Резултати по година": филтър по година + разгъващи се сплитове.
    // Всеки резултат е ДВОЙКА редове — .result-row (видим при съвпадаща
    // година) и .result-detail (видим само след клик). Смяната на година
    // прибира всички разгънати детайли, за да няма "осиротели" подредове.
    (function () {
        const nav = document.querySelector('.year-nav');
        if (!nav) return;
        const buttons = nav.querySelectorAll('button');
        const mainRows = document.querySelectorAll('#results-table tbody tr.result-row');
        const detailRows = document.querySelectorAll('#results-table tbody tr.result-detail');

        nav.addEventListener('click', function (ev) {
            const btn = ev.target.closest('button');
            if (!btn) return;
            const year = btn.dataset.year;
            buttons.forEach(b => b.classList.toggle('active', b === btn));
            mainRows.forEach(r => {
                r.style.display = r.dataset.year === year ? '' : 'none';
                r.classList.remove('open');
                r.setAttribute('aria-expanded', 'false');
            });
            detailRows.forEach(r => { r.style.display = 'none'; });
        });

        function toggleRow(row) {
            const detail = row.nextElementSibling;
            if (!detail || !detail.classList.contains('result-detail')) return;
            const willOpen = detail.style.display === 'none';
            detail.style.display = willOpen ? '' : 'none';
            row.classList.toggle('open', willOpen);
            row.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        }

        mainRows.forEach(function (row) {
            row.addEventListener('click', function () { toggleRow(row); });
            row.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    toggleRow(row);
                }
            });
        });
    }());

    // Местни състезания: същата логика като "Резултати по година" по-горе,
    // но скопирана отделно (.local-year-nav / #local-results-table), защото
    // document.querySelector('.year-nav') по-горе взима само ПЪРВИЯ nav на
    // страницата — с два .year-nav елемента вторият остава без поведение,
    // ако не се скопира изрично.
    (function () {
        const nav = document.querySelector('.local-year-nav');
        if (!nav) return;
        const buttons = nav.querySelectorAll('button');
        const mainRows = document.querySelectorAll('#local-results-table tbody tr.local-result-row');
        const detailRows = document.querySelectorAll('#local-results-table tbody tr.local-result-detail');

        nav.addEventListener('click', function (ev) {
            const btn = ev.target.closest('button');
            if (!btn) return;
            const year = btn.dataset.year;
            buttons.forEach(b => b.classList.toggle('active', b === btn));
            mainRows.forEach(r => {
                r.style.display = r.dataset.year === year ? '' : 'none';
                r.classList.remove('open');
                r.setAttribute('aria-expanded', 'false');
            });
            detailRows.forEach(r => { r.style.display = 'none'; });
        });

        function toggleRow(row) {
            const detail = row.nextElementSibling;
            if (!detail || !detail.classList.contains('local-result-detail')) return;
            const willOpen = detail.style.display === 'none';
            detail.style.display = willOpen ? '' : 'none';
            row.classList.toggle('open', willOpen);
            row.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        }

        mainRows.forEach(function (row) {
            row.addEventListener('click', function () { toggleRow(row); });
            row.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    toggleRow(row);
                }
            });
        });
    }());

    // Лактатни тестове: същото expand/collapse поведение, без year filter
    // (тестовете са малко на брой — не си заслужава допълнителна навигация).
    (function () {
        function toggleRow(row) {
            const detail = row.nextElementSibling;
            if (!detail || !detail.classList.contains('lactate-detail')) return;
            const willOpen = detail.style.display === 'none';
            detail.style.display = willOpen ? '' : 'none';
            row.classList.toggle('open', willOpen);
            row.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        }
        document.querySelectorAll('#lactate-table tbody tr.lactate-row').forEach(function (row) {
            // Клик върху "📊 Анализ" линка не бива да тригва expand/collapse на реда.
            row.addEventListener('click', function (ev) {
                if (ev.target.closest('a')) return;
                toggleRow(row);
            });
            row.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    toggleRow(row);
                }
            });
        });
    }());
    </script>
</body>
</html>
