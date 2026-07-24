<?php
// JSON endpoint за детайлен лактатен тест — консумиран от lactate_analysis.php
// (и от бъдещи фази: сравнение между тестове, cross-athlete анализ).
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/lactate_zones.php';

header('Content-Type: application/json; charset=utf-8');

// Не викаме require_login() тук нарочно — тя прави header('Location: index.php')
// при липсваща сесия, което fetch() просто следва мълчаливо и връща index.php-ѝ
// HTML вместо JSON. Auth.php вече е стартирал сесията отгоре; проверяваме я
// директно и връщаме 401 JSON, който JS-ът на страницата може да прихване.
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
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
    $hr = $t["step{$i}_hr"] !== null ? (float)$t["step{$i}_hr"] : null;
    $la = $t["step{$i}_la"] !== null ? (float)$t["step{$i}_la"] : null;
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
    'ftp'           => $t['ftp'] !== null ? (float)$t['ftp'] : null,
    'w_kg'          => $t['w_kg'] !== null ? (float)$t['w_kg'] : null,
    'height'        => $t['height_cm'] !== null ? (float)$t['height_cm'] : null,
    'weight'        => $t['weight_kg'] !== null ? (float)$t['weight_kg'] : null,
    'age'           => $t['age'] !== null ? (int)$t['age'] : null,
    'lactate_rest'  => $t['lactate_start'] !== null ? (float)$t['lactate_start'] : null,
    'hr_rest'       => $t['hr_start'] !== null ? (float)$t['hr_start'] : null,
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
