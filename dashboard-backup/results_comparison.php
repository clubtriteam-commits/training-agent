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

// Същият обединен местни+World Triathlon списък като athlete.php, но с
// row_id (стабилен ключ за чекбокс/URL селекция) и всички сплитове —
// athlete.php само линква тук, самото сравнение живее на тази страница.
$combined_results = [];
try {
    $stmt = $pdo->prepare("
        SELECT 'local-' || r.id AS row_id, event_date, 'local' AS source,
               e.name AS event_name,
               leg1 AS swim, t1, leg2 AS bike, t2, leg3 AS run, total_time
        FROM local_results r JOIN local_events e ON e.event_id = r.event_id
        WHERE r.athlete_name = ? AND r.sport = 'triathlon'

        UNION ALL

        SELECT 'wt-' || id AS row_id, event_date, 'wt' AS source,
               event_title AS event_name,
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

// Наличните години (за бутоните), най-новата първа = избрана по подразбиране —
// същия патърн като $result_years/$default_year в athlete.php. $combined_results
// вече е ORDER BY event_date DESC, затова array_unique запазва низходящия ред.
$result_years = array_values(array_unique(array_map(
    fn($r) => substr($r['event_date'], 0, 4),
    $combined_results
)));
$default_year = $result_years[0] ?? null;

// Избрани стартове от URL-а (?races=id1,id2,...) — bookmarkable/споделим
// линк, същия принцип като lactate_analysis.php's ?compare=. Невалидни
// row_id-та (изтрит резултат) просто отпадат мълчаливо по-долу.
$selected_ids = [];
if (isset($_GET['races']) && $_GET['races'] !== '') {
    $selected_ids = array_filter(array_map('trim', explode(',', $_GET['races'])));
}

$selected_results = array_values(array_filter($combined_results, function ($r) use ($selected_ids) {
    return in_array($r['row_id'], $selected_ids, true);
}));
usort($selected_results, function ($a, $b) { return strcmp($a['event_date'], $b['event_date']); });

function rc_cell($value) {
    return ($value !== null && $value !== '') ? htmlspecialchars($value) : '—';
}
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
        .hint { color: var(--muted); font-size: 12.5px; margin: 0 0 10px; }
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
        #race-list tbody tr.selected td { background: #eef1fb; }
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

    <div class="table-card">
        <h2>Всички стартове</h2>
        <?php if ($combined_results): ?>
        <p class="hint">Изберете един или повече старта, за да сравните сплитовете им.</p>
        <nav class="year-nav" aria-label="Филтър по година">
            <?php foreach ($result_years as $year): ?>
                <button type="button" data-year="<?= htmlspecialchars($year) ?>"
                        class="<?= $year === $default_year ? 'active' : '' ?>"><?= htmlspecialchars($year) ?></button>
            <?php endforeach; ?>
        </nav>
        <table id="race-list">
            <thead>
                <tr>
                    <th class="check-col"></th>
                    <th>Дата</th><th>Състезание</th><th>Източник</th><th>Общо</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($combined_results as $r):
                    $row_year = substr($r['event_date'], 0, 4);
                ?>
                <tr data-row-id="<?= htmlspecialchars($r['row_id']) ?>"
                    data-year="<?= htmlspecialchars($row_year) ?>"
                    data-date="<?= htmlspecialchars($r['event_date']) ?>"
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

    <div class="table-card" id="compare-card" style="<?= $selected_results ? '' : 'display:none;' ?>">
        <h2>Сравнение</h2>
        <div class="cmp-wrap">
            <table class="cmp-table" id="compare-table">
                <thead><tr id="compare-head"><th>Показател</th>
                    <?php foreach ($selected_results as $r): ?><th><?= htmlspecialchars($r['event_date']) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody id="compare-body">
                    <?php
                    $metrics = [
                        ['event_name', 'Състезание'], ['source', 'Източник'], ['swim', 'Плуване'],
                        ['t1', 'T1'], ['bike', 'Колело'], ['t2', 'T2'], ['run', 'Бягане'], ['total_time', 'Общо'],
                    ];
                    foreach ($metrics as [$key, $label]):
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($label) ?></td>
                        <?php foreach ($selected_results as $r): ?>
                        <td><?= $key === 'source' ? htmlspecialchars($r['source'] === 'wt' ? 'Официално' : 'Местно') : rc_cell($r[$key]) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="empty" id="compare-empty" style="<?= $selected_results ? 'display:none;' : '' ?>">Няма избрани стартове за сравнение.</p>

    <script>
    (function () {
        const checkboxes = document.querySelectorAll('#race-list .checkbox');
        const compareCard = document.getElementById('compare-card');
        const emptyMsg = document.getElementById('compare-empty');
        const head = document.getElementById('compare-head');
        const body = document.getElementById('compare-body');

        const METRICS = [
            ['name', 'Състезание'], ['source', 'Източник'], ['swim', 'Плуване'],
            ['t1', 'T1'], ['bike', 'Колело'], ['t2', 'T2'], ['run', 'Бягане'], ['total', 'Общо'],
        ];

        function cell(value) {
            return value && value !== '' ? value : '—';
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

                head.innerHTML = '<th>Показател</th>' +
                    rows.map(r => `<th>${r.dataset.date}</th>`).join('');

                body.innerHTML = METRICS.map(([key, label]) => {
                    const cells = rows.map(r => `<td>${cell(r.dataset[key])}</td>`).join('');
                    return `<tr><td>${label}</td>${cells}</tr>`;
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

        // Филтър по година — същия патърн като .year-nav в athlete.php
        // ("Резултати по година"/"Местни състезания"): смяната на година
        // скрива редовете от другите години И изчиства избора за сравнение,
        // за да не остане "невидим" избран старт от скрита година.
        const nav = document.querySelector('.year-nav');
        if (nav) {
            const yearButtons = nav.querySelectorAll('button');
            const rows = document.querySelectorAll('#race-list tbody tr');
            nav.addEventListener('click', function (ev) {
                const btn = ev.target.closest('button');
                if (!btn) return;
                const year = btn.dataset.year;
                yearButtons.forEach(b => b.classList.toggle('active', b === btn));
                rows.forEach(r => { r.style.display = r.dataset.year === year ? '' : 'none'; });
                checkboxes.forEach(cb => { cb.checked = false; });
                render();
            });
        }
    }());
    </script>
</body>
</html>
