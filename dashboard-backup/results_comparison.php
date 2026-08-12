<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_login();

$pdo = get_db_connection();

$athlete_id = isset($_GET['id']) ? $_GET['id'] : '';
if ($athlete_id === '') {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("SELECT athlete_name FROM daily_metrics WHERE athlete_id = ? LIMIT 1");
$stmt->execute([$athlete_id]);
$athlete_row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$athlete_row) {
    header('Location: dashboard.php');
    exit;
}
$athlete_name = $athlete_row['athlete_name'];

// Пълен списък на следените атлети (за "сравни и с атлет" селектора) —
// същата заявка като dashboard.php, за да е динамичен списъкът, не хардкоднат.
$roster = $pdo->query("SELECT DISTINCT athlete_id, athlete_name FROM daily_metrics ORDER BY athlete_name")
    ->fetchAll(PDO::FETCH_ASSOC);

// World Triathlon щафетни резултати (Mixed/Team/Youth Relay) споделят
// event_id с индивидуалния резултат на същия атлет от същото състезание —
// изглеждат като "дубликат" в списъка (същото заглавие/дата, различно
// време/позиция). Ръчно потвърдени през WT API-то
// (GET /events/{event_id}/programs/{prog_id} -> prog_name съдържа "Relay")
// на 2026-08-12 за всички редове, споделящи event_id с друг ред в базата.
// Скрити САМО на тази страница по изрична заявка — базата/pipeline-ът не
// се пипат, затова списъкът е статичен и не хваща автоматично бъдещи
// щафетни резултати; ще трябва да се допълни ръчно при нужда.
const RC_RELAY_EVENT_PROG_IDS = [
    '172513:582613', // Mixed Junior Relay
    '172516:578766', // Mixed Youth Relay
    '184418:636400', // Mixed Junior Relay
    '184421:634061', // Mixed Junior Relay
    '184435:634346', // Mixed Junior Relay
    '184438:634474', // Mixed Relay
    '184704:635548', // Mixed Relay
    '194266:676125', // Mixed Junior Relay
    '195201:678246', // Mixed Junior Relay
];

// Обединен местни+World Triathlon списък с row_id (стабилен ключ за
// чекбокс/URL селекция) и всички сплитове — извадено във функция, защото
// сега може да се вика за 2 атлета (основния + избрания за сравнение).
function rc_fetch_combined_results($pdo, $athlete_name) {
    try {
        $stmt = $pdo->prepare("
            SELECT 'local-' || r.id AS row_id, event_date, 'local' AS source,
                   e.name AS event_name,
                   leg1 AS swim, t1, leg2 AS bike, t2, leg3 AS run, total_time,
                   NULL AS event_id, NULL AS prog_id
            FROM local_results r JOIN local_events e ON e.event_id = r.event_id
            WHERE r.athlete_name = ? AND r.sport = 'triathlon'

            UNION ALL

            SELECT 'wt-' || id AS row_id, event_date, 'wt' AS source,
                   event_title AS event_name,
                   swim_split AS swim, t1_split AS t1, bike_split AS bike,
                   t2_split AS t2, run_split AS run, total_time,
                   event_id, prog_id
            FROM world_triathlon_results
            WHERE athlete_name = ?

            ORDER BY event_date DESC
        ");
        $stmt->execute([$athlete_name, $athlete_name]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, function ($r) {
            return !in_array($r['event_id'] . ':' . $r['prog_id'], RC_RELAY_EVENT_PROG_IDS, true);
        }));
    } catch (PDOException $e) {
        return [];
    }
}

$combined_results = rc_fetch_combined_results($pdo, $athlete_name);

// Втори атлет за сравнение (?compare_athlete=<id>) — валидира се срещу
// $roster, невалиден/собствен ID просто се игнорира мълчаливо.
$compare_athlete_id = isset($_GET['compare_athlete']) ? $_GET['compare_athlete'] : '';
$compare_athlete_name = null;
if ($compare_athlete_id !== '' && $compare_athlete_id !== $athlete_id) {
    foreach ($roster as $a) {
        if ($a['athlete_id'] === $compare_athlete_id) {
            $compare_athlete_name = $a['athlete_name'];
            break;
        }
    }
    if ($compare_athlete_name === null) {
        $compare_athlete_id = '';
    }
}
$compare_combined_results = $compare_athlete_name !== null
    ? rc_fetch_combined_results($pdo, $compare_athlete_name)
    : [];

