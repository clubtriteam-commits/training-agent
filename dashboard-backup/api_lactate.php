<?php
// JSON endpoint за детайлен лактатен тест — консумиран от lactate_analysis.php
// (и от бъдещи фази: сравнение между тестове, cross-athlete анализ).
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/lactate_zones.php';

header('Content-Type: application/json; charset=utf-8');

// Production (PHP 8.0) има serialize_precision=100 в php.ini — json_encode()
// печата всеки float с пълната IEEE754 опашка (напр. w_kg=3.9 излиза като
// 3.899999999999999911182...), докато локалният dev PHP (8.3) използва
// съвременния default (-1, "най-късото число, което се връща обратно
// точно същото"). round() НЕ оправя това сам по себе си — закръглен float
// пак си остава "мръсен" double под качулката; трябва изрично да върнем
// -1 за целия отговор.
ini_set('serialize_precision', -1);

// round() тук е за смислена точност на изчислените стойности (напр. зоновите
// прагове от умножения), не за да маха горния шум — това вече го прави
// serialize_precision по-горе.
function num_or_null($v, $decimals = 2) {
    return $v !== null ? round((float)$v, $decimals) : null;
}

// Не викаме require_login() тук нарочно — тя прави header('Location: index.php')
// при липсваща сесия, което fetch() просто следва мълчаливо и връща index.php-ѝ
// HTML вместо JSON. Auth.php вече е стартирал сесията отгоре; проверяваме я
// директно и връщаме 401 JSON, който JS-ът на страницата може да прихване.
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Списък-режим за Фаза 2 (сравнение между тестове): ?athlete=NAME&list=1 ->
// всички тестове на атлета, най-новите първи. Отделен клон, преди test_id
// логиката по-долу, защото няма смисъл от test_id в този режим.
if (isset($_GET['list']) && isset($_GET['athlete'])) {
    $athlete_name = trim((string)$_GET['athlete']);
    if ($athlete_name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'missing athlete'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT id, test_date, ftp, w_kg FROM lactate_tests WHERE athlete_name = ? ORDER BY test_date DESC');
    $stmt->execute([$athlete_name]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tests = array_map(function ($r) {
        return [
            'test_id'   => (int)$r['id'],
            'test_date' => $r['test_date'],
            'ftp'       => num_or_null($r['ftp']),
            'w_kg'      => num_or_null($r['w_kg']),
        ];
    }, $rows);
    echo json_encode(['tests' => $tests], JSON_UNESCAPED_UNICODE);
    exit;
}

$test_id = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;
if ($test_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'missing or invalid test_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = get_db_connection();
$stmt = $pdo->prepare('SELECT * FROM lactate_tests WHERE id = ?');
$stmt->execute([$test_id]);
$t = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$t) {
    http_response_code(404);
    echo json_encode(['error' => 'test not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Само попълнените стъпки (HR или Lactate налични), с реалната им мощност
// от протокола. $points пази само watts+la — входът за LT интерполацията.
$steps = [];
$points = [];
for ($i = 1; $i <= 10; $i++) {
    $hr = num_or_null($t["step{$i}_hr"], 0);
    $la = num_or_null($t["step{$i}_la"], 1);
    if ($hr === null && $la === null) {
        continue;
    }
    $watts = lactate_step_watts($t['protocol'], $i);
    $steps[] = ['watts' => $watts, 'hr' => $hr, 'lactate' => $la];
    $points[] = ['watts' => $watts, 'la' => $la];
}

$lt1_w = $t['lt1_w'] !== null ? (float)$t['lt1_w'] : null;
$lt2_w = $t['lt2_w'] !== null ? (float)$t['lt2_w'] : null;
$lt1_estimated = false;
$lt2_estimated = false;

// Ако LT липсва в базата (никой все още не го е вписал ръчно от Sheet-а),
// изчисляваме го чрез линейна интерполация при прекосяване на 2.0/4.0 mmol.
// Ако кривата никога не пресича прага (напр. тест спрян преди LT2), остава null.
if ($lt1_w === null) {
    $lt1_w = lactate_interpolate_threshold($points, 2.0);
    $lt1_estimated = $lt1_w !== null;
}
if ($lt2_w === null) {
    $lt2_w = lactate_interpolate_threshold($points, 4.0);
    $lt2_estimated = $lt2_w !== null;
}

echo json_encode([
    'athlete_name'  => $t['athlete_name'],
    'test_date'     => $t['test_date'],
    'protocol'      => $t['protocol'],
    'ftp'           => num_or_null($t['ftp'], 0),
    'w_kg'          => num_or_null($t['w_kg']),
    'height'        => num_or_null($t['height_cm'], 0),
    'weight'        => num_or_null($t['weight_kg'], 1),
    'age'           => $t['age'] !== null ? (int)$t['age'] : null,
    'lactate_rest'  => num_or_null($t['lactate_start']),
    'hr_rest'       => num_or_null($t['hr_start'], 0),
    'steps'         => $steps,
    'lt1_w'         => $lt1_w !== null ? round($lt1_w, 1) : null,
    'lt2_w'         => $lt2_w !== null ? round($lt2_w, 1) : null,
    'lt1_estimated' => $lt1_estimated,
    'lt2_estimated' => $lt2_estimated,
    // Зоните зависят само от LT1/LT2 (вкл. интерполираните), затова ги смятаме
    // тук вместо да дублираме compute_zones() в JS — единен източник на истина.
    'zones'         => compute_zones($lt1_w, $lt2_w),
    'notes'         => $t['notes'],
], JSON_UNESCAPED_UNICODE);
