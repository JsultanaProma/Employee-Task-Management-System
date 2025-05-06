<?php
session_start();
require_once __DIR__ . '/../../php/config/JsonDatabase.php';

// Check authentication and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hr') {
    header('Location: ../../views/auth/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Dashboard - Task Management System</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <style>
/* Reset and Base Styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background-color: #f4f7fa;
    color: #333;
    line-height: 1.6;
    overflow-x: hidden;
}

a {
    text-decoration: none;
    color: inherit;
}

button {
    cursor: pointer;
    border: none;
    outline: none;
}

/* CSS Variables for Consistency */
:root {
    --bg-primary: #f4f7fa;
    --bg-secondary: #ffffff;
    --bg-light: #f8fafc;
    --text-primary: #1a1d2e;
    --text-secondary: #6c757d;
    --accent-color: #007bff;
    --accent-hover: #0056b3;
    --error-color: #dc3545;
    --success-color: #28a745;
    --warning-color: #ffc107;
    --border-color: #e9ecef;
    --shadow-sm: 0 4px 15px rgba(0, 0, 0, 0.05);
    --shadow-lg: 0 6px 20px rgba(0, 0, 0, 0.1);
}

/* Dashboard Layout */
.dashboard-layout {
    display: flex;
    min-height: 100vh;
    background: linear-gradient(to bottom right, rgba(0, 123, 255, 0.05), rgba(0, 86, 179, 0.05));
}

/* Sidebar */
.sidebar {
    width: 280px;
    background: linear-gradient(180deg, #2a2f4f, #1e2235);
    color: #fff;
    padding: 1.5rem;
    position: sticky;
    top: 0;
    height: 100vh;
    transition: transform 0.3s ease;
    z-index: 1000;
}

.sidebar-header {
    padding-bottom: 1.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.sidebar-header h2 {
    font-size: 1.75rem;
    font-weight: 700;
    color: #e9ecef;
    position: relative;
}

.sidebar-header h2::after {
    content: '';
    position: absolute;
    bottom: -8px;
    left: 0;
    width: 50px;
    height: 4px;
    background: linear-gradient(to right, var(--accent-color), var(--accent-hover));
    border-radius: 2px;
}

.nav-links {
    list-style: none;
}

.nav-links li {
    margin-bottom: 0.75rem;
}

.nav-links a {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-radius: 0.5rem;
    font-size: 1rem;
    font-weight: 500;
    transition: all 0.3s ease;
}

.nav-links a svg {
    width: 22px;
    height: 22px;
    fill: #adb5bd;
}

.nav-links a:hover {
    background: #3b3f5c;
    transform: translateX(5px);
}

.nav-links li.active a {
    background: linear-gradient(to right, var(--accent-color), var(--accent-hover));
    color: white;
}

.nav-links li.active a svg {
    fill: white;
}

.nav-links li:last-child a {
    color: var(--error-color);
}

.nav-links li:last-child a svg {
    fill: var(--error-color);
}

.nav-links li:last-child a:hover {
    background: rgba(220, 53, 69, 0.1);
}

/* Main Content */
.main-content {
    flex-grow: 1;
    padding: 2rem;
    background: var(--bg-primary);
}

.content-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.header-left h1 {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-primary);
}

.header-right .header-icons {
    display: flex;
    gap: 1rem;
}

.theme-toggle,
.notification-btn,
.user-menu-btn {
    background: var(--bg-secondary);
    border-radius: 50%;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: var(--shadow-sm);
    transition: all 0.3s ease;
}

.theme-toggle:hover,
.notification-btn:hover,
.user-menu-btn:hover {
    background: var(--accent-color);
    color: white;
    transform: scale(1.1);
}

.theme-toggle svg,
.notification-btn svg,
.user-menu-btn svg {
    width: 20px;
    height: 20px;
    fill: currentColor;
}

.notification-badge {
    position: absolute;
    top: -2px;
    right: -2px;
    background: var(--error-color);
    color: white;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.user-menu-btn {
    border-radius: 2rem;
    padding: 0.5rem 1rem;
    width: auto;
}

.user-menu-btn .username {
    font-size: 0.875rem;
    font-weight: 500;
    margin-left: 0.5rem;
}

/* Dashboard Overview */
.dashboard-overview {
    margin-bottom: 2rem;
}

.dashboard-overview .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
}

.stat-card {
    background: var(--bg-secondary);
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
}

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.stat-header h3 {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--text-primary);
}

.stat-date {
    font-size: 0.9rem;
    color: var(--text-secondary);
}

/* My Assigned Tasks Section */
.my-tasks-section {
    background: var(--bg-secondary);
    border-radius: 1rem;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: var(--shadow-sm);
}

.my-tasks-section h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 1.5rem;
    position: relative;
    padding-left: 1rem;
}

