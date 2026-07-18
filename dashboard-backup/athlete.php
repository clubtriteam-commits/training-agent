<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/metrics_glossary.php';
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
    SELECT date, alert_type, message, sent_at
    FROM alerts_log
    WHERE athlete_id = ?
    ORDER BY date DESC, sent_at DESC
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
        SELECT event_date, event_title, position, total_time, event_country
        FROM world_triathlon_results
        WHERE athlete_name = ? AND event_date IS NOT NULL
        ORDER BY event_date DESC
    ");
    $stmt->execute([$athlete_name]);
    $race_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $race_results = [];
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
    'acwr_high'       => 'Висок ACWR',
    'acwr_low'        => 'Нисък ACWR',
    'comment_keyword' => 'Оплакване в коментар',
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
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .tile { background: var(--surface); border-radius: 8px; padding: 14px 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .tile .label { font-size: 13px; color: var(--ink-2); }
        .tile .value { font-size: 26px; font-weight: 600; margin-top: 2px; }
        .charts { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .chart-card { background: var(--surface); border-radius: 8px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .chart-card h2 { margin: 0 0 4px; font-size: 16px; color: var(--ink); }
        .chart-card .hint { font-size: 12px; color: var(--muted); margin: 0 0 10px; }
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
                        <td><?= htmlspecialchars($alert['date']) ?></td>
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
                <?php foreach ($race_results as $r): ?>
                <tr data-year="<?= htmlspecialchars(substr($r['event_date'], 0, 4)) ?>"
                    <?= substr($r['event_date'], 0, 4) !== $default_year ? 'style="display:none;"' : '' ?>>
                    <td><?= htmlspecialchars($r['event_date']) ?></td>
                    <td class="msg"><?= htmlspecialchars($r['event_title'] ?? '—') ?></td>
                    <td><?= $r['position'] !== null && $r['position'] !== '' ? htmlspecialchars($r['position']) : '—' ?></td>
                    <td><?= $r['total_time'] !== null && $r['total_time'] !== '' ? htmlspecialchars($r['total_time']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty">Няма резултати от състезания</p>
        <?php endif; ?>
    </div>

    <?php render_metrics_legend(); ?>

    <script>
    const DATA = <?= json_encode($chart_data, JSON_UNESCAPED_UNICODE) ?>;

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

    // Филтър по година за "Резултати по година" — изцяло клиентски,
    // без презареждане: показва само редовете с избраната година.
    (function () {
        const nav = document.querySelector('.year-nav');
        if (!nav) return;
        const buttons = nav.querySelectorAll('button');
        const rows = document.querySelectorAll('#results-table tbody tr');
        nav.addEventListener('click', function (ev) {
            const btn = ev.target.closest('button');
            if (!btn) return;
            const year = btn.dataset.year;
            buttons.forEach(b => b.classList.toggle('active', b === btn));
            rows.forEach(r => { r.style.display = r.dataset.year === year ? '' : 'none'; });
        });
    }());
    </script>
</body>
</html>
