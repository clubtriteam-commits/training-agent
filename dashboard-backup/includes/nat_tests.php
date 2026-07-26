<?php
// Споделена логика за националните функционални тестове (НЦ) — единен
// източник за правилото "кога две стойности изобщо са сравними", за да не
// се разминат отделните графики/таблици в athlete.php.
//
// Причината правилото да съществува изобщо: тредбанд и вело дават различен
// VO2max при СЪЩИЯ атлет (типично 5-10% по-висок при бягане — по-висока
// метаболитна цена на наклона), а клубният вело протокол (40W стъпки при
// мъже) не е същият като НЦ вело протокола (30W стъпки) — по-стръмна рампа
// дава по-висок W_max при същото ниво на форма. Без това правило графика,
// която просто нарежда стойностите по дата, показва фалшив спад/скок,
// когато всъщност е смяна на протокола, не промяна в атлета.

// Сравними са само тестове с ИДЕНТИЧЕН protocol. Нарочно строго (никакво
// "подобни" протоколи) — точно затова стойностите изобщо стават сравними.
function nat_tests_comparable($protocol_a, $protocol_b) {
    return $protocol_a !== null && $protocol_a === $protocol_b;
}

// Групира тестове по protocol — основата на "всяка графика минава през
// нея": вместо една серия по дата (която би свързала несравними протоколи
// с права линия), връщаме отделен списък тестове на protocol, за да може
// извикващият код да построи отделна графична серия за всеки.
function nat_tests_group_by_protocol($tests) {
    $groups = [];
    foreach ($tests as $t) {
        $groups[$t['protocol']][] = $t;
    }
    return $groups;
}

// Четимо описание на протокол за показване под графиката —
// директно от nat_test_protocols, без интерпретация/съкращаване на
// стойностите (за да не се разминат с това, което лабораторията е записала).
function nat_protocol_description($protocol_row) {
    if (!$protocol_row) return null;
    $parts = [];
    if (!empty($protocol_row['device'])) $parts[] = $protocol_row['device'];
    if (!empty($protocol_row['start_value'])) $parts[] = 'старт ' . $protocol_row['start_value'];
    if (!empty($protocol_row['increment']) && !empty($protocol_row['step_minutes'])) {
        $parts[] = 'стъпка ' . $protocol_row['increment'] . ' на ' . rtrim(rtrim(number_format((float)$protocol_row['step_minutes'], 1), '0'), '.') . ' мин';
    }
    if (!empty($protocol_row['incline']) && $protocol_row['incline'] !== '—') {
        $parts[] = 'наклон ' . $protocol_row['incline'];
    }
    return $parts ? implode(', ', $parts) : null;
}

// Основната метрика на теста зависи от протокола (вело -> W, тредбанд -> км/ч) —
// nat_test_protocols.metric казва кое поле е смислено за показване първо.
function nat_primary_metric($test, $protocol_row) {
    $metric = $protocol_row['metric'] ?? null;
    if ($metric === 'W') {
        if ($test['w_max'] === null) return '—';
        $out = round($test['w_max']) . 'W';
        if ($test['w_max_kg'] !== null) {
            $out .= ' (' . number_format((float)$test['w_max_kg'], 2) . ' W/kg)';
        }
        return $out;
    }
    if ($metric === 'км/ч' || $metric === 'km/h') {
        return $test['s_max_kmh'] !== null ? number_format((float)$test['s_max_kmh'], 1) . ' км/ч' : '—';
    }
    return '—';
}

// ---------------------------------------------------------------------
// Delta (сравнителна таблица) и radar нормализация за redesign-натата
// секция. Отделени тук (не inline в athlete.php), защото и таблицата,
// и trend мини-картата смятат delta-та за едни и същи полета — един
// източник на истина за "накъде сочи стрелката и в какъв цвят".

// Единствената точка, в която се решава посоката на "подобрение" per
// метрика. Нарочно extraible: ЕПЗ не влиза тук изобщо (range, не
// скалар — таблицата винаги показва "—" за него, виж athlete.php).
// 'higher_better'  -> по-голяма стойност = зелено/▲
// 'lower_better'   -> по-малка стойност = зелено/▲ (обратно оцветяване)
// 'neutral'        -> реалната стрелка/diff се показват, но винаги в сиво
$NAT_METRIC_DIRECTION = [
    'w_max'       => 'higher_better',
    'w_max_kg'    => 'higher_better',
    's_max_kmh'   => 'higher_better',
    'vo2max'      => 'higher_better',
    'vo2max_kg'   => 'higher_better',
    'hr_max'      => 'higher_better',
    'la_2'        => 'higher_better',
    'la_6'        => 'higher_better',
    'la_15'       => 'higher_better',
    'muscle_pct'  => 'higher_better',
    'lean_mass_kg' => 'higher_better',
    'weight_kg'   => 'neutral',
    'height_cm'   => 'neutral',
    'arm_span_cm' => 'neutral',
    'duration_min' => 'neutral',
];