.my-tasks-section h2::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 24px;
    background: linear-gradient(135deg, var(--accent-color), var(--accent-hover));
    border-radius: 4px;
}

.user-tasks-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.5rem;
}

.task-card {
    background: var(--bg-light);
    border-radius: 0.75rem;
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.task-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
}

.task-card h3 {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 0.75rem;
}

.task-card p {
    font-size: 0.9rem;
    color: var(--text-secondary);
    margin-bottom: 0.75rem;
}

.task-card .task-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.task-card .status-badge,
.task-card .priority-badge {
    padding: 0.35rem 0.85rem;
    border-radius: 2rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.task-card .status-badge.pending {
    background: rgba(229, 62, 62, 0.15);
    color: var(--error-color);
}

.task-card .status-badge.in_progress {
    background: rgba(236, 201, 75, 0.15);
    color: var(--warning-color);
}

.task-card .status-badge.completed {
    background: rgba(72, 187, 120, 0.15);
    color: var(--success-color);
}

.task-card .priority-badge.high {
    background: rgba(229, 62, 62, 0.15);
    color: var(--error-color);
}

.task-card .priority-badge.medium {
    background: rgba(236, 201, 75, 0.15);
    color: var(--warning-color);
}

.task-card .priority-badge.low {
    background: rgba(72, 187, 120, 0.15);
    color: var(--success-color);
}

/* Employee Management Section */
.employee-management {
    margin-bottom: 2rem;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.section-header h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    padding-left: 1rem;
    position: relative;
}

.section-header h2::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 24px;
    background: linear-gradient(135deg, var(--accent-color), var(--accent-hover));
    border-radius: 4px;
}

.btn-primary {
    background: linear-gradient(to right, var(--accent-color), var(--accent-hover));
    color: white;
    padding: 0.75rem 1.5rem;
    border-radius: 2rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: var(--shadow-sm);
    transition: all 0.3s ease;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-primary svg {
    width: 18px;
    height: 18px;
    fill: white;
}

/* Task Filters */
.task-filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: var(--bg-secondary);
    border-radius: 1rem;
    box-shadow: var(--shadow-sm);
}

.filter-group,
.search-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-group label,
.search-group label {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-secondary);
}

.filter-group select,
.search-group input {
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    background: var(--bg-light);
    color: var(--text-primary);
    font-size: 0.875rem;
    transition: all 0.2s ease;
}

.filter-group select:focus,
.search-group input:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15);
    outline: none;
}

/* Employee List */
.employee-list {
    display: grid;
    gap: 1.5rem;
}

.employee-item {
    background: var(--bg-secondary);
    border-radius: 1rem;
    padding: 1.75rem;
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: center;
    box-shadow: var(--shadow-sm);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    position: relative;
    overflow: hidden;
}

.employee-item:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
}

.employee-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: linear-gradient(to bottom, var(--accent-color), var(--accent-hover));
    border-radius: 4px 0 0 4px;
}

.employee-info {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.employee-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.employee-name {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--text-primary);
}

