<?php
header('Cache-Control: no-store, no-cache, must-revalidate');

// Production (PHP 8.0) има serialize_precision=100 в php.ini — json_encode()
// печата всеки float с пълната IEEE754 опашка (напр. 71.07 като
// 71.0699999999999...) вместо чисто число. Същият фикс като api_lactate.php;
// тук важи за $chart_data/NAT_CHARTS по-долу, вкъдено в <script> инлайн.
ini_set('serialize_precision', -1);

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

// Обединена хронология (местни + World Triathlon) — сплитовете от двата
// източника в един ред по дата, за да се вижда прогресията независимо
// откъде идва състезанието. Само триатлон: дуатлон/акватлон местните
// резултати нямат плуване/колело/бягане в тези колони.
$combined_results = [];
try {
    $stmt = $pdo->prepare("
        SELECT event_date, 'local' AS source,
               leg1 AS swim, t1, leg2 AS bike, t2, leg3 AS run, total_time
        FROM local_results r JOIN local_events e ON e.event_id = r.event_id
        WHERE r.athlete_name = ? AND r.sport = 'triathlon'

        UNION ALL

        SELECT event_date, 'wt' AS source,
               swim_split AS swim, t1_split AS t1, bike_split AS bike,
               t2_split AS t2, run_split AS run, total_time
        FROM world_triathlon_results
        WHERE athlete_name = ?

        ORDER BY event_date DESC
    ");
    $stmt->execute([$athlete_name, $athlete_name]);
    $combined_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $combined_results = [];
}

// HR/power zone разбивка по активност (main.py's ежедневен "zones" step,
// от Intervals.icu — виж storage/db.py:upsert_activity_zones()). Таблицата
// може още да не съществува на стара база — прескачаме тихо.
$activity_zones = [];
try {
    $stmt = $pdo->prepare("
        SELECT activity_id, activity_date, activity_type, has_power,
               hr_zone_times_json, power_zone_times_json,
               icu_average_watts, icu_weighted_avg_watts, icu_ftp
        FROM activity_zones
        WHERE athlete_name = ?
        ORDER BY activity_date DESC
    ");
    $stmt->execute([$athlete_name]);
    $activity_zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $activity_zones = [];
}

// Треньорски оценки от състезания (fetch_race_evaluations.py, от Google
// Sheet таб "Оценки"; join по athlete_name — Sheet-ът не познава нито
// intervals, нито World Triathlon ID, същата причина като local_results
// по-горе). Join-ва се на ниво показване (не SQL) с ±1 ден толеранс към
// world_triathlon_results/local_results, защото датите в двата източника
// понякога се разминават с ден (виж find_evaluation_for_date()).
// Таблицата може още да не съществува на стара база — прескачаме тихо.
$race_evaluations = [];
try {
    $stmt = $pdo->prepare("
        SELECT event_date, event_title, event_type, distance,
               swim_start, swim_training, notes_swim,
               t1_wetsuit, t1_mount, notes_t1,
               bike_power, bike_technique, notes_bike,
               t2_dismount, t2_shoes, notes_t2,
               run_transition, run_pacing, notes_run,
               general_note
        FROM race_evaluations
        WHERE athlete_name = ?
        ORDER BY event_date ASC
    ");
    $stmt->execute([$athlete_name]);
    $race_evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $race_evaluations = [];
}

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

// За всеки протокол: 1 тест -> самостоятелна референтна карта (без
// delta/тренд/radar, тренд не е възможен с една точка); 2+ тестове ->
// trend мини-графика + сравнителна таблица + radar overlay. Всичко
// смятано веднъж тук (не наново във всеки chart/table), за да не се
// разминат числата между блоковете. nat_build_nat_block() е дефинирана
// по-долу, до fmt() — top-level функция, PHP я вижда независимо от реда.
$nat_blocks = [];
foreach ($nat_tests_by_protocol as $protocol => $tests) {
    $nat_blocks[$protocol] = nat_build_nat_block($protocol, $tests, $nat_protocols[$protocol] ?? null);
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

// ---------------------------------------------------------------------
// НЦ функционални тестове (redesign): подготвя всичко, което trend
// мини-картата + сравнителната таблица + radar-ът за ЕДИН протокол се
// нуждаят, на едно място — за да смятат едни и същи числа, не отделни
// копия. $tests идва в низходящ ред по дата (заявката ORDER BY test_date
// DESC); тук се обръща във възходящ, защото таблицата/графиката вървят
// хронологично отляво надясно.
function nat_build_nat_block($protocol, $tests_desc, $protocol_row) {
    $tests_asc = array_reverse($tests_desc);
    $count = count($tests_asc);
    $is_bike = ($protocol_row['metric'] ?? null) === 'W';

    $block = [
        'protocol' => $protocol,
        'protocol_row' => $protocol_row,
        'tests_asc' => $tests_asc,
        'count' => $count,
        'is_bike' => $is_bike,
    ];
    if ($count < 2) {
        return $block; // единичен тест -> single-card, без trend/delta/radar
    }

    $first = $tests_asc[0];
    $last = $tests_asc[$count - 1];
    $prev = $tests_asc[$count - 2];

    // Trend мини-графика: VO2max/kg по всички тестове на протокола.
    $block['trend_labels'] = array_map(fn($t) => $t['test_date'], $tests_asc);
    $block['trend_vo2'] = array_map(fn($t) => $t['vo2max_kg'] !== null ? (float)$t['vo2max_kg'] : null, $tests_asc);

    // Хелпър: слепва label/value/decimals/unit с и sign (истинска посока —
    // за стрелката) И color (up/down/flat CSS клас — nat_delta_info() решава
    // дали метриката е neutral), вместо template-ът да гадае цвета от sign.
    $make_trend_metric = function ($label, $metric_key, $first_val, $last_val, $decimals, $unit = '', $pct = null) use ($first, $last) {
        $info = nat_delta_info($metric_key, $first_val, $last_val, $decimals, $first['protocol'], $last['protocol']);
        return [
            'label' => $label, 'value' => $last_val, 'decimals' => $decimals, 'unit' => $unit,
            'pct' => $pct, 'sign' => $info['sign'], 'color' => $info['color'],
        ];
    };

    $trend_metrics = [];
    $trend_metrics[] = $make_trend_metric('VO2max/kg', 'vo2max_kg', $first['vo2max_kg'], $last['vo2max_kg'], 2, '', nat_delta_pct($first['vo2max_kg'], $last['vo2max_kg']));
    if ($is_bike) {
        $trend_metrics[] = $make_trend_metric('W/kg', 'w_max_kg', $first['w_max_kg'], $last['w_max_kg'], 2, '', nat_delta_pct($first['w_max_kg'], $last['w_max_kg']));
    } else {
        $trend_metrics[] = $make_trend_metric('S_max', 's_max_kmh', $first['s_max_kmh'], $last['s_max_kmh'], 1, ' км/ч', nat_delta_pct($first['s_max_kmh'], $last['s_max_kmh']));
    }
    $trend_metrics[] = $make_trend_metric("La 2' (посл.)", 'la_2', $first['la_2'], $last['la_2'], 1, '', null);
    $block['trend_metrics'] = $trend_metrics;

    // Radar: personal best на всяко поле измежду ВСИЧКИ тестове на този
    // протокол (не само последните два) — изисквано изрично, за да не се
    // хардкодне "последният тест = 100%" когато реалният личен максимум е
    // бил при по-стар тест (виж La 6' при Мира — лактатът ѝ се покачва с
    // времето, значи възстановяването ѝ реално се влошава на тази ос,
    // въпреки че мощността/VO2max растат).
    $radar_fields = ['vo2max_kg' => ['label' => 'VO2max/kg', 'lower' => false]];
    if ($is_bike) {
        $radar_fields['w_max_kg'] = ['label' => 'W_max/kg', 'lower' => false];
    } else {
        $radar_fields['s_max_kmh'] = ['label' => 'S_max', 'lower' => false];
    }
    $radar_fields['hr_max'] = ['label' => 'HR_max', 'lower' => false];
    $radar_fields['la_6'] = ['label' => "Възст. (1/La 6')", 'lower' => true];
    $radar_fields['muscle_pct'] = ['label' => 'Мускулна %', 'lower' => false];

    $radar_labels = [];
    $radar_latest = [];
    $radar_prev = [];
    foreach ($radar_fields as $field => $meta) {
        $best = nat_radar_best($tests_asc, $field, $meta['lower']);
        if ($best === null) continue;
        $latest_pct = nat_radar_pct($last[$field] ?? null, $best['value'], $meta['lower']);
        $prev_pct = nat_radar_pct($prev[$field] ?? null, $best['value'], $meta['lower']);
        if ($latest_pct === null || $prev_pct === null) continue; // липсваща стойност -> пропусни оста и за двете серии
        $radar_labels[] = $meta['label'];
        $radar_latest[] = round($latest_pct, 1);
        $radar_prev[] = round($prev_pct, 1);
    }
    $block['radar_labels'] = $radar_labels;
    $block['radar_latest'] = $radar_latest;
    $block['radar_prev'] = $radar_prev;
    $block['radar_latest_date'] = $last['test_date'];
    $block['radar_prev_date'] = $prev['test_date'];

    return $block;
}

function nat_delta_cell_html($metric_key, $first_val, $last_val, $decimals = 1, $unit = '', $protocol_a = null, $protocol_b = null) {
    $info = nat_delta_info($metric_key, $first_val, $last_val, $decimals, $protocol_a, $protocol_b);
    if ($info['diff'] === null) {
        return '<span class="delta flat">—</span>';
    }
    $arrow = nat_delta_arrow($info['sign']);
    $abs = number_format(abs($info['diff']), $decimals);
    return '<span class="delta ' . htmlspecialchars($info['color']) . '">' . $arrow . ' ' . htmlspecialchars($abs . $unit) . '</span>';
}

function nat_comp_delta_cell_html($first_muscle, $last_muscle) {
    $info = nat_delta_info('muscle_pct', $first_muscle, $last_muscle, 1);
    if ($info['diff'] === null) {
        return '<span class="delta flat">—</span>';
    }
    $arrow = nat_delta_arrow($info['sign']);
    return '<span class="delta ' . htmlspecialchars($info['color']) . '">' . $arrow . ' мускул</span>';
}

function nat_epz_text($from, $to) {
    if ($from === null || $to === null) return '—';
    return (int)$from . '–' . (int)$to;
}

// Лентичка = реален дял от телесното тегло (fat_pct + muscle_pct), НЕ
// рескейлнати спрямо сбора им — остатъкът (кости/вода/органи) остава
// незапълнен, вместо мокъпа да си измисля общ знаменател от 100%.
function nat_comp_bar_html($fat_pct, $fat_kg, $muscle_pct, $muscle_kg) {
    $fat_w = $fat_pct !== null ? max(0, min(100, (float)$fat_pct)) : 0;
    $muscle_w = $muscle_pct !== null ? max(0, min(100, (float)$muscle_pct)) : 0;
    $fat_txt = $fat_pct !== null
        ? number_format((float)$fat_pct, 1) . '%' . ($fat_kg !== null ? ' (' . number_format((float)$fat_kg, 1) . ' кг)' : '')
        : '—';
    $muscle_txt = $muscle_pct !== null
        ? number_format((float)$muscle_pct, 1) . '%' . ($muscle_kg !== null ? ' (' . number_format((float)$muscle_kg, 1) . ' кг)' : '')
        : '—';
    return '<span class="comp-bar"><span class="fat" style="width:' . $fat_w . '%"></span><span class="muscle" style="width:' . $muscle_w . '%"></span></span>'
        . htmlspecialchars($fat_txt) . ' / ' . htmlspecialchars($muscle_txt);
}

function nat_la_cell_html($la, $hr, $decimals = 1) {
    if ($la === null) return '—';
    $text = number_format((float)$la, $decimals);
    if ($hr !== null) {
        $text .= ' <span style="color:var(--muted);font-weight:400;font-size:11px;">(HR ' . (int)$hr . ')</span>';
    }
    return $text;
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

// HR идва плосък позиционен масив (icu_hr_zone_times, storage/db.py пази го
// 1:1 като hr_zone_times_json) — позиция = зона, Z1 на индекс 0. Power идва
// списък от {"id","secs"} обекти (icu_zone_times, вкл. "SS" sweet-spot като
// последен елемент) — двата формата се различават, защото такива са и в
// самия Intervals.icu отговор (виж разузнаването в data-model.md). Тук ги
// свеждаме до един и същ [{label, secs}] за общ рендер по-долу.
function zone_segments($zone_times_json, $power_style) {
    if ($zone_times_json === null) return [];
    $times = json_decode($zone_times_json, true);
    if (!$times) return [];
    if ($power_style) {
        return array_map(fn($t) => ['label' => $t['id'], 'secs' => (int)$t['secs']], $times);
    }
    return array_values(array_map(
        fn($i, $secs) => ['label' => 'Z' . ($i + 1), 'secs' => (int)$secs],
        array_keys($times), $times
    ));
}

// Цветовият канал носи само груба интензивност (5 стъпки от ordinal синята
// рампа — виж :root по-горе и dataviz skill-а), не индивидуален цвят на
// всяка зона: 7-8 напълно различими стъпки от един hue не се събират в
// достъпния lightness диапазон (validate_palette.js --ordinal хвърля
// adjacent-ΔL FAIL над ~5 стъпки за accessible банда). Точната секунда по
// зона винаги е налична текстово в detail панела — цветът е само ориентир.
function zone_bucket($index, $count) {
    if ($count <= 1) return 5;
    return min(5, 1 + (int)floor($index * 5 / $count));
}

// Връща <span> stacked bar + подпис "общо време" за един zone_segments()
// резултат. Нулевите зони се пропускат от бара (нищо за рисуване), но се
// броят в общото време; 4px заоблени краища идват от :first-child/:last-child
// в CSS — работят автоматично, защото само ненулевите сегменти влизат в DOM-а.
function zone_bar_html($segments) {
    $total = array_sum(array_column($segments, 'secs'));
    if ($total <= 0) return '—';
    $n = count($segments);
    $bars = '';
    foreach ($segments as $i => $s) {
        if ($s['secs'] <= 0) continue;
        $pct = $s['secs'] / $total * 100;
        $bucket = zone_bucket($i, $n);
        $bars .= '<span style="width:' . $pct . '%;background:var(--zone-' . $bucket . ')" title="'
            . htmlspecialchars($s['label']) . ': ' . gmdate('H:i:s', $s['secs']) . '"></span>';
    }
    $mins = round($total / 60);
    return '<span class="zone-bar">' . $bars . '</span><span class="zone-bar-total">' . $mins . ' мин</span>';
}

// Оценките от race_evaluations се join-ват на ниво показване, не в SQL —
// event_date в двата източника (Sheet-а за оценки срещу local_results/
// world_triathlon_results) понякога се разминава с ден (виж спецификацията
// и sanity check-а от имплементацията), затова взимаме НАЙ-БЛИЗКАТА оценка
// в рамките на $tolerance_days, не точно съвпадение. При равни разстояния
// печели първата по ред (стабилно, но и не е очакван случай при 11 реда).
function find_evaluation_for_date($evaluations, $date, $tolerance_days = 1) {
    if (!$date) return null;
    $target = strtotime($date);
    if ($target === false) return null;
    $best = null;
    $best_diff = null;
    foreach ($evaluations as $ev) {
        $d = strtotime($ev['event_date']);
        if ($d === false) continue;
        $diff = abs($d - $target) / 86400;
        if ($diff <= $tolerance_days && ($best_diff === null || $diff < $best_diff)) {
            $best = $ev;
            $best_diff = $diff;
        }
    }
    return $best;
}

// "4.50" -> "4.5", "4.00" -> "4" — оценките позволяват свободна стъпка
// (4.2, 4.75), но целите числа не бива да носят излишни нули.
function eval_fmt_score($v) {
    return rtrim(rtrim(sprintf('%.2f', (float)$v), '0'), '.');
}

// Същата зелено/жълто/червено логика като lactate_level_class() по-горе,
// прагове по спецификацията: >=4.5 зелено, 3-4.5 жълто, <3 червено.
function eval_score_badge($v) {
    if ($v === null) return '<span class="eval-badge eval-na">—</span>';
    $f = (float)$v;
    $class = $f >= 4.5 ? 'eval-good' : ($f >= 3 ? 'eval-mid' : 'eval-bad');
    return '<span class="eval-badge ' . $class . '">' . eval_fmt_score($f) . '</span>';
}

// 10-те елемента, групирани по дисциплина/преход — общ ред за detail
// панела (4а) и heatmap-а (4б), за да не се разминат етикетите на две места.
function eval_element_groups() {
    return [
        'Плуване' => [['label' => 'Старт', 'key' => 'swim_start'], ['label' => 'Тренировки', 'key' => 'swim_training'], 'notes_swim'],
        'Т1'      => [['label' => 'Събличане', 'key' => 't1_wetsuit'], ['label' => 'Качване', 'key' => 't1_mount'], 'notes_t1'],
        'Колело'  => [['label' => 'Мощност', 'key' => 'bike_power'], ['label' => 'Техника', 'key' => 'bike_technique'], 'notes_bike'],
        'Т2'      => [['label' => 'Слизане', 'key' => 't2_dismount'], ['label' => 'Обуване', 'key' => 't2_shoes'], 'notes_t2'],
        'Бягане'  => [['label' => 'Преход', 'key' => 'run_transition'], ['label' => 'Разпределение', 'key' => 'run_pacing'], 'notes_run'],
    ];
}

// Рендер на пълния оценъчен блок (badge-ове по елемент + бележка по група +
// обща бележка накрая) за ЕДНА оценка — споделен между WT и местния detail
// панел (4а), за да не се разминат двата рендера с времето.
function eval_panel_html($ev) {
    $html = '<div class="eval-panel"><p class="eval-panel-title">Треньорска оценка'
        . (!empty($ev['event_title']) ? ' — ' . htmlspecialchars($ev['event_title']) : '') . '</p>';
    foreach (eval_element_groups() as $group => $spec) {
        [$el1, $el2, $notes_key] = $spec;
        $html .= '<div class="eval-group"><span class="eval-group-label">' . htmlspecialchars($group) . '</span> ';
        foreach ([$el1, $el2] as $el) {
            $html .= eval_score_badge($ev[$el['key']] ?? null)
                . ' <span class="eval-el-label">' . htmlspecialchars($el['label']) . '</span> ';
        }
        if (!empty($ev[$notes_key])) {
            $html .= '<div class="eval-note">' . htmlspecialchars($ev[$notes_key]) . '</div>';
        }
        $html .= '</div>';
    }
    if (!empty($ev['general_note'])) {
        $html .= '<div class="eval-note eval-general-note">' . htmlspecialchars($ev['general_note']) . '</div>';
    }
    $html .= '</div>';
    return $html;
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
            --up: #1e7d3a;
            --down: #b03a3a;
            --flat: #898781;
            /* Ordinal рампа за zone bar-овете (виж zone_bucket() по-долу) —
               5 стъпки от синия sequential hue, validate_palette.js --ordinal
               PASS (adjacent ΔL >= 0.06); 7-8 напълно различими стъпки не се
               събират в достъпния lightness диапазон, затова само 5 grosso-modo
               интензивност бъкета, не индивидуален цвят на всяка зона. */
            --zone-1: #86b6ef;
            --zone-2: #5598e7;
            --zone-3: #2a78d6;
            --zone-4: #1c5cab;
            --zone-5: #0d366b;
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
        .source-badge { display: inline-block; padding: 3px 10px; border-radius: 8px; font-weight: 600; font-size: 12px; }
        .source-badge.source-wt    { background: #eef1fb; color: #2250e3; }
        .source-badge.source-local { background: #e8f5ec; color: #1f7a3d; }
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
        /* ---- НЦ функционални тестове: protocol block ---- */
        .protocol-block { margin-top: 28px; border-top: 1px solid var(--grid); padding-top: 20px; }
        .protocol-block:first-of-type { margin-top: 0; border-top: none; padding-top: 0; }
        .protocol-head { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; margin-bottom: 2px; }
        .protocol-head h3 { margin: 0; font-size: 15.5px; }
        .protocol-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .protocol-meta { font-size: 12px; color: var(--muted); margin: 0 0 14px; }
        .protocol-grid { display: grid; grid-template-columns: 1.1fr 1.4fr; gap: 20px; align-items: start; }
        @media (max-width: 900px) { .protocol-grid { grid-template-columns: 1fr; } }

        /* ---- trend mini-card ---- */
        .trend-card { background: #fafbfc; border: 1px solid var(--grid); border-radius: 8px; padding: 14px 16px; }
        .trend-card .chart-wrap { position: relative; height: 150px; margin-top: 6px; }
        .trend-metrics { display: flex; gap: 18px; margin-top: 12px; flex-wrap: wrap; }
        .trend-metric { flex: 1; min-width: 90px; }
        .trend-metric .tm-label { font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        .trend-metric .tm-value { font-size: 19px; font-weight: 700; font-variant-numeric: tabular-nums; margin-top: 1px; }
        .tm-delta { font-size: 11.5px; font-weight: 600; margin-left: 4px; }
        .tm-delta.up { color: var(--up); } .tm-delta.down { color: var(--down); } .tm-delta.flat { color: var(--flat); }

        /* ---- comparison table: metrics as rows, tests as columns (sticky first col) ---- */
        .cmp-wrap { overflow-x: auto; border: 1px solid var(--grid); border-radius: 8px; }
        .cmp-table { border-collapse: collapse; width: 100%; font-size: 12.5px; }
        .cmp-table th, .cmp-table td { padding: 7px 12px; white-space: nowrap; text-align: right; font-variant-numeric: tabular-nums; border-bottom: 1px solid #f0efec; }
        .cmp-table th:first-child, .cmp-table td:first-child {
            position: sticky; left: 0; background: var(--surface); text-align: left;
            font-weight: 600; color: var(--ink-2); z-index: 1; border-right: 1px solid var(--grid);
        }
        .cmp-table thead th { color: var(--muted); font-weight: 600; font-size: 11px; border-bottom: 1px solid var(--grid); background: #fafbfc; }
        .cmp-table thead th:first-child { background: #fafbfc; }
        .cmp-table tbody tr:nth-child(even) td { background: #fafbff; }
        .cmp-table tbody tr:nth-child(even) td:first-child { background: #fafbff; }
        .cmp-table .group-row td { border-bottom: none; }
        .cmp-table .group-row td:first-child { padding-top: 14px; font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); background: transparent; border-right: none; }
        .cmp-table .delta-col { border-left: 2px solid var(--grid); }
        .delta { font-weight: 700; }
        .delta.up { color: var(--up); } .delta.down { color: var(--down); } .delta.flat { color: var(--flat); font-weight: 600; }

        /* ---- body composition mini bars (истински дял от теглото: fat_pct + muscle_pct, остатъкът е незапълнен) ---- */
        .comp-bar-row td { padding-top: 4px; padding-bottom: 4px; }
        .comp-bar { display: inline-flex; width: 84px; height: 8px; border-radius: 4px; overflow: hidden; vertical-align: middle; margin-right: 6px; background: #eee; }
        .comp-bar span.fat { background: #f0b562; display: block; height: 100%; }
        .comp-bar span.muscle { background: var(--series-1); display: block; height: 100%; }
        /* Zone bar — 4px заоблени краища само на първия/последния РЕНДЕРНАТ
           сегмент (нулевите зони липсват от DOM-а, виж zone_bar_html()), 2px
           surface gap между сегментите през flex gap. */
        .zone-bar { display: inline-flex; width: 160px; height: 14px; gap: 2px; vertical-align: middle; margin-right: 8px; background: var(--grid); border-radius: 4px; overflow: hidden; }
        .zone-bar span { display: block; height: 100%; }
        .zone-bar-total { font-variant-numeric: tabular-nums; color: var(--ink-2); }
        .zone-legend { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-bottom: 12px; font-size: 12px; color: var(--ink-2); }
        .zone-legend span.swatch { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; vertical-align: middle; }
        /* Треньорски оценки — същата зелено/жълто/червено палитра като .lt-badge. */
        .eval-panel { margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--grid); }
        .eval-panel-title { margin: 0 0 8px; font-weight: 600; font-size: 13px; color: var(--ink-2); }
        .eval-group { margin-bottom: 6px; font-size: 13px; }
        .eval-group-label { display: inline-block; min-width: 60px; font-weight: 600; color: var(--ink-2); }
        .eval-el-label { color: var(--ink-2); margin-right: 10px; }
        .eval-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; padding: 2px 6px; border-radius: 6px; font-weight: 700; font-size: 12px; margin-right: 2px; }
        .eval-badge.eval-good { background: #e5f3e6; color: #2e7d32; }
        .eval-badge.eval-mid  { background: #fdecd2; color: #b8600a; }
        .eval-badge.eval-bad  { background: #fbe2e2; color: #c62828; }
        .eval-badge.eval-na   { background: var(--grid); color: var(--muted); }
        .eval-note { margin: 2px 0 0 60px; font-size: 12px; color: var(--ink-2); font-style: italic; }
        .eval-general-note { margin: 8px 0 0; padding-top: 8px; border-top: 1px dashed var(--grid); font-style: normal; }
        .eval-heatmap { border-collapse: collapse; white-space: nowrap; }
        .eval-heatmap th, .eval-heatmap td { padding: 6px 10px; border-bottom: 1px solid var(--grid); font-size: 12px; }
        .eval-heatmap th { text-align: center; color: var(--ink-2); font-weight: 600; }
        .eval-heatmap-event { font-weight: 400; color: var(--muted); font-size: 11px; white-space: normal; }
        .eval-heatmap-row-label { text-align: left; font-weight: 600; color: var(--ink); white-space: nowrap; }
        /* Клетка с бележка: пунктирано подчертаване сигнализира "кликни ме"
           без да добавя визуален шум за клетките без бележка. */
        .eval-cell.has-note { cursor: pointer; border-bottom: 1px dotted var(--muted); padding-bottom: 1px; }
        .eval-cell.has-note:hover, .eval-cell.has-note.active { border-bottom-color: var(--series-1); }
        /* Popup — speech-bubble над/под кликнатата клетка, позициониран в JS
           (position:fixed убягва overflow:auto на table-card без нужда от
           преместване в DOM-а). Стрелката е чист CSS триъгълник, посоката
           (сочи надолу/нагоре) се превключва през .eval-popup--below. */
        .eval-popup { position: fixed; z-index: 50; max-width: 300px; background: var(--surface);
            border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.18); padding: 10px 14px;
            font-size: 13px; line-height: 1.4; white-space: normal; }
        .eval-popup::after { content: ""; position: absolute; left: 50%; margin-left: -6px;
            border: 6px solid transparent; }
        .eval-popup:not(.eval-popup--below)::after { top: 100%; border-top-color: var(--surface); }
        .eval-popup.eval-popup--below::after { bottom: 100%; border-bottom-color: var(--surface); }
        .eval-popup-label { display: block; font-weight: 700; color: var(--ink); margin-bottom: 2px; }
        .eval-popup-event { display: block; color: var(--muted); font-size: 11px; margin-bottom: 6px; }

        /* ---- single-point card (протокол с 1 тест — тренд все още невъзможен) ---- */
        .single-card { background: #fafbfc; border: 1px solid var(--grid); border-radius: 8px; padding: 16px 18px; display: flex; gap: 22px; flex-wrap: wrap; align-items: flex-start; }
        .single-stat .tm-label { font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        .single-stat .tm-value { font-size: 20px; font-weight: 700; margin-top: 1px; }
        .single-note { font-size: 12px; color: var(--muted); font-style: italic; margin-left: auto; max-width: 260px; }
        .single-detail { flex-basis: 100%; font-size: 12px; color: var(--ink-2); margin: 4px 0 0; }

        /* ---- radar ---- */
        .radar-card { margin-top: 24px; }
        .radar-card .chart-wrap { position: relative; height: 300px; max-width: 420px; margin: 0 auto; }

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
                            <?php $ev = find_evaluation_for_date($race_evaluations, $r['event_date']); if ($ev): ?>
                                <?= eval_panel_html($ev) ?>
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
                            <?php $ev = find_evaluation_for_date($race_evaluations, $r['event_date']); if ($ev): ?>
                                <?= eval_panel_html($ev) ?>
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
        <h2>Обединена хронология</h2>
        <?php if ($combined_results): ?>
        <table id="combined-results-table">
            <thead>
                <tr>
                    <th>Дата</th><th>Източник</th><th>Плуване</th><th>T1</th>
                    <th>Колело</th><th>T2</th><th>Бягане</th><th>Общо</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($combined_results as $r): ?>
                <tr>
                    <td class="event-date"><?= htmlspecialchars($r['event_date']) ?></td>
                    <td>
                        <span class="source-badge source-<?= htmlspecialchars($r['source']) ?>">
                            <?= $r['source'] === 'wt' ? 'Официално' : 'Местно' ?>
                        </span>
                    </td>
                    <td><?= $r['swim'] !== null && $r['swim'] !== '' ? htmlspecialchars($r['swim']) : '—' ?></td>
                    <td><?= $r['t1'] !== null && $r['t1'] !== '' ? htmlspecialchars($r['t1']) : '—' ?></td>
                    <td><?= $r['bike'] !== null && $r['bike'] !== '' ? htmlspecialchars($r['bike']) : '—' ?></td>
                    <td><?= $r['t2'] !== null && $r['t2'] !== '' ? htmlspecialchars($r['t2']) : '—' ?></td>
                    <td><?= $r['run'] !== null && $r['run'] !== '' ? htmlspecialchars($r['run']) : '—' ?></td>
                    <td class="total-time"><?= $r['total_time'] !== null && $r['total_time'] !== '' ? htmlspecialchars($r['total_time']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty">Няма данни</p>
        <?php endif; ?>
    </div>

    <div class="table-card" style="margin-top:20px;">
        <h2>Зони на тренировка</h2>
        <?php if ($activity_zones): ?>
        <div class="zone-legend">
            <span>По-лек</span>
            <span><span class="swatch" style="background:var(--zone-1)"></span>Z1-2</span>
            <span><span class="swatch" style="background:var(--zone-2)"></span></span>
            <span><span class="swatch" style="background:var(--zone-3)"></span>средна</span>
            <span><span class="swatch" style="background:var(--zone-4)"></span></span>
            <span><span class="swatch" style="background:var(--zone-5)"></span>макс.</span>
            <span>По-интензивен</span>
            <span style="color:var(--muted)">— грубо, точните секунди по зона са в детайлите на реда</span>
        </div>
        <table id="zone-results-table">
            <thead>
                <tr><th>Дата</th><th>Тип</th><th>HR зони</th><th>Power зони</th></tr>
            </thead>
            <tbody>
                <?php foreach ($activity_zones as $r):
                    $hr_segs = zone_segments($r['hr_zone_times_json'], false);
                    $power_segs = zone_segments($r['power_zone_times_json'], true);
                ?>
                <tr class="result-row zone-result-row" tabindex="0" role="button" aria-expanded="false">
                    <td class="event-date"><?= htmlspecialchars($r['activity_date']) ?></td>
                    <td class="msg"><?= htmlspecialchars($r['activity_type'] ?? '—') ?></td>
                    <td><?= zone_bar_html($hr_segs) ?></td>
                    <td><?= zone_bar_html($power_segs) ?></td>
                </tr>
                <tr class="result-detail zone-result-detail" style="display:none;">
                    <td colspan="4">
                        <div class="split-panel">
                            <?php if ($hr_segs): ?>
                            <p style="margin:0 0 4px;font-weight:600;">HR зони</p>
                            <div class="splits-grid">
                                <?php foreach ($hr_segs as $s): if ($s['secs'] <= 0) continue; ?>
                                <div class="split-cell">
                                    <div class="split-label"><?= htmlspecialchars($s['label']) ?></div>
                                    <div class="split-time"><?= gmdate('H:i:s', $s['secs']) ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($power_segs): ?>
                            <p style="margin:10px 0 4px;font-weight:600;">Power зони</p>
                            <div class="splits-grid">
                                <?php foreach ($power_segs as $s): if ($s['secs'] <= 0) continue; ?>
                                <div class="split-cell">
                                    <div class="split-label"><?= htmlspecialchars($s['label']) ?></div>
                                    <div class="split-time"><?= gmdate('H:i:s', $s['secs']) ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <p style="margin:10px 0 0;font-size:12px;color:var(--ink-2);">
                                Ср. мощност: <?= $r['icu_average_watts'] !== null ? round($r['icu_average_watts']) . 'W' : '—' ?> ·
                                Норм. мощност: <?= $r['icu_weighted_avg_watts'] !== null ? round($r['icu_weighted_avg_watts']) . 'W' : '—' ?> ·
                                FTP: <?= $r['icu_ftp'] !== null ? round($r['icu_ftp']) . 'W' : '—' ?>
                            </p>
                            <?php endif; ?>
                            <?php if (!$hr_segs && !$power_segs): ?>
                                <div class="no-splits">Няма детайлни данни</div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty">Няма записани zone данни</p>
        <?php endif; ?>
    </div>

    <div class="table-card" style="margin-top:20px;">
        <h2>Оценки — сезонен тренд</h2>
        <?php if (count($race_evaluations) >= 2): ?>
        <div style="overflow-x:auto;">
        <table class="eval-heatmap">
            <thead>
                <tr>
                    <th>Елемент</th>
                    <?php foreach ($race_evaluations as $ev): ?>
                    <th>
                        <?= htmlspecialchars($ev['event_date']) ?>
                        <?php if (!empty($ev['event_title'])): ?>
                        <br><span class="eval-heatmap-event"><?= htmlspecialchars($ev['event_title']) ?></span>
                        <?php endif; ?>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach (eval_element_groups() as $group => $spec):
                    [$el1, $el2, $notes_key] = $spec;
                    foreach ([$el1, $el2] as $el):
                ?>
                <tr>
                    <td class="eval-heatmap-row-label"><?= htmlspecialchars($group) ?>: <?= htmlspecialchars($el['label']) ?></td>
                    <?php foreach ($race_evaluations as $ev):
                        $note = $ev[$notes_key] ?? null;
                        $badge = eval_score_badge($ev[$el['key']] ?? null);
                    ?>
                    <td style="text-align:center;">
                        <?php if (!empty($note)): ?>
                        <span class="eval-cell has-note" tabindex="0" role="button"
                              data-label="<?= htmlspecialchars($group . ': ' . $el['label']) ?>"
                              data-event="<?= htmlspecialchars(($ev['event_title'] ?? '') . ' (' . $ev['event_date'] . ')') ?>"
                              data-note="<?= htmlspecialchars($note) ?>"><?= $badge ?></span>
                        <?php else: ?>
                            <?= $badge ?>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; endforeach; ?>
            </tbody>
        </table>
        <div id="eval-popup" class="eval-popup" role="tooltip" style="display:none;"></div>
        </div>
        <?php else: ?>
            <p class="empty">Нужни са поне 2 състезания с оценки за тренд</p>
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
        <?php if ($nat_tests):
            // Payload за JS chart-овете по-долу (trend мини-графика + radar
            // на протокол с 2+ теста) — събран тук, вкаран в <script> накрая.
            $nat_js_charts = [];
        ?>
        <p class="hint" style="margin:0 0 16px;">
            Отделно от клубните лактатни тестове по-горе — различен протокол. Стойности между вело и тредбанд
            не се сравняват директно (различен физиологичен таван за същия атлет) — <code>nat_tests_comparable()</code>
            пази това навсякъде по-долу.
        </p>

        <?php foreach ($nat_blocks as $protocol => $block):
            $protocol_row = $block['protocol_row'];
            $device_label = $protocol_row['device'] ?? $protocol;
            $description = nat_protocol_description($protocol_row);
            $dot_color = $block['is_bike'] ? 'var(--series-1)' : 'var(--series-2)';
            $canvas_id = 'natTrend_' . preg_replace('/[^a-zA-Z0-9]/', '_', $protocol);
            $radar_id = 'natRadar_' . preg_replace('/[^a-zA-Z0-9]/', '_', $protocol);
        ?>
        <div class="protocol-block">
            <div class="protocol-head">
                <span class="protocol-dot" style="background:<?= $dot_color ?>"></span>
                <h3><?= htmlspecialchars($device_label) ?></h3>
            </div>
            <p class="protocol-meta">
                <?= $description ? htmlspecialchars($description) : '' ?>
                <?= $description ? ' · ' : '' ?><?= $block['count'] ?> тест<?= $block['count'] === 1 ? '' : 'а' ?>
                <?php if ($block['count'] < 2): ?> — тренд все още не е възможен<?php endif; ?>
            </p>

            <?php if ($block['count'] < 2):
                $t = $block['tests_asc'][0];
            ?>
            <div class="single-card">
                <div class="single-stat"><div class="tm-label">Дата</div><div class="tm-value" style="font-size:15px"><?= htmlspecialchars($t['test_date']) ?></div></div>
                <div class="single-stat"><div class="tm-label"><?= $block['is_bike'] ? 'W_max' : 'S_max' ?></div><div class="tm-value"><?= nat_primary_metric($t, $protocol_row) ?></div></div>
                <div class="single-stat"><div class="tm-label">VO2max/kg</div><div class="tm-value"><?= fmt($t['vo2max_kg'], 2) ?></div></div>
                <div class="single-stat"><div class="tm-label">HR_max</div><div class="tm-value"><?= $t['hr_max'] !== null ? (int)$t['hr_max'] : '—' ?></div></div>
                <div class="single-stat"><div class="tm-label">ЕПЗ</div><div class="tm-value" style="font-size:15px"><?= nat_epz_text($t['epz_from'], $t['epz_to']) ?></div></div>
                <div class="single-note">Един тест — показан като референтна точка, не като тренд. Следващ тест на този протокол ще отключи сравнение.</div>
                <p class="single-detail">
                    Ръст: <?= fmt($t['height_cm'], 0) ?> см · Разтег: <?= fmt($t['arm_span_cm'], 0) ?> см ·
                    Тегло: <?= fmt($t['weight_kg'], 1) ?> кг · АТМ: <?= fmt($t['lean_mass_kg'], 1) ?> кг ·
                    Продължителност: <?= fmt($t['duration_min'], 1) ?> мин<br>
                    Състав: <?= nat_comp_bar_html($t['fat_pct'], $t['fat_kg'], $t['muscle_pct'], $t['muscle_kg']) ?><br>
                    Лактат: La 2' <?= nat_la_cell_html($t['la_2'], $t['hr_2']) ?> ·
                    La 6' <?= nat_la_cell_html($t['la_6'], $t['hr_6']) ?> ·
                    La 15' <?= fmt($t['la_15'], 1) ?>
                </p>
            </div>

            <?php else:
                $tests_asc = $block['tests_asc'];
                $first = $tests_asc[0];
                $last = $tests_asc[count($tests_asc) - 1];
                $nat_js_charts[$canvas_id] = [
                    'labels' => $block['trend_labels'],
                    'data' => $block['trend_vo2'],
                    'color' => $block['is_bike'] ? '#2a78d6' : '#1baf7a',
                ];
                if (!empty($block['radar_labels'])) {
                    $nat_js_charts[$radar_id] = [
                        'type' => 'radar',
                        'labels' => $block['radar_labels'],
                        'latest' => $block['radar_latest'],
                        'latest_date' => $block['radar_latest_date'],
                        'prev' => $block['radar_prev'],
                        'prev_date' => $block['radar_prev_date'],
                        'color' => $block['is_bike'] ? '#2a78d6' : '#1baf7a',
                    ];
                }
            ?>
            <div class="protocol-grid">
                <div class="trend-card">
                    <div class="chart-wrap"><canvas id="<?= $canvas_id ?>"></canvas></div>
                    <div class="trend-metrics">
                        <?php foreach ($block['trend_metrics'] as $tm): ?>
                        <div class="trend-metric">
                            <div class="tm-label"><?= htmlspecialchars($tm['label']) ?></div>
                            <div class="tm-value">
                                <?= fmt($tm['value'], $tm['decimals']) . htmlspecialchars($tm['unit']) ?><?php if ($tm['sign']): ?><span class="tm-delta <?= htmlspecialchars($tm['color']) ?>">
                                    <?= nat_delta_arrow($tm['sign']) ?><?= $tm['pct'] !== null ? ' ' . number_format(abs($tm['pct']), 0) . '%' : '' ?>
                                </span><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="cmp-wrap">
                    <table class="cmp-table">
                        <thead>
                            <tr>
                                <th>Показател</th>
                                <?php foreach ($tests_asc as $t): ?><th><?= htmlspecialchars($t['test_date']) ?></th><?php endforeach; ?>
                                <th class="delta-col">Δ общо</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="group-row"><td colspan="<?= count($tests_asc) + 2 ?>">Натоварване</td></tr>
                            <?php if ($block['is_bike']): ?>
                            <tr>
                                <td>W_max</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= $t['w_max'] !== null ? (int)round($t['w_max']) . ' W' : '—' ?></td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('w_max', $first['w_max'], $last['w_max'], 0, ' W', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                            <tr>
                                <td>W_max/kg</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= fmt($t['w_max_kg'], 2) ?></td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('w_max_kg', $first['w_max_kg'], $last['w_max_kg'], 2, '', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <td>S_max</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= fmt($t['s_max_kmh'], 1) ?> км/ч</td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('s_max_kmh', $first['s_max_kmh'], $last['s_max_kmh'], 1, ' км/ч', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td>VO2max</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= fmt($t['vo2max'], 0) ?></td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('vo2max', $first['vo2max'], $last['vo2max'], 0, '', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                            <tr>
                                <td>VO2max/kg</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= fmt($t['vo2max_kg'], 2) ?></td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('vo2max_kg', $first['vo2max_kg'], $last['vo2max_kg'], 2, '', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                            <tr>
                                <td>HR_max</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= $t['hr_max'] !== null ? (int)$t['hr_max'] : '—' ?></td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('hr_max', $first['hr_max'], $last['hr_max'], 0, '', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                            <tr>
                                <td>ЕПЗ</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= nat_epz_text($t['epz_from'], $t['epz_to']) ?></td><?php endforeach; ?>
                                <td class="delta-col"><span class="delta flat">—</span></td>
                            </tr>
                            <tr>
                                <td>Продължителност</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= fmt($t['duration_min'], 1) ?> мин</td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('duration_min', $first['duration_min'], $last['duration_min'], 1, ' мин', $first['protocol'], $last['protocol']) ?></td>
                            </tr>

                            <tr class="group-row"><td colspan="<?= count($tests_asc) + 2 ?>">Телесен състав</td></tr>
                            <tr>
                                <td>Тегло</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= fmt($t['weight_kg'], 1) ?> кг</td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('weight_kg', $first['weight_kg'], $last['weight_kg'], 1, ' кг', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                            <tr>
                                <td>Ръст</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= fmt($t['height_cm'], 0) ?> см</td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('height_cm', $first['height_cm'], $last['height_cm'], 0, ' см', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                            <tr>
                                <td>Разтег</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= fmt($t['arm_span_cm'], 0) ?> см</td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('arm_span_cm', $first['arm_span_cm'], $last['arm_span_cm'], 0, ' см', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                            <tr>
                                <td>АТМ</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= fmt($t['lean_mass_kg'], 1) ?> кг</td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('lean_mass_kg', $first['lean_mass_kg'], $last['lean_mass_kg'], 1, ' кг', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                            <tr class="comp-bar-row">
                                <td>Състав <span style="font-weight:400;color:var(--muted)">(мазн./муск.)</span></td>
                                <?php foreach ($tests_asc as $t): ?><td><?= nat_comp_bar_html($t['fat_pct'], $t['fat_kg'], $t['muscle_pct'], $t['muscle_kg']) ?></td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_comp_delta_cell_html($first['muscle_pct'], $last['muscle_pct']) ?></td>
                            </tr>

                            <tr class="group-row"><td colspan="<?= count($tests_asc) + 2 ?>">Лактат при връх</td></tr>
                            <tr>
                                <td>La 2'</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= nat_la_cell_html($t['la_2'], $t['hr_2']) ?></td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('la_2', $first['la_2'], $last['la_2'], 1, '', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                            <tr>
                                <td>La 6'</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= nat_la_cell_html($t['la_6'], $t['hr_6']) ?></td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('la_6', $first['la_6'], $last['la_6'], 1, '', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                            <tr>
                                <td>La 15'</td>
                                <?php foreach ($tests_asc as $t): ?><td><?= fmt($t['la_15'], 1) ?></td><?php endforeach; ?>
                                <td class="delta-col"><?= nat_delta_cell_html('la_15', $first['la_15'], $last['la_15'], 1, '', $first['protocol'], $last['protocol']) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (!empty($block['radar_labels'])): ?>
            <div class="radar-card">
                <h3 style="margin:0 0 2px;font-size:15px;">Профил при последния тест (<?= htmlspecialchars($block['radar_latest_date']) ?>) — спрямо личния максимум</h3>
                <p class="protocol-meta" style="margin-bottom:10px;">
                    Всяка ос е нормализирана към собствения най-добър резултат на атлета в ТОЗИ протокол (= 100%) —
                    личният максимум по ос може да идва от по-стар тест, затова последният тест не е задължително 100% навсякъде.
                </p>
                <div class="chart-wrap"><canvas id="<?= $radar_id ?>"></canvas></div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
            <p class="empty">Няма национални функционални тестове</p>
        <?php endif; ?>
    </div>

    <?php render_metrics_legend(); ?>

    <script>
    const DATA = <?= json_encode($chart_data, JSON_UNESCAPED_UNICODE) ?>;
    const NAT_CHARTS = <?= json_encode($nat_js_charts ?? [], JSON_UNESCAPED_UNICODE) ?>;

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

    // НЦ функционални тестове: по един trend line chart + (ако протоколът
    // има personal-best данни за поне 2 общи оси) радар на протокол с 2+
    // теста. Всеки canvas id е уникален (natTrend_<protocol>/natRadar_<protocol>),
    // затова просто минаваме през NAT_CHARTS без да гадаем броя/типа предварително —
    // единичните тестове (single-card) изобщо нямат запис тук.
    Object.keys(NAT_CHARTS).forEach(function (id) {
        const el = document.getElementById(id);
        if (!el) return;
        const cfg = NAT_CHARTS[id];

        if (cfg.type === 'radar') {
            new Chart(el, {
                type: 'radar',
                data: {
                    labels: cfg.labels,
                    datasets: [
                        {
                            label: cfg.latest_date + ' (последен)',
                            data: cfg.latest,
                            borderColor: cfg.color, backgroundColor: cfg.color + '26',
                            borderWidth: 2, pointRadius: 3
                        },
                        {
                            label: cfg.prev_date,
                            data: cfg.prev,
                            borderColor: MUTED, backgroundColor: 'rgba(137,135,129,0.08)',
                            borderWidth: 1.5, borderDash: [4, 3], pointRadius: 2
                        }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, font: { size: 11 } } } },
                    scales: { r: { min: 0, max: 100, ticks: { display: false }, grid: { color: GRID } } }
                }
            });
        } else {
            new Chart(el, {
                type: 'line',
                data: { labels: cfg.labels, datasets: [series('VO2max/kg', cfg.data, cfg.color)] },
                options: baseOptions()
            });
        }
    });

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

    // Зони на тренировка: същото expand/collapse, без year filter (7-8 реда,
    // не си заслужава допълнителна навигация — виж лактатните тестове по-горе).
    (function () {
        function toggleRow(row) {
            const detail = row.nextElementSibling;
            if (!detail || !detail.classList.contains('zone-result-detail')) return;
            const willOpen = detail.style.display === 'none';
            detail.style.display = willOpen ? '' : 'none';
            row.classList.toggle('open', willOpen);
            row.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        }
        document.querySelectorAll('#zone-results-table tbody tr.zone-result-row').forEach(function (row) {
            row.addEventListener('click', function () { toggleRow(row); });
            row.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    toggleRow(row);
                }
            });
        });
    }());

    // Оценки — сезонен тренд: клик върху клетка с бележка отваря speech-bubble
    // popup над/под нея (position:fixed, преизчислено при всеки клик — table-card
    // скролва хоризонтално, а fixed убягва overflow clipping без нужда клетката
    // да е извън scroll контейнера). textContent навсякъде, не innerHTML с низове —
    // бележките идват от треньора през Sheet, но няма причина да им вярваме
    // сляпо като на HTML.
    (function () {
        const popup = document.getElementById('eval-popup');
        if (!popup) return;
        let activeCell = null;

        function closePopup() {
            popup.style.display = 'none';
            if (activeCell) activeCell.classList.remove('active');
            activeCell = null;
        }

        function openPopup(cell) {
            popup.textContent = '';
            const label = document.createElement('span');
            label.className = 'eval-popup-label';
            label.textContent = cell.dataset.label;
            const eventLine = document.createElement('span');
            eventLine.className = 'eval-popup-event';
            eventLine.textContent = cell.dataset.event;
            popup.appendChild(label);
            popup.appendChild(eventLine);
            popup.appendChild(document.createTextNode(cell.dataset.note));

            popup.classList.remove('eval-popup--below');
            popup.style.display = 'block';

            const cellRect = cell.getBoundingClientRect();
            const popupRect = popup.getBoundingClientRect();
            const margin = 10;
            let below = false;
            let top = cellRect.top - popupRect.height - margin;
            if (top < 8) {
                top = cellRect.bottom + margin;
                below = true;
            }
            let left = cellRect.left + cellRect.width / 2 - popupRect.width / 2;
            left = Math.max(8, Math.min(left, window.innerWidth - popupRect.width - 8));

            popup.style.top = top + 'px';
            popup.style.left = left + 'px';
            if (below) popup.classList.add('eval-popup--below');

            if (activeCell) activeCell.classList.remove('active');
            cell.classList.add('active');
            activeCell = cell;
        }

        document.querySelectorAll('.eval-cell.has-note').forEach(function (cell) {
            cell.addEventListener('click', function (ev) {
                ev.stopPropagation();
                if (activeCell === cell) { closePopup(); return; }
                openPopup(cell);
            });
            cell.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    cell.click();
                } else if (ev.key === 'Escape') {
                    closePopup();
                }
            });
        });

        document.addEventListener('click', function (ev) {
            if (activeCell && !popup.contains(ev.target) && ev.target !== activeCell) closePopup();
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') closePopup();
        });
        window.addEventListener('scroll', closePopup, true);
        window.addEventListener('resize', closePopup);
    }());
    </script>
</body>
</html>
