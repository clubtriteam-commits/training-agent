<?php
ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 30);
session_set_cookie_params(60 * 60 * 24 * 30);
session_start();

function require_login() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: index.php');
        exit;
    }
}
