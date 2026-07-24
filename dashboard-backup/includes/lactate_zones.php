<?php
// Изчисления, споделени между athlete.php, api_lactate.php и lactate_analysis.php —
// единен източник за мощност по стъпка, LT интерполация и зоновия модел, за да не
// се разминат при бъдещи фази (сравнение между тестове, cross-athlete анализ).

// Мощност по стъпка от протокола: М започва от 80W с +40W на стъпка,
// Ж от 60W с +30W — стандартните инкременти за лактатните тестове в клуба.
// Непознат протокол -> null.
function lactate_step_watts($protocol, $step) {
    $protocol = strtoupper(trim((string)$protocol));
    if ($protocol === 'М' || $protocol === 'M') {
        return 80 + 40 * ($step - 1);
    }
    if ($protocol === 'Ж' || $protocol === 'W' || $protocol === 'F') {
        return 60 + 30 * ($step - 1);
    }
    return null;
}

// Линейна интерполация на мощността, при която лактатната крива пресича $threshold
// mmol, между най-близката двойка стъпки от двете страни на прага. $points е масив
// от ['watts' => W, 'la' => mmol|null], в реда на стъпките (нарастващ watts).
// Връща null, ако кривата никога не достига прага (напр. тест спрян рано).
function lactate_interpolate_threshold($points, $threshold) {
    $prev = null;
    foreach ($points as $p) {
        if ($p['la'] === null || $p['watts'] === null) {
            continue;
        }
        if ($prev !== null && $prev['la'] <= $threshold && $p['la'] >= $threshold && $p['la'] != $prev['la']) {
            $ratio = ($threshold - $prev['la']) / ($p['la'] - $prev['la']);
            return $prev['watts'] + $ratio * ($p['watts'] - $prev['watts']);
        }
        $prev = $p;
    }
    return null;
}

// 5-зонов модел от LT1/LT2 (в watts). Връща [] ако някой от прагът липсва
// (нито в базата, нито изчислим чрез интерполация).
function compute_zones($lt1_w, $lt2_w) {
    if ($lt1_w === null || $lt2_w === null) {
        return [];
    }
    return [
        ['name' => 'Z1 Recovery',  'from_w' => 0.0,            'to_w' => $lt1_w * 0.85, 'color' => 'rgba(76, 175, 80, 0.10)'],
        ['name' => 'Z2 Endurance', 'from_w' => $lt1_w * 0.85,  'to_w' => $lt1_w,        'color' => 'rgba(76, 175, 80, 0.22)'],
        ['name' => 'Z3 Tempo',     'from_w' => $lt1_w,         'to_w' => $lt2_w * 0.95, 'color' => 'rgba(255, 213, 79, 0.25)'],
        ['name' => 'Z4 Threshold', 'from_w' => $lt2_w * 0.95,  'to_w' => $lt2_w * 1.05, 'color' => 'rgba(255, 152, 0, 0.28)'],
        ['name' => 'Z5 VO2max+',   'from_w' => $lt2_w * 1.05,  'to_w' => null,          'color' => 'rgba(198, 40, 40, 0.22)'],
    ];
}
