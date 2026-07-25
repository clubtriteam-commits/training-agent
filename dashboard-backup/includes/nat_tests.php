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