.employee-details {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.employee-metrics {
    display: flex;
    gap: 1.5rem;
    margin-top: 0.5rem;
}

.metric {
    display: flex;
    flex-direction: column;
}

.metric-label {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

.metric-value {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--text-primary);
}

.employee-actions {
    display: flex;
    gap: 0.75rem;
}

.btn-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: var(--bg-light);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.btn-icon:hover {
    background: var(--accent-color);
}

.btn-icon svg {
    width: 20px;
    height: 20px;
    fill: var(--text-secondary);
}

.btn-icon:hover svg {
    fill: white;
}

.status-badge {
    padding: 0.35rem 0.85rem;
    border-radius: 2rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-active {
    background: rgba(72, 187, 120, 0.15);
    color: var(--success-color);
}

.status-onleave {
    background: rgba(236, 201, 75, 0.15);
    color: var(--warning-color);
}

.status-remote {
    background: rgba(66, 153, 225, 0.15);
    color: var(--accent-color);
}

/* Task Management Section */
.task-management {
    background: var(--bg-secondary);
    border-radius: 1rem;
    padding: 2rem;
    box-shadow: var(--shadow-sm);
}

.task-list {
    display: grid;
    gap: 1.5rem;
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.75);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.modal.active,
.modal.show {
    display: flex;
}

.modal-content {
    background: var(--bg-primary);
    border-radius: 1.5rem;
    width: 100%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.75rem 2rem;
    border-bottom: 1px solid var(--border-color);
}

.modal-header h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
}

.close-modal {
    background: none;
    font-size: 1.5rem;
    color: var(--text-secondary);
    padding: 0.5rem;
    border-radius: 0.5rem;
    transition: all 0.2s ease;
}

.close-modal:hover {
    background: rgba(229, 62, 62, 0.1);
    color: var(--error-color);
}

.modal-body {
    padding: 2rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--text-secondary);
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    background: var(--bg-light);
    color: var(--text-primary);
    font-size: 0.95rem;
    transition: all 0.2s ease;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15);
    outline: none;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    padding: 1.5rem 2rem;
    border-top: 1px solid var(--border-color);
}

.btn-secondary {
    padding: 0.75rem 1.5rem;
    border-radius: 2rem;
    background: var(--bg-light);
    color: var(--text-secondary);
    font-weight: 600;
    border: 1px solid var(--border-color);
    transition: all 0.2s ease;
}

.btn-secondary:hover {
    background: var(--bg-secondary);
    color: var(--text-primary);
}

/* Menu Toggle */
.menu-toggle {
    display: none;
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: var(--accent-color);
    color: white;
    align-items: center;
    justify-content: center;
    box-shadow: var(--shadow-sm);
    z-index: 1001;
}

.menu-toggle svg {
    width: 24px;
    height: 24px;
    fill: white;
}

/* Loading Indicator */
.loading-indicator {
    text-align: center;
    padding: 2rem;
    background: var(--bg-light);
    border-radius: 1rem;
    color: var(--accent-color);
    font-weight: 500;
}

/* Responsive Design */
@media (max-width: 1024px) {
    .dashboard-layout {
        flex-direction: column;
    }

    .sidebar {
        position: fixed;
        left: -280px;
        transition: left 0.3s ease;
        z-index: 1000;
    }

    .sidebar.active {
        left: 0;
    }

    .main-content {
        margin-left: 0;
    }

    .menu-toggle {
        display: flex;
    }
}

@media (max-width: 768px) {
    .main-content {
        padding: 1rem;
    }

    .content-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }

    .header-icons {
        width: 100%;
        justify-content: space-between;
    }

    .dashboard-overview .grid {
        grid-template-columns: 1fr;
    }

    .user-tasks-container,
    .task-filters,
    .employee-list,
    .task-list {
        grid-template-columns: 1fr;
    }

    .employee-item {
        grid-template-columns: 1fr;
    }

    .employee-actions {
        justify-content: flex-end;
        margin-top: 1rem;
    }

    .modal-content {
        margin: 10% 5%;
        max-width: 90%;
    }
}

