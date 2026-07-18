<?php
// Централизиран речник на метриките в дашборда.
// Нова метрика се добавя само тук — легендите в athlete.php и dashboard.php
// итерират през масивите и я показват автоматично.

// Показвано име на всеки термин (ключовете съвпадат с $METRICS_GLOSSARY)
$METRIC_LABELS = [
    'acwr'             => 'ACWR',
    'ctl'              => 'CTL (Fitness)',
    'atl'              => 'ATL (Fatigue)',
    'hrv'              => 'HRV',
    'sleep'            => 'Сън',
    'stress'           => 'Стрес',
    'resting_hr'       => 'Пулс в покой',
    'world_ranking'    => 'World Ranking',
    'regional_ranking' => 'Regional Ranking',
];

$METRICS_GLOSSARY = [
    'acwr'             => 'Съотношение остро/хронично натоварване. Показва дали текущият обем е рязко по-висок или по-нисък от обичайния — индикатор за риск от травма или детрениране. Оптималният диапазон е 0.8–1.3.',
    'ctl'              => 'Хронично тренировъчно натоварване (Fitness) — дългосрочна база на формата, изчислена от последните ~42 дни тренировки.',
    'atl'              => 'Остро тренировъчно натоварване (Fatigue) — краткосрочна умора, изчислена от последните ~7 дни тренировки.',
    'hrv'              => 'Вариабилност на сърдечния ритъм — индикатор за възстановяване и готовност на нервната система. По-високи стойности обикновено означават по-добро възстановяване.',
    'sleep'            => 'Часове сън на нощ.',
    'stress'           => 'Ниво на стрес, докладвано от устройството на атлета.',
    'resting_hr'       => 'Пулс в покой, обикновено измерен сутрин. Трайно повишение може да е знак за умора или заболяване.',
    'world_ranking'    => 'Позиция в световния ранкинг на World Triathlon (по-ниско число = по-добра позиция).',
    'regional_ranking' => 'Позиция в европейския/регионален ранкинг на World Triathlon.',
];

// Сгъваема легенда (по подразбиране затворена). Самостоятелна откъм стилове,
// за да изглежда еднакво на всички страници, без да пипаме техния CSS.
function render_metrics_legend() {
    global $METRIC_LABELS, $METRICS_GLOSSARY;
    ?>
    <details style="margin-top:20px;background:#ffffff;border-radius:8px;padding:14px 18px;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <summary style="cursor:pointer;font-size:15px;font-weight:600;color:#52514e;">Речник на метриките</summary>
        <dl style="margin:10px 0 0;font-size:13px;">
            <?php foreach ($METRICS_GLOSSARY as $key => $text): ?>
                <dt style="font-weight:600;margin-top:8px;"><?= htmlspecialchars($METRIC_LABELS[$key] ?? $key) ?></dt>
                <dd style="margin:2px 0 0;color:#52514e;"><?= htmlspecialchars($text) ?></dd>
            <?php endforeach; ?>
        </dl>
    </details>
    <?php
}