// $first/$last са суровите стойности на един и същ поле от два теста.
// Връща null-safe структура: sign (истинската посока на промяната,
// независимо от оценка) + color (up/down/flat за CSS клас, вкарва
// 'neutral' метриките винаги в 'flat' цвят) + diff (закръглен на
// $decimals — "плоско" се решава СЛЕД закръгляне, не с fuzzy epsilon,
// за да не скрие реални малки разлики като -0.19 W/kg на Миролюба).
// $protocol_a/$protocol_b — ако подадени, налагат nat_tests_comparable()
// преди да смятат каквото и да е (delta между различни протоколи е
// невалидна операция по дефиниция, виж коментара горе във файла).
function nat_delta_info($metric_key, $first, $last, $decimals = 1, $protocol_a = null, $protocol_b = null) {
    global $NAT_METRIC_DIRECTION;
    if ($protocol_a !== null || $protocol_b !== null) {
        if (!nat_tests_comparable($protocol_a, $protocol_b)) {
            return ['sign' => null, 'color' => 'flat', 'diff' => null];
        }
    }
    if ($first === null || $last === null) {
        return ['sign' => null, 'color' => 'flat', 'diff' => null];
    }
    $diff = round((float)$last - (float)$first, $decimals);
    if ($diff == 0.0) {
        return ['sign' => 'flat', 'color' => 'flat', 'diff' => 0.0];
    }
    $direction = $NAT_METRIC_DIRECTION[$metric_key] ?? 'higher_better';
    $sign = $diff > 0 ? 'up' : 'down';
    if ($direction === 'neutral') {
        $color = 'flat';
    } elseif ($direction === 'lower_better') {
        $color = $diff > 0 ? 'down' : 'up';
    } else {
        $color = $sign;
    }
    return ['sign' => $sign, 'color' => $color, 'diff' => $diff];
}

function nat_delta_arrow($sign) {
    if ($sign === 'up') return '▲';
    if ($sign === 'down') return '▼';
    if ($sign === 'flat') return '≈';
    return '—';
}

// Процентна delta (за trend мини-картата, "▲ 21%" стил) — отделно от
// nat_delta_info, защото процентът няма смисъл за метрики без естествена
// нула (напр. ЕПЗ) и не участва в цветовия клас на таблицата.
function nat_delta_pct($first, $last) {
    if ($first === null || $last === null || (float)$first == 0.0) return null;
    return ((float)$last - (float)$first) / (float)$first * 100;
}

// Personal best на дадено поле измежду ВСИЧКИ тестове на атлета в този
// протокол (не само последните два, сравнявани в radar overlay-я) —
// изисквано изрично: нормализацията е спрямо реалния максимум на ТОЗИ
// атлет, никога хардкоднат/презет от друг тест.
// $lower_is_better обръща посоката (напр. La 6' — по-ниска стойност =
// по-добро възстановяване, затова "best" е минимумът).
function nat_radar_best($tests, $field, $lower_is_better = false) {
    $best = null;
    $best_date = null;
    foreach ($tests as $t) {
        if (!isset($t[$field]) || $t[$field] === null) continue;
        $v = (float)$t[$field];
        if ($best === null || ($lower_is_better ? $v < $best : $v > $best)) {
            $best = $v;
            $best_date = $t['test_date'];
        }
    }
    if ($best === null) return null;
    return ['value' => $best, 'date' => $best_date];
}

// Стойност -> % от personal best (0-100+, ако best e бил надминат в тест
// извън сравняваната двойка). Никога не хардкодва 100 за "последния" тест —
// ако личният максимум е бил при по-стар тест, последният законно излиза
// под 100% на тази ос (виж бележката за La 6' на Мира в проверката).
function nat_radar_pct($value, $best, $lower_is_better = false) {
    if ($value === null || $best === null || (float)$best == 0.0) return null;
    if ($lower_is_better) {
        return (float)$best / (float)$value * 100;
    }
    return (float)$value / (float)$best * 100;
}
