<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once 'includes/auth.php';
require_login();

// Само за back-линка — самите тестови данни идват от api_lactate.php през fetch().
// athlete_id не се пази в lactate_tests (Sheet-ът не познава intervals.icu ID),
// затова athlete.php го подава директно в линка към тази страница.
$athlete_id = isset($_GET['athlete_id']) ? $_GET['athlete_id'] : '';
$test_id = isset($_GET['test_id']) ? $_GET['test_id'] : '';
$compare = isset($_GET['compare']) ? $_GET['compare'] : '';
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
        .subheader { color: var(--ink-2); font-size: 14px; margin-bottom: 14px; }
        .compare-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 20px; }
        .compare-bar label { font-size: 13px; color: var(--ink-2); font-weight: 600; }
        .compare-bar select {
            font: inherit; font-size: 13px; padding: 5px 8px; border-radius: 6px;
            border: 1px solid var(--grid); background: var(--surface); color: var(--ink);
            max-width: 220px;
        }
        .compare-bar select:disabled { color: var(--muted); background: #f4f4f4; }
        .chart-card, .table-card { background: var(--surface); border-radius: 8px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .chart-wrap { position: relative; height: 420px; }
        .table-card { margin-top: 20px; overflow-x: auto; }
        .table-card h2 { margin: 0 0 10px; font-size: 16px; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; }
        #zonesTable { max-width: 480px; }
        th { text-align: left; color: var(--ink-2); font-weight: 600; padding: 6px 14px 6px 0; border-bottom: 1px solid var(--grid); white-space: nowrap; }
        td { padding: 6px 14px 6px 0; border-bottom: 1px solid #f0efec; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .zone-swatch { display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-right: 8px; vertical-align: middle; }
        .empty { color: var(--muted); font-style: italic; font-size: 14px; }
        .lt-note { font-size: 11px; color: var(--muted); }
        .lt-summary {
            text-align: center; font-size: 20px; font-weight: 700;
            margin: 14px 0; color: var(--ink);
        }
        .lt-summary .lt1-value { color: #2e7d32; }
        .lt-summary .lt2-value { color: #c62828; }
        .lt-summary .lt-est-suffix { font-size: 13px; font-weight: 400; font-style: italic; color: var(--muted); }
        .lt-summary .lt-sep { color: var(--grid); margin: 0 10px; font-weight: 400; }
        #compareTable td.delta-good { color: #2e7d32; font-weight: 700; }
        #compareTable td.delta-bad { color: #c62828; font-weight: 700; }
        #compareTable td.delta-neutral { color: var(--ink-2); font-weight: 600; }
        #compareTable th.col-delta { color: var(--ink); }
        @media (max-width: 480px) {
            body { padding: 12px; }
            .chart-wrap { height: 300px; }
            .lt-summary { font-size: 16px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1 id="pageTitle">Зареждане…</h1>
        <a id="backLink" href="dashboard.php">&larr; Назад към профила</a>
    </div>
    <div class="subheader" id="metaLine" style="display:none;"></div>

    <div class="compare-bar" id="compareBar" style="display:none;">
        <label for="compareSelect1">Сравни с:</label>
        <select id="compareSelect1"><option value="">— няма —</option></select>
        <select id="compareSelect2"><option value="">— няма —</option></select>
    </div>

    <p class="empty" id="errorBox" style="display:none;"></p>

    <div class="chart-card" id="chartCard" style="display:none;">
        <div class="chart-wrap"><canvas id="chartLactate"></canvas></div>
    </div>

    <p class="lt-summary" id="ltSummary" style="display:none;"></p>

    <div class="table-card" id="zonesCard" style="display:none;">
        <h2>Зони</h2>
        <table id="zonesTable">
            <thead><tr><th>Зона</th><th>Диапазон</th></tr></thead>
            <tbody></tbody>
        </table>
        <p class="lt-note" id="ltNote" style="display:none;"></p>
    </div>

    <div class="table-card" id="compareCard" style="display:none;">
        <h2>Сравнителна таблица</h2>
        <table id="compareTable">
            <thead><tr id="compareThead"></tr></thead>
            <tbody></tbody>
        </table>
    </div>

    <script>
    (function () {
        // Стилове за наслагваните криви — по-светли нюанси на основните
        // сини/червени, с нарастващ dash pattern, за да останат различими
        // без да засенчват основния тест.
        const COMPARE_STYLES = [
            { hr: '#8fb8e8', la: '#e08f8f', dash: [6, 4] },
            { hr: '#c3d9f2', la: '#f0c4c4', dash: [2, 3] }
        ];
        const MAX_COMPARE = 2;

        const testId = <?= json_encode($test_id) ?>;
        const athleteId = <?= json_encode($athlete_id) ?>;
        let compareIds = <?= json_encode($compare) ?>
            .split(',').map(function (s) { return s.trim(); }).filter(Boolean).slice(0, MAX_COMPARE);

        let mainData = null;
        const testCache = {};   // test_id -> api response, за да не рефетчваме при re-toggle
        let chartInstance = null;

        const select1 = document.getElementById('compareSelect1');
        const select2 = document.getElementById('compareSelect2');

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

        async function fetchTest(id) {
            if (testCache[id]) return testCache[id];
            const res = await fetch('api_lactate.php?test_id=' + encodeURIComponent(id));
            if (res.status === 401) {
                location.href = 'index.php';
                throw new Error('unauthorized');
            }
            const body = await res.json();
            if (!res.ok) throw new Error(body.error || ('HTTP ' + res.status));
            testCache[id] = body;
            return body;
        }

        async function fetchList(athleteName) {
            const res = await fetch('api_lactate.php?list=1&athlete=' + encodeURIComponent(athleteName));
            if (res.status === 401) {
                location.href = 'index.php';
                throw new Error('unauthorized');
            }
            const body = await res.json();
            if (!res.ok) throw new Error(body.error || ('HTTP ' + res.status));
            return body.tests || [];
        }

        (async function init() {
            try {
                mainData = await fetchTest(testId);
            } catch (err) {
                if (err.message !== 'unauthorized') showError('Неуспешно зареждане: ' + err.message);
                return;
            }

            renderHeader(mainData);

            let athleteTests = [];
            try {
                athleteTests = await fetchList(mainData.athlete_name);
            } catch (err) {
                // Списъкът с тестове за сравнение е второстепенен — основната
                // графика вече е готова да се рендерира дори той да гръмне.
                athleteTests = [];
            }
            const otherTests = athleteTests.filter(function (t) { return String(t.test_id) !== String(testId); });

            if (otherTests.length) {
                document.getElementById('compareBar').style.display = '';
                populateSelect(select1, otherTests);
                populateSelect(select2, otherTests);
                select1.value = compareIds[0] || '';
                select2.value = compareIds[1] || '';
                syncSelectDisabling();
                select1.addEventListener('change', onCompareChange);
                select2.addEventListener('change', onCompareChange);
            } else {
                compareIds = [];
            }

            // Заредени compare test_id-та от URL-а (bookmark/споделен линк) —
            // невалидни/изтрити ID-та просто отпадат мълчаливо.
            if (compareIds.length) {
                await loadCompareTests();
            }

            renderAll();
        }());

        function populateSelect(select, tests) {
            tests.forEach(function (t) {
                const opt = document.createElement('option');
                opt.value = t.test_id;
                opt.textContent = t.test_date + (t.ftp !== null ? ' (FTP ' + Math.round(t.ftp) + 'W)' : '');
                select.appendChild(opt);
            });
        }

        // Не позволява избор на един и същ тест в двата select-а.
        function syncSelectDisabling() {
            [select1, select2].forEach(function (sel, idx) {
                const other = idx === 0 ? select2 : select1;
                Array.prototype.forEach.call(sel.options, function (opt) {
                    opt.disabled = opt.value !== '' && opt.value === other.value;
                });
            });
        }

        async function loadCompareTests() {
            const loaded = [];
            for (const id of compareIds) {
                try {
                    await fetchTest(id);
                    loaded.push(id);
                } catch (err) {
                    // невалиден/изтрит test_id от URL-а — прескачаме тихо
                }
            }
            compareIds = loaded;
        }

        async function onCompareChange() {
            const ids = [select1.value, select2.value].filter(Boolean);
            compareIds = Array.from(new Set(ids)).slice(0, MAX_COMPARE);
            syncSelectDisabling();
            try {
                await loadCompareTests();
            } catch (err) {
                showError('Неуспешно зареждане на сравнение: ' + err.message);
                return;
            }
            renderAll();
        }

        function renderAll() {
            buildChart(mainData, compareIds.map(function (id) { return testCache[id]; }));
            const allShown = [mainData].concat(compareIds.map(function (id) { return testCache[id]; }));
            if (compareIds.length > 0) {
                document.getElementById('compareCard').style.display = '';
                buildCompareTable(allShown);
            } else {
                document.getElementById('compareCard').style.display = 'none';
            }
            updateUrl();
        }

        function updateUrl() {
            const params = new URLSearchParams(location.search);
            if (compareIds.length) {
                params.set('compare', compareIds.join(','));
            } else {
                params.delete('compare');
            }
            const qs = params.toString();
            history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
        }

        function renderHeader(data) {
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
            renderLtSummary(data);

            if (data.zones && data.zones.length) {
                document.getElementById('zonesCard').style.display = '';
                buildZonesTable(data);
            }
        }

        // Едро текстово резюме на LT1/LT2 под графиката — "(est.)" само когато
        // стойността е интерполирана, не въведена ръчно в Sheet-а.
        function renderLtSummary(data) {
            const el = document.getElementById('ltSummary');
            if (data.lt1_w === null && data.lt2_w === null) {
                el.style.display = 'none';
                return;
            }
            const parts = [];
            if (data.lt1_w !== null) {
                parts.push('<span class="lt1-value">LT1: ' + Math.round(data.lt1_w) + 'W</span>' +
                    (data.lt1_estimated ? ' <span class="lt-est-suffix">(est.)</span>' : ''));
            }
            if (data.lt2_w !== null) {
                parts.push('<span class="lt2-value">LT2: ' + Math.round(data.lt2_w) + 'W</span>' +
                    (data.lt2_estimated ? ' <span class="lt-est-suffix">(est.)</span>' : ''));
            }
            el.innerHTML = parts.join('<span class="lt-sep">|</span>');
            el.style.display = '';
        }

        function makeSeries(test, field, color, dash, label) {
            const points = test.steps
                .filter(function (s) { return s[field] !== null; })
                .map(function (s) { return { x: s.watts, y: s[field] }; });
            return {
                label: label,
                yAxisID: field === 'hr' ? 'yHr' : 'yLa',
                data: points,
                borderColor: color, backgroundColor: color,
                borderWidth: dash ? 2 : 2.5,
                borderDash: dash || [],
                pointRadius: dash ? 2 : 3,
                pointHoverRadius: 5,
                tension: 0.2, spanGaps: false
            };
        }

        function buildChart(main, compares) {
            Chart.register(window['chartjs-plugin-annotation']);
            if (chartInstance) {
                chartInstance.destroy();
                chartInstance = null;
            }

            const datasets = [
                makeSeries(main, 'hr', '#2a78d6', null, 'Пулс (' + main.test_date + ')'),
                makeSeries(main, 'lactate', '#c62828', null, 'Лактат (' + main.test_date + ')')
            ];
            compares.forEach(function (cmp, i) {
                if (!cmp) return;
                const style = COMPARE_STYLES[i] || COMPARE_STYLES[COMPARE_STYLES.length - 1];
                datasets.push(makeSeries(cmp, 'hr', style.hr, style.dash, 'Пулс (' + cmp.test_date + ')'));
                datasets.push(makeSeries(cmp, 'lactate', style.la, style.dash, 'Лактат (' + cmp.test_date + ')'));
            });

            // Зоните и LT линиите са само за основния тест — с повече от един
            // тестов праг графиката бързо става нечетима.
            const annotations = {};
            (main.zones || []).forEach(function (z, i) {
                annotations['zone' + i] = {
                    type: 'box',
                    xMin: z.from_w,
                    xMax: z.to_w === null ? undefined : z.to_w,
                    backgroundColor: z.color,
                    borderWidth: 0,
                    drawTime: 'beforeDatasetsDraw'
                };
            });
            if (main.lt1_w !== null) {
                annotations['lt1'] = ltLineAnnotation(main.lt1_w, '#2e7d32', 'LT1 ' + Math.round(main.lt1_w) + 'W' + (main.lt1_estimated ? ' (est.)' : ''), 'start');
            }
            if (main.lt2_w !== null) {
                annotations['lt2'] = ltLineAnnotation(main.lt2_w, '#c62828', 'LT2 ' + Math.round(main.lt2_w) + 'W' + (main.lt2_estimated ? ' (est.)' : ''), 'end');
            }

            chartInstance = new Chart(document.getElementById('chartLactate'), {
                type: 'line',
                data: { datasets: datasets },
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
                        legend: { display: true, labels: { usePointStyle: true, pointStyle: 'line', boxWidth: 24 } },
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
            const note = document.getElementById('ltNote');
            if (data.lt1_estimated || data.lt2_estimated) {
                note.textContent = '(est.) = LT прагът не е въведен ръчно — изчислен чрез линейна интерполация при пресичане на 2.0/4.0 mmol.';
                note.style.display = '';
            } else {
                note.style.display = 'none';
            }
        }

        // Линейна интерполация на HR (или друго поле) по мощност — за
        // "Пулс при LT2" в сравнителната таблица. Извън обхвата на
        // измерените стъпки просто взима крайната стойност (без extrapolate).
        function interpolateAtWatts(steps, field, targetWatts) {
            if (targetWatts === null) return null;
            const pts = steps
                .filter(function (s) { return s[field] !== null && s.watts !== null; })
                .sort(function (a, b) { return a.watts - b.watts; });
            if (!pts.length) return null;
            if (targetWatts <= pts[0].watts) return pts[0][field];
            if (targetWatts >= pts[pts.length - 1].watts) return pts[pts.length - 1][field];
            for (let i = 1; i < pts.length; i++) {
                const a = pts[i - 1], b = pts[i];
                if (a.watts <= targetWatts && targetWatts <= b.watts) {
                    const ratio = b.watts === a.watts ? 0 : (targetWatts - a.watts) / (b.watts - a.watts);
                    return a[field] + ratio * (b[field] - a[field]);
                }
            }
            return null;
        }

        function maxOf(steps, field) {
            const vals = steps.map(function (s) { return s[field]; }).filter(function (v) { return v !== null; });
            return vals.length ? Math.max.apply(null, vals) : null;
        }

        // higherBetter: true = по-високо е подобрение, false = по-ниско е
        // подобрение, null = без цветова преценка (посоката не е еднозначна).
        const COMPARE_ROWS = [
            { label: 'LT1 (W)', higherBetter: true, get: function (t) { return t.lt1_w; }, fmt: function (v) { return v === null ? '—' : Math.round(v) + 'W'; } },
            { label: 'LT2 (W)', higherBetter: true, get: function (t) { return t.lt2_w; }, fmt: function (v) { return v === null ? '—' : Math.round(v) + 'W'; } },
            { label: 'FTP', higherBetter: true, get: function (t) { return t.ftp; }, fmt: function (v) { return v === null ? '—' : Math.round(v) + 'W'; } },
            { label: 'W/kg', higherBetter: true, get: function (t) { return t.w_kg; }, fmt: function (v) { return v === null ? '—' : v.toFixed(2); } },
            { label: 'Пулс при LT2', higherBetter: false, get: function (t) { return interpolateAtWatts(t.steps, 'hr', t.lt2_w); }, fmt: function (v) { return v === null ? '—' : Math.round(v) + ' bpm'; } },
            { label: 'Макс лактат', higherBetter: null, get: function (t) { return maxOf(t.steps, 'lactate'); }, fmt: function (v) { return v === null ? '—' : v.toFixed(1) + ' mmol'; } }
        ];

        function buildCompareTable(tests) {
            const sorted = tests.slice().sort(function (a, b) { return a.test_date < b.test_date ? -1 : (a.test_date > b.test_date ? 1 : 0); });

            const thead = document.getElementById('compareThead');
            thead.innerHTML = '<th></th>' +
                sorted.map(function (t) { return '<th>' + t.test_date + '</th>'; }).join('') +
                '<th class="col-delta">Δ</th>';

            const tbody = document.querySelector('#compareTable tbody');
            tbody.innerHTML = '';

            const oldest = sorted[0];
            const newest = sorted[sorted.length - 1];

            COMPARE_ROWS.forEach(function (row) {
                const tr = document.createElement('tr');
                let html = '<td>' + row.label + '</td>';
                sorted.forEach(function (t) {
                    html += '<td>' + row.fmt(row.get(t)) + '</td>';
                });

                const oldVal = row.get(oldest);
                const newVal = row.get(newest);
                if (oldest === newest || oldVal === null || newVal === null) {
                    html += '<td class="delta-neutral">—</td>';
                } else {
                    const delta = newVal - oldVal;
                    let cls = 'delta-neutral';
                    let deltaText = row.fmt(Math.abs(delta));
                    if (delta === 0) {
                        deltaText = '±' + deltaText;
                    } else {
                        deltaText = (delta > 0 ? '+' : '−') + deltaText;
                        if (row.higherBetter !== null) {
                            const improved = row.higherBetter ? delta > 0 : delta < 0;
                            cls = improved ? 'delta-good' : 'delta-bad';
                        }
                    }
                    html += '<td class="' + cls + '">' + deltaText + '</td>';
                }
                tr.innerHTML = html;
                tbody.appendChild(tr);
            });
        }
    }());
    </script>
</body>
</html>