// Една "карта" на атлет = списъкът му + годините му за year-nav бутоните.
// Динамично 1 или 2 карти, в зависимост дали е избран втори атлет.
function rc_years($results) {
    $years = array_values(array_unique(array_map(
        fn($r) => substr($r['event_date'], 0, 4),
        $results
    )));
    return [$years, $years[0] ?? null];
}

$race_cards = [
    ['athlete_id' => $athlete_id, 'athlete_name' => $athlete_name, 'results' => $combined_results],
];
if ($compare_athlete_name !== null) {
    $race_cards[] = ['athlete_id' => $compare_athlete_id, 'athlete_name' => $compare_athlete_name, 'results' => $compare_combined_results];
}

// Избрани стартове от URL-а (?races=id1,id2,...) — bookmarkable/споделим
// линк, същия принцип като lactate_analysis.php's ?compare=. Невалидни
// row_id-та (изтрит резултат) просто отпадат мълчаливо по-долу. Пулът е
// обединен от двете карти, за да може да сравняваш стартове на различни
// атлети (row_id е глобален PK в local_results/world_triathlon_results,
// няма риск от колизия между атлети).
$selected_ids = [];
if (isset($_GET['races']) && $_GET['races'] !== '') {
    $selected_ids = array_filter(array_map('trim', explode(',', $_GET['races'])));
}

$all_results_pool = [];
foreach ($race_cards as $card) {
    foreach ($card['results'] as $r) {
        $r['athlete_name'] = $card['athlete_name'];
        $all_results_pool[] = $r;
    }
}

$selected_results = array_values(array_filter($all_results_pool, function ($r) use ($selected_ids) {
    return in_array($r['row_id'], $selected_ids, true);
}));
usort($selected_results, function ($a, $b) { return strcmp($a['event_date'], $b['event_date']); });

function rc_cell($value) {
    return ($value !== null && $value !== '') ? htmlspecialchars($value) : '—';
}

// "0:10:19" / "00:10:57" / "1:06:07" -> секунди. Произволен брой ':'-групи,
// последните две винаги са мин:сек, всичко преди са часове.
function rc_time_to_seconds($str) {
    if ($str === null || $str === '') return null;
    $parts = explode(':', $str);
    foreach ($parts as $p) {
        if ($p === '' || !is_numeric($p)) return null;
    }
    $seconds = 0;
    $mult = 1;
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        $seconds += (int)$parts[$i] * $mult;
        $mult *= 60;
    }
    return $seconds;
}

// Разлика между първия (най-стар, ляво) и последния (най-нов, дясно)
// избран старт — само мин:сек, никога часове (сравняваме сплитове/общо
// време на триатлон, delta от порядъка на часове не се случва). По-малко
// време = по-бърз старт = подобрение -> зелено; повече -> червено.
function rc_delta_cell($first_val, $last_val, $is_time) {
    if (!$is_time) return ['text' => '—', 'class' => 'delta-neutral'];
    $a = rc_time_to_seconds($first_val);
    $b = rc_time_to_seconds($last_val);
    if ($a === null || $b === null) return ['text' => '—', 'class' => 'delta-neutral'];
    $delta = $b - $a;
    $sign = $delta < 0 ? '−' : ($delta > 0 ? '+' : '±');
    $abs = abs($delta);
    $mins = intdiv($abs, 60);
    $secs = $abs % 60;
    $text = $sign . $mins . ':' . str_pad((string)$secs, 2, '0', STR_PAD_LEFT);
    $class = $delta < 0 ? 'delta-good' : ($delta > 0 ? 'delta-bad' : 'delta-neutral');
    return ['text' => $text, 'class' => $class];
}

