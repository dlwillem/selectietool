<?php
/**
 * Entry point — routeert naar dashboard of login.
 */
require_once __DIR__ . '/includes/bootstrap.php';

// Nog geen users-tabel? → eerst installatie draaien.
try {
    $hasUsers = db_value('SELECT COUNT(*) FROM users');
} catch (Throwable $e) {
    redirect('install.php');
}

if (empty($hasUsers)) {
    redirect('install.php');
}

if (is_logged_in()) {
    redirect('pages/home.php');
}
redirect('pages/login.php');