@media (max-width: 480px) {
    .stat-card,
    .task-card,
    .employee-item {
        padding: 1rem;
    }

    .section-header h2,
    .my-tasks-section h2,
    .modal-header h2 {
        font-size: 1.3rem;
    }

    .btn-primary,
    .btn-secondary {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }
}
</style>
</head>
<body class="dashboard-page" style="font-family: 'Inter', sans-serif;">
    <div class="dashboard-layout">
        <!-- Sidebar Navigation -->
        <nav class="sidebar">
            <div class="sidebar-header">
                <h2>Task Manager</h2>
            </div>
            <ul class="nav-links">
                <li class="active">
                    <a href="#dashboard">
                        <svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
                        Dashboard
                    </a>
                </li>
                <li>
                    <a href="#employees">
                        <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                        Employees
                    </a>
                </li>
                <li>
                    <a href="#tasks">
                        <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z"/></svg>
                        Tasks
                    </a>
                </li>
                <li>
                    <a href="#reports">
                        <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                        Reports
                    </a>
                </li>
                <li>
                    <a href="#settings">
                        <svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
                        Settings
                    </a>
                </li>
                <li>
                    <a href="../../views/auth/logout.php">
                        <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                        Logout
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content Area -->
        <main class="main-content">
            <header class="content-header">
                <div class="header-left">
                    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></h1>
                </div>
                <div class="header-right">
                    <div class="header-icons">
                        <button id="themeToggle" class="theme-toggle" aria-label="Toggle theme">
                            <svg class="sun-icon" viewBox="0 0 24 24"><path d="M12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.65 0-3 1.35-3 3s1.35 3 3 3 3-1.35 3-3-1.35-3-3-3zm0-2c.28 0 .5-.22.5-.5v-3c0-.28-.22-.5-.5-.5s-.5.22-.5.5v3c0 .28.22.5.5.5zm0 13c-.28 0-.5.22-.5.5v3c0 .28.22.5.5.5s.5-.22.5-.5v-3c0-.28-.22-.5-.5-.5z"/></svg>
                            <svg class="moon-icon" viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z"/></svg>
                        </button>
                        <button class="notification-btn" aria-label="View notifications">
                            <svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"/></svg>
                            <span class="notification-badge">3</span>
                        </button>
                        <button class="user-menu-btn">
                            <svg class="avatar-icon" viewBox="0 0 24 24">
                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                            </svg>
                            <span class="username"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                        </button>
                    </div>
                </div>
            </header>

            <!-- Dashboard Overview -->
            <section class="dashboard-overview">

            <!-- My Tasks Section -->
            <section class="my-tasks-section" id="mytasks">
                <h2>
                    My Assigned Tasks
                </h2>
                <div id="user-tasks-container" class="user-tasks-container">
                    <!-- Task cards will be loaded here via JavaScript -->
                    <div class="loading-indicator">
                        <p>Loading your tasks...</p>
                    </div>
                </div>
            </section>

            <!-- Employee Management Section -->
            <section class="employee-management">
                <div class="section-header">
                    <h2>Employee Management</h2>
                    <button class="btn-primary" id="addEmployeeBtn">
                        <svg viewBox="0 0 24 24"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                        Add Employee
                    </button>
                </div>

                <!-- Employee Filters -->
                <div class="task-filters">
                    <div class="filter-group">
                        <label for="departmentFilter">Department</label>
                        <select id="departmentFilter">
                            <option value="all">All</option>
                            <option value="engineering">Engineering</option>
                            <option value="marketing">Marketing</option>
                            <option value="design">Design</option>
                            <option value="operations">Operations</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="statusFilter">Status</label>
                        <select id="statusFilter">
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="onleave">On Leave</option>
                            <option value="remote">Remote</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="performanceFilter">Performance</label>
                        <select id="performanceFilter">
                            <option value="all">All</option>
                            <option value="excellent">Excellent</option>
                            <option value="good">Good</option>
                            <option value="average">Average</option>
                            <option value="needs_improvement">Needs Improvement</option>
                        </select>
                    </div>
                    <div class="search-group">
                        <label>Search</label>
                        <input type="text" id="searchEmployees" placeholder="Search employees...">
                    </div>
                </div>

                <!-- Employee List -->
                <div class="employee-list" id="employeeList">
                    <!-- Sample employee for styling reference -->
                    <div class="employee-item">
                        <div class="employee-info">
                            <div class="employee-header">
                                <h3 class="employee-name">John Doe</h3>
                                <span class="status-badge status-active">Active</span>
                            </div>
                            <div class="employee-details">
                                <span class="employee-position">Senior Developer</span>
                                <span class="employee-department">Engineering</span>
                                <span class="employee-email">john.doe@example.com</span>
                            </div>
                            <div class="employee-metrics">
                                <div class="metric">
                                    <span class="metric-label">Tasks</span>
                                    <span class="metric-value">12</span>
                                </div>
                                <div class="metric">
                                    <span class="metric-label">Completed</span>
                                    <span class="metric-value">8</span>
                                </div>
                                <div class="metric">
                                    <span class="metric-label">On Time</span>
                                    <span class="metric-value">92%</span>
                                </div>
                            </div>
                        </div>
                        <div class="employee-actions">
                            <button class="btn-icon" aria-label="View employee">
                                <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                            </button>
                            <button class="btn-icon" aria-label="Edit employee">
                                <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                            </button>
                            <button class="btn-icon" aria-label="Assign task">
                                <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z"/><path d="M18 9l-1.4-1.4-6.6 6.6-2.6-2.6L6 13l4 4z"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Task Management Section -->
            <section class="task-management">
                <div class="section-header">
                    <h2>Task Assignment</h2>
                    <button class="btn-primary" id="createTaskBtn">
                        <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                        Create Task
                    </button>
                </div>

                <!-- Task Filters -->
                <div class="task-filters">
                    <div class="filter-group">
                        <label for="taskStatusFilter">Status</label>
                        <select id="taskStatusFilter">
                            <option value="all">All</option>
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="priorityFilter">Priority</label>
                        <select id="priorityFilter">
                            <option value="all">All</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="assigneeFilter">Assignee</label>
                        <select id="assigneeFilter">
                            <option value="all">All Employees</option>
                            <!-- Employees will be populated dynamically -->
                        </select>
                    </div>
                    <div class="search-group">
                        <label>Search</label>
                        <input type="text" id="searchTasks" placeholder="Search tasks...">
                    </div>
                </div>

                <!-- Task List -->
                <div class="task-list" id="taskList">
                    <!-- Tasks will be populated dynamically -->
                </div>
            </section>
        </main>
    </div>

    <!-- Mobile menu toggle -->
    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
        <svg viewBox="0 0 24 24" width="24" height="24">
            <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/>
        </svg>
    </button>

    <!-- Modal Templates -->
    <div class="modal" id="createTaskModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create New Task</h2>
                <button class="close-modal" aria-label="Close modal">&times;</button>
            </div>
            <form id="createTaskForm" class="modal-body">
                <div class="form-group">
                    <label for="taskTitle">Task Title</label>
                    <input type="text" id="taskTitle" name="title" required>
                </div>
                <div class="form-group">
                    <label for="taskDescription">Description</label>
                    <textarea id="taskDescription" name="description" required></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="assignee">Assign To</label>
                        <select id="assignee" name="assignee" required>
                            <!-- Employees will be populated dynamically -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="taskPriority">Priority</label>
                        <select id="taskPriority" name="priority" required>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="dueDate">Due Date</label>
                        <input type="datetime-local" id="dueDate" name="dueDate" required>
                    </div>
                    <div class="form-group">
                        <label for="estimatedHours">Estimated Hours</label>
                        <input type="number" id="estimatedHours" name="estimatedHours" min="0" step="0.5" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary">Create Task</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Employee Modal -->
    <div class="modal" id="addEmployeeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Employee</h2>
                <button class="close-modal" aria-label="Close modal">&times;</button>
            </div>
            <form id="addEmployeeForm" class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="firstName">First Name</label>
                        <input type="text" id="firstName" name="firstName" required>
                    </div>
                    <div class="form-group">
                        <label for="lastName">Last Name</label>
                        <input type="text" id="lastName" name="lastName" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="department">Department</label>
                        <select id="department" name="department" required>
                            <option value="engineering">Engineering</option>
                            <option value="marketing">Marketing</option>
                            <option value="design">Design</option>
                            <option value="operations">Operations</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="position">Position</label>
                        <input type="text" id="position" name="position" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="joinDate">Join Date</label>
                    <input type="date" id="joinDate" name="joinDate" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary">Add Employee</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
    <script src="../../assets/js/theme.js"></script>
    <script src="../../assets/js/charts.js"></script>
    <script src="../../assets/js/dashboard.js"></script>
    <script src="../../assets/js/user-tasks.js"></script>
    <script src="../../assets/js/modern-dashboard.js"></script>
    <script>
        // Initialize user tasks
        document.addEventListener('DOMContentLoaded', function() {
            const username = '<?php echo htmlspecialchars($_SESSION["username"]); ?>';
            UserTasks.init(username, 'user-tasks-container');
        });
    </script>
</body>
</html>