$compare_metrics = [
    ['athlete_name', 'Атлет', false],
    ['event_name', 'Състезание', false],
    ['source', 'Източник', false],
    ['swim', 'Плуване', true],
    ['t1', 'T1', true],
    ['bike', 'Колело', true],
    ['t2', 'T2', true],
    ['run', 'Бягане', true],
    ['total_time', 'Общо', true],
];
$show_delta = count($selected_results) >= 2;
$first_selected = $selected_results[0] ?? null;
$last_selected = $selected_results[count($selected_results) - 1] ?? null;
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($athlete_name) ?> — Сравнение на стартове</title>
    <style>
        :root {
            --ink: #0b0b0b;
            --ink-2: #52514e;
            --muted: #898781;
            --grid: #e1e0d9;
            --surface: #ffffff;
        }
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: var(--ink); }
        a { color: #2250e3; text-decoration: none; }
        .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 6px; }
        h1 { margin: 0; font-size: 22px; }
        .subheader { color: var(--ink-2); font-size: 14px; margin-bottom: 14px; }
        .hint { color: var(--muted); font-size: 12.5px; margin: 0 0 14px; }
        .compare-athlete-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
        .compare-athlete-row label { font-size: 13px; color: var(--ink-2); font-weight: 600; }
        .compare-athlete-row select {
            font: inherit; font-size: 13px; padding: 5px 8px; border-radius: 6px;
            border: 1px solid var(--grid); background: var(--surface); color: var(--ink);
        }
        .year-nav { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
        .year-nav button { padding: 5px 12px; border-radius: 14px; font-size: 14px; border: none; background: #eceae4; color: var(--ink-2); cursor: pointer; }
        .year-nav button.active { background: #2250e3; color: white; }
        .table-card { background: var(--surface); border-radius: 8px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow-x: auto; }
        .table-card + .table-card { margin-top: 20px; }
        .table-card h2 { margin: 0 0 10px; font-size: 16px; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; }
        th { text-align: left; color: var(--ink-2); font-weight: 600; padding: 6px 14px 6px 0; border-bottom: 1px solid var(--grid); white-space: nowrap; }
        td { padding: 6px 14px 6px 0; border-bottom: 1px solid #f0efec; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .empty { color: var(--muted); font-style: italic; font-size: 14px; }
        .check-col { width: 30px; }
        .checkbox { width: 16px; height: 16px; cursor: pointer; }
        .race-list tbody tr.selected td { background: #eef1fb; }
        .event-name { font-weight: 600; color: var(--ink); }
        .source-badge { display: inline-block; padding: 3px 10px; border-radius: 8px; font-weight: 600; font-size: 12px; }
        .source-badge.source-wt    { background: #eef1fb; color: #2250e3; }
        .source-badge.source-local { background: #e8f5ec; color: #1f7a3d; }
        /* Сравнителна таблица: metrics като редове, избраните стартове като колони (sticky първа колона) */
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
        .delta-col { border-left: 2px solid var(--grid); }
        .delta-good { color: #2e7d32; font-weight: 700; }
        .delta-bad { color: #c62828; font-weight: 700; }
        .delta-neutral { color: var(--ink-2); font-weight: 600; }
        @media (max-width: 480px) {
            body { padding: 12px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?= htmlspecialchars($athlete_name) ?> — Сравнение на стартове</h1>
        <a href="athlete.php?id=<?= urlencode($athlete_id) ?>">&larr; Назад към профила</a>
    </div>
    <div class="subheader">Обединена хронология — местни и World Triathlon състезания</div>
    <p class="hint">Изберете един или повече старта, за да сравните сплитовете им — изборът се пази и при смяна на година и на атлет.</p>

    <?php if (count($roster) > 1): ?>
    <div class="compare-athlete-row">
        <label for="compare-athlete-select">Сравни и с атлет:</label>
        <select id="compare-athlete-select">
            <option value="">— няма —</option>
            <?php foreach ($roster as $a): if ($a['athlete_id'] === $athlete_id) continue; ?>
            <option value="<?= htmlspecialchars($a['athlete_id']) ?>" <?= $a['athlete_id'] === $compare_athlete_id ? 'selected' : '' ?>><?= htmlspecialchars($a['athlete_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php foreach ($race_cards as $card):
        [$years, $default_year] = rc_years($card['results']);
    ?>
    <div class="table-card race-card" data-athlete-id="<?= htmlspecialchars($card['athlete_id']) ?>">
        <h2>Всички стартове — <?= htmlspecialchars($card['athlete_name']) ?></h2>
        <?php if ($card['results']): ?>
        <nav class="year-nav" aria-label="Филтър по година">
            <?php foreach ($years as $year): ?>
                <button type="button" data-year="<?= htmlspecialchars($year) ?>"
                        class="<?= $year === $default_year ? 'active' : '' ?>"><?= htmlspecialchars($year) ?></button>
            <?php endforeach; ?>
        </nav>
        <table class="race-list">
            <thead>
                <tr>
                    <th class="check-col"></th>
                    <th>Дата</th><th>Състезание</th><th>Източник</th><th>Общо</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($card['results'] as $r):
                    $row_year = substr($r['event_date'], 0, 4);
                ?>
                <tr data-row-id="<?= htmlspecialchars($r['row_id']) ?>"
                    data-year="<?= htmlspecialchars($row_year) ?>"
                    data-date="<?= htmlspecialchars($r['event_date']) ?>"
                    data-athlete="<?= htmlspecialchars($card['athlete_name']) ?>"
                    data-name="<?= htmlspecialchars($r['event_name'] ?? '') ?>"
                    data-source="<?= htmlspecialchars($r['source'] === 'wt' ? 'Официално' : 'Местно') ?>"
                    data-swim="<?= htmlspecialchars($r['swim'] ?? '') ?>"
                    data-t1="<?= htmlspecialchars($r['t1'] ?? '') ?>"
                    data-bike="<?= htmlspecialchars($r['bike'] ?? '') ?>"
                    data-t2="<?= htmlspecialchars($r['t2'] ?? '') ?>"
                    data-run="<?= htmlspecialchars($r['run'] ?? '') ?>"
                    data-total="<?= htmlspecialchars($r['total_time'] ?? '') ?>"
                    <?= $row_year !== $default_year ? 'style="display:none;"' : '' ?>>
                    <td class="check-col">
                        <input type="checkbox" class="checkbox" value="<?= htmlspecialchars($r['row_id']) ?>"
                            <?= in_array($r['row_id'], $selected_ids, true) ? 'checked' : '' ?>>
                    </td>
                    <td><?= htmlspecialchars($r['event_date']) ?></td>
                    <td class="event-name"><?= rc_cell($r['event_name']) ?></td>
                    <td>
                        <span class="source-badge source-<?= htmlspecialchars($r['source']) ?>">
                            <?= $r['source'] === 'wt' ? 'Официално' : 'Местно' ?>
                        </span>
                    </td>
                    <td><?= rc_cell($r['total_time']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty">Няма данни</p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="table-card" id="compare-card" style="<?= $selected_results ? '' : 'display:none;' ?>">
        <h2>Сравнение</h2>
        <div class="cmp-wrap">
            <table class="cmp-table" id="compare-table">
                <thead><tr id="compare-head">
                    <th>Показател</th>
                    <?php foreach ($selected_results as $r): ?><th><?= htmlspecialchars($r['event_date']) ?></th><?php endforeach; ?>
                    <?php if ($show_delta): ?><th class="delta-col">Δ</th><?php endif; ?>
                </tr></thead>
                <tbody id="compare-body">
                    <?php foreach ($compare_metrics as [$key, $label, $is_time]): ?>
                    <tr>
                        <td><?= htmlspecialchars($label) ?></td>
                        <?php foreach ($selected_results as $r): ?>
                        <td><?= $key === 'source' ? htmlspecialchars($r['source'] === 'wt' ? 'Официално' : 'Местно') : rc_cell($r[$key]) ?></td>
                        <?php endforeach; ?>
                        <?php if ($show_delta):
                            $d = rc_delta_cell($first_selected[$key] ?? null, $last_selected[$key] ?? null, $is_time);
                        ?>
                        <td class="delta-col <?= $d['class'] ?>"><?= htmlspecialchars($d['text']) ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="empty" id="compare-empty" style="<?= $selected_results ? 'display:none;' : '' ?>">Няма избрани стартове за сравнение.</p>

    <script>
    (function () {
        const checkboxes = document.querySelectorAll('.checkbox');
        const compareCard = document.getElementById('compare-card');
        const emptyMsg = document.getElementById('compare-empty');
        const head = document.getElementById('compare-head');
        const body = document.getElementById('compare-body');

        // [dataset ключ, етикет, дали е time стойност (участва в Δ)]
        const METRICS = [
            ['athlete', 'Атлет', false], ['name', 'Състезание', false], ['source', 'Източник', false],
            ['swim', 'Плуване', true], ['t1', 'T1', true], ['bike', 'Колело', true],
            ['t2', 'T2', true], ['run', 'Бягане', true], ['total', 'Общо', true],
        ];

        function cell(value) {
            return value && value !== '' ? value : '—';
        }

        // "0:10:19" / "1:06:07" -> секунди, огледално на rc_time_to_seconds() в PHP.
        function timeToSeconds(str) {
            if (!str) return null;
            const parts = str.split(':');
            if (parts.some(p => p === '' || isNaN(Number(p)))) return null;
            let seconds = 0, mult = 1;
            for (let i = parts.length - 1; i >= 0; i--) {
                seconds += Number(parts[i]) * mult;
                mult *= 60;
            }
            return seconds;
        }

        // Само мин:сек (никога часове — сравняваме сплитове/общо време на
        // триатлон). По-малко = по-бърз старт = подобрение -> зелено.
        function deltaCell(firstVal, lastVal, isTime) {
            if (!isTime) return { text: '—', cls: 'delta-neutral' };
            const a = timeToSeconds(firstVal), b = timeToSeconds(lastVal);
            if (a === null || b === null) return { text: '—', cls: 'delta-neutral' };
            const delta = b - a;
            const sign = delta < 0 ? '−' : (delta > 0 ? '+' : '±');
            const abs = Math.abs(delta);
            const mins = Math.floor(abs / 60), secs = abs % 60;
            const text = sign + mins + ':' + String(secs).padStart(2, '0');
            const cls = delta < 0 ? 'delta-good' : (delta > 0 ? 'delta-bad' : 'delta-neutral');
            return { text, cls };
        }

        function render() {
            const selected = Array.from(checkboxes).filter(cb => cb.checked);
            checkboxes.forEach(cb => {
                cb.closest('tr').classList.toggle('selected', cb.checked);
            });

            if (!selected.length) {
                compareCard.style.display = 'none';
                emptyMsg.style.display = '';
            } else {
                compareCard.style.display = '';
                emptyMsg.style.display = 'none';

                const rows = selected
                    .map(cb => cb.closest('tr'))
                    .sort((a, b) => a.dataset.date.localeCompare(b.dataset.date));
                const showDelta = rows.length >= 2;
                const first = rows[0], last = rows[rows.length - 1];

                head.innerHTML = '<th>Показател</th>' +
                    rows.map(r => `<th>${r.dataset.date}</th>`).join('') +
                    (showDelta ? '<th class="delta-col">Δ</th>' : '');

                body.innerHTML = METRICS.map(([key, label, isTime]) => {
                    const cells = rows.map(r => `<td>${cell(r.dataset[key])}</td>`).join('');
                    let deltaHtml = '';
                    if (showDelta) {
                        const d = deltaCell(first.dataset[key], last.dataset[key], isTime);
                        deltaHtml = `<td class="delta-col ${d.cls}">${d.text}</td>`;
                    }
                    return `<tr><td>${label}</td>${cells}${deltaHtml}</tr>`;
                }).join('');
            }

            const params = new URLSearchParams(location.search);
            const ids = selected.map(cb => cb.value);
            if (ids.length) {
                params.set('races', ids.join(','));
            } else {
                params.delete('races');
            }
            const qs = params.toString();
            history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
        }

        checkboxes.forEach(cb => cb.addEventListener('change', render));

        // Филтър по година — визуално стеснява всяка карта до 1 година, но НЕ
        // пипа чекнатите чекбоксове (сравнението може да е между-годишно).
        // Обходено през всички .year-nav (1 карта = 1 nav, а карти вече може
        // да са 2 — основен атлет + избран за сравнение).
        document.querySelectorAll('.year-nav').forEach(function (nav) {
            const card = nav.closest('.race-card');
            if (!card) return;
            const yearButtons = nav.querySelectorAll('button');
            const rows = card.querySelectorAll('.race-list tbody tr');
            nav.addEventListener('click', function (ev) {
                const btn = ev.target.closest('button');
                if (!btn) return;
                const year = btn.dataset.year;
                yearButtons.forEach(b => b.classList.toggle('active', b === btn));
                rows.forEach(r => { r.style.display = r.dataset.year === year ? '' : 'none'; });
            });
        });

        // Смяна на "сравни и с атлет" -> презареждане със същите ?races=
        // (селекцията оцелява) + новия/премахнат ?compare_athlete=.
        const athleteSelect = document.getElementById('compare-athlete-select');
        if (athleteSelect) {
            athleteSelect.addEventListener('change', function () {
                const params = new URLSearchParams(location.search);
                if (athleteSelect.value) {
                    params.set('compare_athlete', athleteSelect.value);
                } else {
                    params.delete('compare_athlete');
                }
                location.search = params.toString();
            });
        }
    }());
    </script>
</body>
</html>
