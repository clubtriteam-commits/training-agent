<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once 'includes/auth.php';
require_login();

// Само за back-линка — самите тестови данни идват от api_lactate.php през fetch().
// athlete_id не се пази в lactate_tests (Sheet-ът не познава intervals.icu ID),
// затова athlete.php го подава директно в линка към тази страница.
$athlete_id = isset($_GET['athlete_id']) ? $_GET['athlete_id'] : '';
$test_id = isset($_GET['test_id']) ? $_GET['test_id'] : '';
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Лактатен анализ</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
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
        .subheader { color: var(--ink-2); font-size: 14px; margin-bottom: 20px; }
        .chart-card, .table-card { background: var(--surface); border-radius: 8px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .chart-wrap { position: relative; height: 420px; }
        .table-card { margin-top: 20px; overflow-x: auto; }
        .table-card h2 { margin: 0 0 10px; font-size: 16px; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; max-width: 480px; }
        th { text-align: left; color: var(--ink-2); font-weight: 600; padding: 6px 14px 6px 0; border-bottom: 1px solid var(--grid); }
        td { padding: 6px 14px 6px 0; border-bottom: 1px solid #f0efec; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .zone-swatch { display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-right: 8px; vertical-align: middle; }
        .empty { color: var(--muted); font-style: italic; font-size: 14px; }
        .lt-note { font-size: 11px; color: var(--muted); }
        @media (max-width: 480px) {
            body { padding: 12px; }
            .chart-wrap { height: 300px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1 id="pageTitle">Зареждане…</h1>
        <a id="backLink" href="dashboard.php">&larr; Назад към профила</a>
    </div>
    <div class="subheader" id="metaLine" style="display:none;"></div>

    <p class="empty" id="errorBox" style="display:none;"></p>

    <div class="chart-card" id="chartCard" style="display:none;">
        <div class="chart-wrap"><canvas id="chartLactate"></canvas></div>
    </div>

    <div class="table-card" id="zonesCard" style="display:none;">
        <h2>Зони</h2>
        <table id="zonesTable">
            <thead><tr><th>Зона</th><th>Диапазон</th></tr></thead>
            <tbody></tbody>
        </table>
        <p class="lt-note" id="ltNote" style="display:none;"></p>
    </div>

    <script>
    (function () {
        const params = new URLSearchParams(location.search);
        const testId = params.get('test_id') || <?= json_encode($test_id) ?>;
        const athleteId = params.get('athlete_id') || <?= json_encode($athlete_id) ?>;

        if (athleteId) {
            document.getElementById('backLink').href = 'athlete.php?id=' + encodeURIComponent(athleteId);
        }

        function showError(msg) {
            document.getElementById('pageTitle').textContent = 'Лактатен анализ';
            const box = document.getElementById('errorBox');
            box.textContent = msg;
            box.style.display = '';
        }

        if (!testId) {
            showError('Липсва test_id в адреса.');
            return;
        }

        fetch('api_lactate.php?test_id=' + encodeURIComponent(testId))
            .then(function (res) {
                if (res.status === 401) {
                    location.href = 'index.php';
                    return Promise.reject(new Error('unauthorized'));
                }
                return res.json().then(function (body) {
                    if (!res.ok) throw new Error(body.error || ('HTTP ' + res.status));
                    return body;
                });
            })
            .then(renderPage)
            .catch(function (err) {
                if (err.message !== 'unauthorized') {
                    showError('Неуспешно зареждане: ' + err.message);
                }
            });

        function renderPage(data) {
            document.title = data.athlete_name + ' — Лактатен анализ';
            document.getElementById('pageTitle').textContent = data.athlete_name + ' — ' + data.test_date;

            const metaParts = [];
            if (data.protocol) metaParts.push('Протокол: ' + data.protocol);
            if (data.ftp !== null) metaParts.push('FTP: ' + Math.round(data.ftp) + 'W');
            if (data.w_kg !== null) metaParts.push(data.w_kg.toFixed(2) + ' W/kg');
            if (data.weight !== null) metaParts.push(data.weight.toFixed(1) + ' кг');
            if (data.age !== null) metaParts.push(data.age + ' г.');
            const metaEl = document.getElementById('metaLine');
            metaEl.textContent = metaParts.join(' · ');
            metaEl.style.display = '';

            document.getElementById('chartCard').style.display = '';
            buildChart(data);

            if (data.zones && data.zones.length) {
                document.getElementById('zonesCard').style.display = '';
                buildZonesTable(data);
            }
        }

        function buildChart(data) {
            Chart.register(window['chartjs-plugin-annotation']);

            const hrPoints = data.steps.filter(function (s) { return s.hr !== null; })
                .map(function (s) { return { x: s.watts, y: s.hr }; });
            const laPoints = data.steps.filter(function (s) { return s.lactate !== null; })
                .map(function (s) { return { x: s.watts, y: s.lactate }; });

            const annotations = {};
            (data.zones || []).forEach(function (z, i) {
                annotations['zone' + i] = {
                    type: 'box',
                    xMin: z.from_w,
                    xMax: z.to_w === null ? undefined : z.to_w,
                    backgroundColor: z.color,
                    borderWidth: 0,
                    drawTime: 'beforeDatasetsDraw'
                };
            });
            if (data.lt1_w !== null) {
                annotations['lt1'] = ltLineAnnotation(data.lt1_w, '#2e7d32', 'LT1 ' + Math.round(data.lt1_w) + 'W' + (data.lt1_estimated ? ' (est.)' : ''), 'start');
            }
            if (data.lt2_w !== null) {
                annotations['lt2'] = ltLineAnnotation(data.lt2_w, '#c62828', 'LT2 ' + Math.round(data.lt2_w) + 'W' + (data.lt2_estimated ? ' (est.)' : ''), 'end');
            }

            new Chart(document.getElementById('chartLactate'), {
                type: 'line',
                data: {
                    datasets: [
                        {
                            label: 'Пулс (bpm)', yAxisID: 'yHr', data: hrPoints,
                            borderColor: '#2a78d6', backgroundColor: '#2a78d6',
                            borderWidth: 2, pointRadius: 3, pointHoverRadius: 5,
                            tension: 0.2, spanGaps: false
                        },
                        {
                            label: 'Лактат (mmol)', yAxisID: 'yLa', data: laPoints,
                            borderColor: '#c62828', backgroundColor: '#c62828',
                            borderWidth: 2, pointRadius: 3, pointHoverRadius: 5,
                            tension: 0.2, spanGaps: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: {
                            type: 'linear',
                            title: { display: true, text: 'Мощност (W)' },
                            grid: { color: '#e1e0d9' }
                        },
                        yHr: {
                            type: 'linear', position: 'left',
                            title: { display: true, text: 'Пулс (bpm)', color: '#2a78d6' },
                            grid: { color: '#e1e0d9' }
                        },
                        yLa: {
                            type: 'linear', position: 'right',
                            title: { display: true, text: 'Лактат (mmol)', color: '#c62828' },
                            grid: { drawOnChartArea: false },
                            suggestedMin: 0
                        }
                    },
                    plugins: {
                        legend: { display: true, labels: { usePointStyle: true, pointStyle: 'line' } },
                        annotation: { annotations: annotations }
                    }
                }
            });
        }

        function ltLineAnnotation(watts, color, label, position) {
            return {
                type: 'line',
                xMin: watts, xMax: watts,
                borderColor: color, borderWidth: 2, borderDash: [6, 4],
                label: {
                    display: true, content: label, position: position,
                    backgroundColor: color, color: '#ffffff',
                    font: { size: 11, weight: 'bold' }, padding: 4
                }
            };
        }

        function buildZonesTable(data) {
            const tbody = document.querySelector('#zonesTable tbody');
            tbody.innerHTML = '';
            data.zones.forEach(function (z) {
                const from = Math.round(z.from_w);
                const to = z.to_w === null ? '∞' : Math.round(z.to_w);
                const tr = document.createElement('tr');
                const swatchColor = z.color.replace(/[\d.]+\)$/, '1)');
                tr.innerHTML = '<td><span class="zone-swatch" style="background:' + swatchColor + '"></span>' + z.name + '</td>' +
                    '<td>' + from + '–' + to + 'W</td>';
                tbody.appendChild(tr);
            });
            if (data.lt1_estimated || data.lt2_estimated) {
                const note = document.getElementById('ltNote');
                note.textContent = '(est.) = LT прагът не е въведен ръчно — изчислен чрез линейна интерполация при пресичане на 2.0/4.0 mmol.';
                note.style.display = '';
            }
        }
    }());
    </script>
</body>
</html>
