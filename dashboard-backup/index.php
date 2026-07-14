<?php
session_start();
require_once 'includes/config.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $env_path = get_secrets_path();
    $env_content = file_get_contents($env_path);
    preg_match('/DASHBOARD_PASSWORD=(.*)/', $env_content, $matches);
    $correct_password = isset($matches[1]) ? trim($matches[1]) : '';
    if ($password === $correct_password && $correct_password !== '') {
        $_SESSION['logged_in'] = true;
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Grешна парола';
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Login</title>
<style>body{font-family:Arial;background:#f4f4f4;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}.box{background:white;padding:40px;border-radius:8px;width:300px;}input{width:100%;padding:10px;margin:10px 0;box-sizing:border-box;}button{width:100%;padding:10px;background:#2250e3;color:white;border:none;border-radius:4px;}</style>
</head><body>
<div class="box">
<h2>Athlete Dashboard</h2>
<form method="POST">
<input type="password" name="password" placeholder="Parola" required autofocus>
<button type="submit">Vhod</button>
</form>
<?php if ($error) { echo '<p style="color:red">' . htmlspecialchars($error) . '</p>'; } ?>
</div>
</body></html>
