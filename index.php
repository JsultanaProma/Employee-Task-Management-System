<?php
session_start();
require_once __DIR__ . '/php/config/JsonDatabase.php';

// Check if user is logged in
function isAuthenticated() {
    return isset($_SESSION['user_id']);
}

// Check user role
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

// Redirect based on role
if (isAuthenticated()) {
    $role = $_SESSION['role'];
    switch ($role) {
        case 'admin':
            header('Location: views/admin/dashboard.php');
            break;
        case 'hr':
            header('Location: views/hr/dashboard.php');
            break;
        case 'employee':
            header('Location: views/employee/dashboard.php');
            break;
        default:
            session_destroy();
            header('Location: views/auth/login.php');
    }
    exit;
}

// Redirect to login if not authenticated
header('Location: views/auth/login.php');
exit;
?>