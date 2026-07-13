<?php
session_start();

function require_login() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: index.php');
        exit;
    }
}
