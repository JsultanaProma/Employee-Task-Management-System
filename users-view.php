<?php
session_start();
require_once __DIR__ . '/../../php/config/JsonDatabase.php';

// Check authentication and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Initialize users file
$usersFile = __DIR__ . '/../../database/json/users.json';
$users = file_exists($usersFile) ? json_decode(file_get_contents($usersFile), true) : [];

// Function to format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

// Function to generate a unique user ID
function generateUserId($users) {
    return empty($users) ? 1 : max(array_column($users, 'id')) + 1;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_user':
                // Validate required fields
                if (empty($_POST['firstName']) || empty($_POST['lastName']) || empty($_POST['email']) || empty($_POST['password']) || empty($_POST['confirmPassword']) || empty($_POST['role'])) {
                    $response['message'] = 'All required fields must be filled';
                    echo json_encode($response);
                    exit;
                }

                $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $response['message'] = 'Invalid email address';
                    echo json_encode($response);
                    exit;
                }

                // Check if email already exists
                foreach ($users as $user) {
                    if ($user['email'] === $email) {
                        $response['message'] = 'Email already exists';
                        echo json_encode($response);
                        exit;
                    }
                }

                // Check if passwords match
                if ($_POST['password'] !== $_POST['confirmPassword']) {
                    $response['message'] = 'Passwords do not match';
                    echo json_encode($response);
                    exit;
                }

                // Generate username (simple, lowercase, no special characters)
                $username = strtolower(trim($_POST['firstName']) . '_' . trim($_POST['lastName']));
                $username = preg_replace('/[^a-z0-9_]/', '', $username);

                $newUser = [
                    'id' => generateUserId($users),
                    'username' => $username,
                    'email' => $email,
                    'password' => $_POST['password'], // Store plain text password
                    'firstName' => trim($_POST['firstName']),
                    'lastName' => trim($_POST['lastName']),
                    'role' => $_POST['role'],
                    'department' => $_POST['department'] ?? 'none',
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'last_login' => null,
                    'permissions' => [
                        'tasks' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
                        'users' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
                        'reports' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
                        'settings' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false]
                    ]
                ];

                // Write to users.json with file locking
                $fp = fopen($usersFile, 'c+');
                if (flock($fp, LOCK_EX)) {
                    $users[] = $newUser;
                    ftruncate($fp, 0);
                    fwrite($fp, json_encode($users, JSON_PRETTY_PRINT));
                    fflush($fp);
                    flock($fp, LOCK_UN);
                    $response['success'] = true;
                    $response['message'] = 'User added successfully';
                } else {
                    $response['message'] = 'Failed to lock file for writing';
                    error_log('Failed to lock users.json for writing at ' . date('Y-m-d H:i:s'));
                }
                fclose($fp);
                break;

            case 'edit_user':
                $userId = (int)$_POST['userId'];
                $userIndex = array_search($userId, array_column($users, 'id'));

                if ($userIndex === false) {
                    $response['message'] = 'User not found';
                    echo json_encode($response);
                    exit;
                }

                $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $response['message'] = 'Invalid email address';
                    echo json_encode($response);
                    exit;
                }

                // Check if email is taken by another user
                foreach ($users as $index => $user) {
                    if ($index != $userIndex && $user['email'] === $email) {
                        $response['message'] = 'Email already exists';
                        echo json_encode($response);
                        exit;
                    }
                }

                $users[$userIndex]['firstName'] = trim($_POST['firstName']);
                $users[$userIndex]['lastName'] = trim($_POST['lastName']);
                $users[$userIndex]['email'] = $email;
                $users[$userIndex]['role'] = $_POST['role'];
                $users[$userIndex]['department'] = $_POST['department'] ?? 'none';
                $users[$userIndex]['username'] = strtolower(trim($_POST['firstName']) . '_' . trim($_POST['lastName']));
                $users[$userIndex]['username'] = preg_replace('/[^a-z0-9_]/', '', $users[$userIndex]['username']);
                $users[$userIndex]['updated_at'] = date('Y-m-d H:i:s');

                if (!empty($_POST['password'])) {
                    $users[$userIndex]['password'] = $_POST['password']; // Store plain text password
                }

                file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
                $response['success'] = true;
                $response['message'] = 'User updated successfully';
                break;

            case 'disable_user':
                $userId = (int)$_POST['userId'];
                $userIndex = array_search($userId, array_column($users, 'id'));

                if ($userIndex === false) {
                    $response['message'] = 'User not found';
                    echo json_encode($response);
                    exit;
                }

                $users[$userIndex]['status'] = $users[$userIndex]['status'] === 'active' ? 'inactive' : 'active';
                $users[$userIndex]['updated_at'] = date('Y-m-d H:i:s');
                file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
                $response['success'] = true;
                $response['message'] = 'User status updated';
                $response['newStatus'] = $users[$userIndex]['status'];
                break;

            case 'update_permissions':
                $userId = (int)$_POST['userId'];
                $userIndex = array_search($userId, array_column($users, 'id'));

                if ($userIndex === false) {
                    $response['message'] = 'User not found';
                    echo json_encode($response);
                    exit;
                }

                $permissions = [
                    'tasks' => [
                        'view' => isset($_POST['perm_tasks_view']),
                        'create' => isset($_POST['perm_tasks_create']),
                        'edit' => isset($_POST['perm_tasks_edit']),
                        'delete' => isset($_POST['perm_tasks_delete'])
                    ],
                    'users' => [
                        'view' => isset($_POST['perm_users_view']),
                        'create' => isset($_POST['perm_users_create']),
                        'edit' => isset($_POST['perm_users_edit']),
                        'delete' => isset($_POST['perm_users_delete'])
                    ],
                    'reports' => [
                        'view' => isset($_POST['perm_reports_view']),
                        'create' => isset($_POST['perm_reports_create']),
                        'edit' => isset($_POST['perm_reports_edit']),
                        'delete' => isset($_POST['perm_reports_delete'])
                    ],
                    'settings' => [
                        'view' => isset($_POST['perm_settings_view']),
                        'create' => isset($_POST['perm_settings_create']),
                        'edit' => isset($_POST['perm_settings_edit']),
                        'delete' => isset($_POST['perm_settings_delete'])
                    ]
                ];

                $users[$userIndex]['permissions'] = $permissions;
                $users[$userIndex]['updated_at'] = date('Y-m-d H:i:s');
                file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
                $response['success'] = true;
                $response['message'] = 'Permissions updated successfully';
                break;

            default:
                $response['message'] = 'Invalid action';
        }
    } else {
        $response['message'] = 'No action specified';
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - Task Management System</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
</head>
<body class="dashboard-page">
    <div class="dashboard-layout">
        <!-- Sidebar Navigation -->
        <nav class="sidebar">
            <div class="sidebar-header">
                <h2>Task Manager</h2>
            </div>
            <ul class="nav-links">
                <li><a href="dashboard.php"><svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z" /></svg>Dashboard</a></li>
                <li><a href="tasks-view.php"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z" /></svg>Tasks</a></li>
                <li><a href="teams-view.php"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" /></svg>Teams</a></li>
                <li><a href="projects-view.php"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zM7 10h5v5H7z" /></svg>Projects</a></li>
                <li><a href="departments-view.php"><svg viewBox="0 0 24 24"><path d="M12 2L4.5 20.29l.71.71L12 18l6.79 3 .71-.71z" /></svg>Departments</a></li>
                <li><a href="notifications-view.php"><svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z" /></svg>Notifications</a></li>
                <li class="active"><a href="users-view.php"><svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" /></svg>Users</a></li>
                <li><a href="reports-view.php"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" /></svg>Reports</a></li>
                <li><a href="dashboard.php#settings"><svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l-.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z" /></svg>Settings</a></li>
                <li class="logout-item"><a href="../../views/auth/logout.php"><svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z" /></svg>Logout</a></li>
            </ul>
        </nav>
        <!-- Main Content Area -->
        <main class="main-content">
            <header class="content-header">
                <div class="header-left">
                    <h1>Users</h1>
                </div>
                <div class="header-right">
                    <div class="header-icons">
                        <button id="themeToggle" class="theme-toggle" aria-label="Toggle theme">
                            <svg class="sun-icon" viewBox="0 0 24 24"><path d="M12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.65 0-3 1.35-3 3s1.35 3 3 3 3-1.35 3-3-1.35-3-3-3zm0-2c.28 0 .5-.22.5-.5v-3c0-.28-.22-.5-.5-.5s-.5.22-.5.5v3c0 .28.22.5.5.5zm0 13c-.28 0-.5.22-.5.5v3c0 .28.22.5.5.5s.5-.22.5-.5v-3c0-.28-.22-.5-.5-.5z" /></svg>
                            <svg class="moon-icon" viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z" /></svg>
                        </button>
                        <button class="notification-btn" aria-label="View notifications">
                            <svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z" /></svg>
                            <span class="notification-badge">3</span>
                        </button>
                        <div class="user-menu-container">
                            <button class="user-menu-btn">
                                <svg class="avatar-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" /></svg>
                                <span class="username"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                            </button>
                            <div class="user-dropdown">
                                <a href="settings-view.php" class="dropdown-item"><svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l-.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z" /></svg>Settings</a>
                                <a href="../../views/auth/logout.php" class="dropdown-item"><svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z" /></svg>Logout</a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>
            <!-- User Management Section -->
            <section class="user-management">
                <div class="section-header">
                    <h2>User Management</h2>
                    <button class="btn-primary" id="addUserBtn"><svg viewBox="0 0 24 24"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" /></svg>Add User</button>
                </div>
                <!-- User Filters -->
                <div class="task-filters">
                    <div class="filter-group">
                        <label for="userRoleFilter">Role</label>
                        <select id="userRoleFilter">
                            <option value="all">All Roles</option>
                            <option value="admin">Admin</option>
                            <option value="hr">HR</option>
                            <option value="employee">Employee</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="userStatusFilter">Status</label>
                        <select id="userStatusFilter">
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="search-group">
                        <label>Search</label>
                        <input type="text" id="searchUsers" placeholder="Search users...">
                    </div>
                </div>
                <!-- User List -->
                <div class="user-list" id="userList">
                    <?php foreach ($users as $user) : ?>
                        <div class="user-item" data-id="<?php echo $user['id']; ?>" data-role="<?php echo $user['role']; ?>" data-status="<?php echo $user['status']; ?>">
                            <div class="user-info">
                                <div class="user-header">
                                    <h3 class="user-name"><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></h3>
                                    <span class="role-badge role-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span>
                                </div>
                                <div class="user-details">
                                    <span class="user-email"><?php echo htmlspecialchars($user['email']); ?></span>
                                    <span class="user-joined">Joined: <?php echo formatDate($user['created_at']); ?></span>
                                    <span class="user-status <?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span>
                                </div>
                            </div>
                            <div class="user-actions">
                                <button class="btn-icon edit-user-btn" aria-label="Edit user"><svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" /></svg></button>
                                <button class="btn-icon permission-btn" aria-label="Edit permissions"><svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z" /></svg></button>
                                <button class="btn-icon disable-user-btn" aria-label="Toggle user status"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8 0-1.85.63-3.55 1.69-4.9L16.9 18.31C15.55 19.37 13.85 20 12 20zm6.31-3.1L7.1 5.69C8.45 4.63 10.15 4 12 4c4.42 0 8 3.58 8 8 0 1.85-.63 3.55-1.69 4.9z" /></svg></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
    <!-- Mobile menu toggle -->
    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
        <svg viewBox="0 0 24 24" width="24" height="24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" /></svg>
    </button>
    <!-- Modal Templates -->
    <!-- Add User Modal -->
    <div class="modal" id="addUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New User</h2>
                <button class="close-modal" aria-label="Close modal">×</button>
            </div>
            <form id="addUserForm" class="modal-body">
                <input type="hidden" name="action" value="add_user">
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
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="role">Role</label>
                        <select id="role" name="role" required>
                            <option value="admin">Admin</option>
                            <option value="hr">HR</option>
                            <option value="employee">Employee</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="department">Department</label>
                        <select id="department" name="department">
                            <option value="management">Management</option>
                            <option value="hr">Human Resources</option>
                            <option value="engineering">Engineering</option>
                            <option value="marketing">Marketing</option>
                            <option value="design">Design</option>
                            <option value="none" selected>None</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="confirmPassword">Confirm Password</label>
                        <input type="password" id="confirmPassword" name="confirmPassword" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary" id="addUserSubmit">Add User</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Edit User Modal -->
    <div class="modal" id="editUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit User</h2>
                <button class="close-modal" aria-label="Close modal">×</button>
            </div>
            <form id="editUserForm" class="modal-body">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" id="userId" name="userId">
                <div class="form-row">
                    <div class="form-group">
                        <label for="editFirstName">First Name</label>
                        <input type="text" id="editFirstName" name="firstName" required>
                    </div>
                    <div class="form-group">
                        <label for="editLastName">Last Name</label>
                        <input type="text" id="editLastName" name="lastName" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="editEmail">Email</label>
                    <input type="email" id="editEmail" name="email" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="editRole">Role</label>
                        <select id="editRole" name="role" required>
                            <option value="admin">Admin</option>
                            <option value="hr">HR</option>
                            <option value="employee">Employee</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editDepartment">Department</label>
                        <select id="editDepartment" name="department">
                            <option value="management">Management</option>
                            <option value="hr">Human Resources</option>
                            <option value="engineering">Engineering</option>
                            <option value="marketing">Marketing</option>
                            <option value="design">Design</option>
                            <option value="none">None</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="editPassword">Password (leave blank to keep current)</label>
                    <input type="password" id="editPassword" name="password">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Permissions Modal -->
    <div class="modal" id="permissionsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit User Permissions</h2>
                <button class="close-modal" aria-label="Close modal">×</button>
            </div>
            <form id="permissionsForm" class="modal-body">
                <input type="hidden" name="action" value="update_permissions">
                <input type="hidden" id="permUserId" name="userId">
                <table class="permissions-table">
                    <thead>
                        <tr>
                            <th>Permission</th>
                            <th>View</th>
                            <th>Create</th>
                            <th>Edit</th>
                            <th>Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Tasks</td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_tasks_view"><span class="toggle-slider"></span></label></td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_tasks_create"><span class="toggle-slider"></span></label></td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_tasks_edit"><span class="toggle-slider"></span></label></td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_tasks_delete"><span class="toggle-slider"></span></label></td>
                        </tr>
                        <tr>
                            <td>Users</td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_users_view"><span class="toggle-slider"></span></label></td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_users_create"><span class="toggle-slider"></span></label></td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_users_edit"><span class="toggle-slider"></span></label></td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_users_delete"><span class="toggle-slider"></span></label></td>
                        </tr>
                        <tr>
                            <td>Reports</td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_reports_view"><span class="toggle-slider"></span></label></td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_reports_create"><span class="toggle-slider"></span></label></td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_reports_edit"><span class="toggle-slider"></span></label></td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_reports_delete"><span class="toggle-slider"></span></label></td>
                        </tr>
                        <tr>
                            <td>Settings</td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_settings_view"><span class="toggle-slider"></span></label></td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_settings_create"><span class="toggle-slider"></span></label></td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_settings_edit"><span class="toggle-slider"></span></label></td>
                            <td><label class="permission-toggle"><input type="checkbox" name="perm_settings_delete"><span class="toggle-slider"></span></label></td>
                        </tr>
                    </tbody>
                </table>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary">Save Permissions</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Scripts -->
    <script src="../../assets/js/theme.js"></script>
    <script src="../../assets/js/dashboard.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal setup
            setupModals();

            // Mobile menu toggle
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.querySelector('.sidebar');
            if (menuToggle) {
                menuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show-mobile');
                });
            }

            // Add User Modal
            const addUserBtn = document.getElementById('addUserBtn');
            const addUserModal = document.getElementById('addUserModal');
            if (addUserBtn) {
                addUserBtn.addEventListener('click', function() {
                    openModal(addUserModal);
                    document.getElementById('addUserForm').reset();
                });
            }

            // Handle Add User Form Submission
            const addUserForm = document.getElementById('addUserForm');
            const addUserSubmit = document.getElementById('addUserSubmit');
            if (addUserForm) {
                addUserForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    // Client-side password match validation
                    const password = document.getElementById('password').value;
                    const confirmPassword = document.getElementById('confirmPassword').value;
                    if (password !== confirmPassword) {
                        alert('Error: Passwords do not match');
                        return;
                    }

                    const formData = new FormData(this);
                    // Log form data for debugging
                    for (let [key, value] of formData.entries()) {
                        console.log(`${key}: ${value}`);
                    }

                    addUserSubmit.disabled = true;
                    addUserSubmit.textContent = 'Adding...';

                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        addUserSubmit.disabled = false;
                        addUserSubmit.textContent = 'Add User';
                        if (data.success) {
                            alert(data.message);
                            closeModal(addUserModal);
                            location.reload(); // Refresh to show new user
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        addUserSubmit.disabled = false;
                        addUserSubmit.textContent = 'Add User';
                        alert('An error occurred while adding the user: ' + error.message);
                    });
                });
            }

            // Edit User Buttons
            document.querySelectorAll('.edit-user-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const userItem = this.closest('.user-item');
                    const userId = userItem.dataset.id;
                    const user = <?php echo json_encode($users); ?>.find(u => u.id == userId);
                    if (user) {
                        const editModal = document.getElementById('editUserModal');
                        document.getElementById('userId').value = user.id;
                        document.getElementById('editFirstName').value = user.firstName;
                        document.getElementById('editLastName').value = user.lastName;
                        document.getElementById('editEmail').value = user.email;
                        document.getElementById('editRole').value = user.role;
                        document.getElementById('editDepartment').value = user.department || 'none';
                        document.getElementById('editPassword').value = '';
                        openModal(editModal);
                    }
                });
            });

            // Handle Edit User Form Submission
            const editUserForm = document.getElementById('editUserForm');
            if (editUserForm) {
                editUserForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            closeModal(document.getElementById('editUserModal'));
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while updating the user');
                    });
                });
            }

            // Disable User Buttons
            document.querySelectorAll('.disable-user-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const userItem = this.closest('.user-item');
                    const userId = userItem.dataset.id;
                    if (confirm('Are you sure you want to toggle this user\'s status?')) {
                        const formData = new FormData();
                        formData.append('action', 'disable_user');
                        formData.append('userId', userId);
                        fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.message);
                                userItem.dataset.status = data.newStatus;
                                userItem.querySelector('.user-status').textContent = data.newStatus.charAt(0).toUpperCase() + data.newStatus.slice(1);
                                userItem.querySelector('.user-status').className = 'user-status ' + data.newStatus;
                            } else {
                                alert('Error: ' + data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while updating user status');
                        });
                    }
                });
            });

            // Permissions Buttons
            document.querySelectorAll('.permission-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const userItem = this.closest('.user-item');
                    const userId = userItem.dataset.id;
                    const user = <?php echo json_encode($users); ?>.find(u => u.id == userId);
                    if (user) {
                        const permModal = document.getElementById('permissionsModal');
                        document.getElementById('permUserId').value = user.id;
                        // Populate permissions (with fallback for users without permissions)
                        const permissions = user.permissions || {
                            tasks: { view: false, create: false, edit: false, delete: false },
                            users: { view: false, create: false, edit: false, delete: false },
                            reports: { view: false, create: false, edit: false, delete: false },
                            settings: { view: false, create: false, edit: false, delete: false }
                        };
                        document.querySelector('[name="perm_tasks_view"]').checked = permissions.tasks.view;
                        document.querySelector('[name="perm_tasks_create"]').checked = permissions.tasks.create;
                        document.querySelector('[name="perm_tasks_edit"]').checked = permissions.tasks.edit;
                        document.querySelector('[name="perm_tasks_delete"]').checked = permissions.tasks.delete;
                        document.querySelector('[name="perm_users_view"]').checked = permissions.users.view;
                        document.querySelector('[name="perm_users_create"]').checked = permissions.users.create;
                        document.querySelector('[name="perm_users_edit"]').checked = permissions.users.edit;
                        document.querySelector('[name="perm_users_delete"]').checked = permissions.users.delete;
                        document.querySelector('[name="perm_reports_view"]').checked = permissions.reports.view;
                        document.querySelector('[name="perm_reports_create"]').checked = permissions.reports.create;
                        document.querySelector('[name="perm_reports_edit"]').checked = permissions.reports.edit;
                        document.querySelector('[name="perm_reports_delete"]').checked = permissions.reports.delete;
                        document.querySelector('[name="perm_settings_view"]').checked = permissions.settings.view;
                        document.querySelector('[name="perm_settings_create"]').checked = permissions.settings.create;
                        document.querySelector('[name="perm_settings_edit"]').checked = permissions.settings.edit;
                        document.querySelector('[name="perm_settings_delete"]').checked = permissions.settings.delete;
                        openModal(permModal);
                    }
                });
            });

            // Handle Permissions Form Submission
            const permissionsForm = document.getElementById('permissionsForm');
            if (permissionsForm) {
                permissionsForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            closeModal(document.getElementById('permissionsModal'));
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while updating permissions');
                    });
                });
            }

            // Filter and Search Functionality
            const userRoleFilter = document.getElementById('userRoleFilter');
            const userStatusFilter = document.getElementById('userStatusFilter');
            const searchUsers = document.getElementById('searchUsers');
            const userList = document.getElementById('userList');

            function filterUsers() {
                const roleFilter = userRoleFilter.value;
                const statusFilter = userStatusFilter.value;
                const searchTerm = searchUsers.value.toLowerCase();

                const userItems = userList.querySelectorAll('.user-item');
                userItems.forEach(item => {
                    const role = item.dataset.role;
                    const status = item.dataset.status;
                    const name = item.querySelector('.user-name').textContent.toLowerCase();
                    const email = item.querySelector('.user-email').textContent.toLowerCase();

                    const matchesRole = roleFilter === 'all' || role === roleFilter;
                    const matchesStatus = statusFilter === 'all' || status === statusFilter;
                    const matchesSearch = name.includes(searchTerm) || email.includes(searchTerm);

                    if (matchesRole && matchesStatus && matchesSearch) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
            }

            userRoleFilter.addEventListener('change', filterUsers);
            userStatusFilter.addEventListener('change', filterUsers);
            searchUsers.addEventListener('input', filterUsers);

            // Modal setup function
            function setupModals() {
                const allModals = document.querySelectorAll('.modal');
                allModals.forEach(modal => {
                    modal.querySelectorAll('.close-modal').forEach(closeBtn => {
                        closeBtn.addEventListener('click', function() {
                            closeModal(modal);
                        });
                    });
                    modal.querySelectorAll('[data-dismiss="modal"]').forEach(cancelBtn => {
                        cancelBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            closeModal(modal);
                        });
                    });
                    modal.addEventListener('click', function(e) {
                        if (e.target === this) {
                            closeModal(modal);
                        }
                    });
                });
            }

            // Open modal function
            function openModal(modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                document.body.classList.add('modal-open');
            }

            // Close modal function
            function closeModal(modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
                document.body.classList.remove('modal-open');
            }
        });
    </script>
</body>
</html>