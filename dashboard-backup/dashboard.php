<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/metrics_glossary.php';
require_login();

$pdo = get_db_connection();

// Взимаме списък на атлетите (уникални по athlete_id) от последните записи
$athletes_query = $pdo->query("
    SELECT DISTINCT athlete_id, athlete_name
    FROM daily_metrics
    ORDER BY athlete_name
");
$athletes = $athletes_query->fetchAll(PDO::FETCH_ASSOC);

function get_latest_metrics($pdo, $athlete_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM daily_metrics
        WHERE athlete_id = ?
        ORDER BY date DESC LIMIT 1
    ");
    $stmt->execute([$athlete_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// world_triathlon се пълни с World Triathlon ID (не intervals ID),
// затова търсим по athlete_name — общото поле от config/athletes.yaml
function get_latest_ranking($pdo, $athlete_name) {
    $stmt = $pdo->prepare("
        SELECT * FROM world_triathlon
        WHERE athlete_name = ?
        ORDER BY fetched_at DESC LIMIT 1
    ");
    $stmt->execute([$athlete_name]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function get_recent_alerts($pdo, $athlete_id, $limit = 5) {
    $stmt = $pdo->prepare("
        SELECT * FROM alert_events
        WHERE athlete_id = ?
        ORDER BY detected_at DESC LIMIT ?
    ");
    $stmt->bindValue(1, $athlete_id);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function status_badge($status) {
    $colors = ['ok' => '#2e7d32', 'low' => '#f57c00', 'high' => '#c62828', 'no_data' => '#999'];
    $labels = ['ok' => 'Нормално', 'low' => 'Детрениране', 'high' => 'Риск', 'no_data' => 'Няма данни'];
    $color = $colors[$status] ?? '#999';
    $label = $labels[$status] ?? $status;
    return "<span style=\"background:$color;color:white;padding:3px 10px;border-radius:12px;font-size:13px;\">$label</span>";
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Athlete Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        h1 { margin: 0; color: #333; }
        .logout { color: #2250e3; text-decoration: none; }
        .athletes-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
        .card { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .card h2 { margin-top: 0; color: #2250e3; }
        .metric-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #eee; }
        .metric-label { color: #666; }
        .metric-value { font-weight: bold; }
        .alerts { margin-top: 15px; }
        .alert-item { font-size: 13px; color: #555; padding: 4px 0; border-bottom: 1px solid #f0f0f0; }
        .no-alerts { color: #999; font-size: 13px; font-style: italic; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Athlete Dashboard</h1>
        <a href="logout.php" class="logout">Изход</a>
    </div>

    <div class="athletes-grid">
        <?php foreach ($athletes as $athlete): ?>
            <?php
                $metrics = get_latest_metrics($pdo, $athlete['athlete_id']);
                $ranking = get_latest_ranking($pdo, $athlete['athlete_name']);
                $alerts = get_recent_alerts($pdo, $athlete['athlete_id']);
            ?>
            <div class="card">
                <h2><a href="athlete.php?id=<?= urlencode($athlete['athlete_id']) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($athlete['athlete_name']) ?> &rsaquo;</a></h2>

                <?php if ($metrics): ?>
                    <div class="metric-row">
                        <span class="metric-label">Последна дата</span>
                        <span class="metric-value"><?= htmlspecialchars($metrics['date']) ?></span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-label">ACWR</span>
                        <span class="metric-value"><?= htmlspecialchars($metrics['acwr'] ?? '—') ?> <?= status_badge($metrics['acwr_status']) ?></span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-label">CTL (Fitness)</span>
                        <span class="metric-value"><?= round($metrics['ctl'] ?? 0, 1) ?></span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-label">ATL (Fatigue)</span>
                        <span class="metric-value"><?= round($metrics['atl'] ?? 0, 1) ?></span>
                    </div>
                    <?php if ($metrics['hrv']): ?>
                    <div class="metric-row">
                        <span class="metric-label">HRV</span>
                        <span class="metric-value"><?= htmlspecialchars($metrics['hrv']) ?></span>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="no-alerts">Няма тренировъчни данни</p>
                <?php endif; ?>

                <?php if ($ranking): ?>
                    <div class="metric-row">
                        <span class="metric-label">World Ranking</span>
                        <span class="metric-value">#<?= htmlspecialchars($ranking['world_ranking'] ?? '—') ?></span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-label">Regional Ranking</span>
                        <span class="metric-value">#<?= htmlspecialchars($ranking['regional_ranking'] ?? '—') ?></span>
                    </div>
                <?php endif; ?>

                <div class="alerts">
                    <strong>Последни аларми:</strong>
                    <?php if ($alerts): ?>
                        <?php foreach ($alerts as $alert): ?>
                            <div class="alert-item"><?= htmlspecialchars($alert['event_date']) ?>: <?= htmlspecialchars($alert['message']) ?></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="no-alerts">Няма аларми</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php render_metrics_legend(); ?>
</body>
</html>
