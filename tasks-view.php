<?php
session_start();
require_once __DIR__ . '/../../php/config/JsonDatabase.php';

// Check authentication and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    // If it's an API request (AJAX), return JSON error
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    } else {
        // Otherwise, redirect to login page
        header('Location: ../../views/auth/login.php');
        exit;
    }
}

$jsonDb = JsonDatabase::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// --- API Request Handling --- START
if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
    header('Content-Type: application/json');

    // Add CSRF token check here if implemented

    switch ($method) {
        case 'POST': // Create Task
            handlePost($jsonDb);
            break;
        case 'PUT': // Update Task
            handlePut($jsonDb);
            break;
        case 'DELETE': // Delete Task
            handleDelete($jsonDb);
            break;
    }
    exit; // Stop script execution after handling API request
}
// --- API Request Handling --- END

// --- Page Load Data (GET Request) --- START
// Load required data for page display
$tasks = $jsonDb->query('tasks') ?? []; // Fetch the entire array of tasks

// Directly read users from users.json
$usersFile = __DIR__ . '/../../database/json/users.json';
$users = file_exists($usersFile) ? json_decode(file_get_contents($usersFile), true) : [];

// Directly read teams from teams.json
$teamsFile = __DIR__ . '/../../database/json/teams.json';
$teams = file_exists($teamsFile) ? json_decode(file_get_contents($teamsFile), true) : [];


// Function to format date (keep for display)
function formatDate($date)
{
    // Basic validation in case date is missing or invalid
    if (empty($date) || strtotime($date) === false) {
        return 'N/A';
    }
    return date('M d, Y', strtotime($date));
}

// --- API Helper Functions --- START
function handlePost($db) {
    // Use $_POST for form data submitted normally (or via FormData in JS)
    $data = $_POST;

    $requiredFields = ['title', 'description', 'assignee', 'priority', 'dueDate', 'estimatedHours'];
    foreach ($requiredFields as $field) {
        // Allow assignee to be empty string initially if needed, but check if it exists
        if (!isset($data[$field]) || ($field !== 'assignee' && empty($data[$field]))) {
             if ($field === 'assignee' && isset($data[$field]) && $data[$field] === '') {
                 // Allow empty assignee if required by logic, otherwise enforce it
                 // For now, let's require it based on the original code
                 http_response_code(400);
                 echo json_encode(['error' => "Field '$field' cannot be empty"]);
                 return;
             } else if ($field !== 'assignee'){
                 http_response_code(400);
                 echo json_encode(['error' => "Field '$field' is required and cannot be empty"]);
                 return;
             }
        }
    }

    // Validate date format
    if (isset($data['dueDate']) && (strtotime($data['dueDate']) === false || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $data['dueDate']))) {
         http_response_code(400);
         echo json_encode(['error' => "Invalid Due Date format. Use YYYY-MM-DDTHH:MM"]);
         return;
    }
     // Validate estimated hours
     if (!is_numeric($data['estimatedHours']) || floatval($data['estimatedHours']) < 0) {
         http_response_code(400);
         echo json_encode(['error' => "Estimated Hours must be a non-negative number."]);
         return;
     }


    $newTaskData = [
        'title' => trim($data['title']),
        'description' => trim($data['description']),
        'assignee' => $data['assignee'] ?? null, // Handle potentially empty assignee
        'priority' => $data['priority'],
        'dueDate' => $data['dueDate'], // Store as ISO 8601 format or similar
        'estimatedHours' => floatval($data['estimatedHours']),
        'status' => 'pending', // Default status
        'created_by' => $_SESSION['username'] ?? 'system',
        'createdAt' => date('c'), // ISO 8601 format
        'updated_at' => date('c')
    ];


    $taskId = $db->insert('tasks', $newTaskData);

    // Fetch the newly created task to return it
    $createdTasks = $db->query('tasks', ['id' => $taskId]);
    $createdTask = !empty($createdTasks) ? array_values($createdTasks)[0] : null;

    if ($createdTask) {
        http_response_code(201);
        // Ensure all expected fields are in the response, even if null
        $responseTask = array_merge([
             'id' => $taskId,
             'title' => null, 'description' => null, 'assignee' => null,
             'priority' => null, 'dueDate' => null, 'estimatedHours' => null,
             'status' => null, 'created_by' => null, 'createdAt' => null, 'updated_at' => null
        ], $createdTask);
        echo json_encode(['success' => true, 'task' => $responseTask]);
    } else {
        error_log("Failed to retrieve task after insert with ID: " . $taskId); // Log error
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create task or retrieve it after creation']);
    }
}


function handlePut($db) {
    // PUT data comes from request body, not $_POST
    parse_str(file_get_contents('php://input'), $data);

    if (!isset($data['taskId']) || empty($data['taskId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Task ID is required']);
        return;
    }
    $id = $data['taskId'];

    // Fetch existing task first to ensure it exists
    $existingTasks = $db->query('tasks', ['id' => $id]);
    if (empty($existingTasks)) {
        http_response_code(404);
        echo json_encode(['error' => 'Task not found']);
        return;
    }
    $existingTask = array_values($existingTasks)[0];


    // Fields allowed to be updated
    $allowedFields = ['title', 'description', 'assignee', 'priority', 'dueDate', 'status', 'estimatedHours'];
    $updateData = [];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) { // Only update if field is present in the request
             // Basic validation similar to POST
            if (empty($data[$field]) && $field !== 'assignee') { // Allow empty assignee maybe? Adjust if needed
                 http_response_code(400);
                 echo json_encode(['error' => "Field '$field' cannot be empty"]);
                 return;
             }
             if ($field === 'dueDate' && (strtotime($data[$field]) === false || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $data[$field]))) {
                 http_response_code(400);
                 echo json_encode(['error' => "Invalid Due Date format. Use YYYY-MM-DDTHH:MM"]);
                 return;
             }
             if ($field === 'estimatedHours' && (!is_numeric($data[$field]) || floatval($data[$field]) < 0)) {
                 http_response_code(400);
                 echo json_encode(['error' => "Estimated Hours must be a non-negative number."]);
                 return;
             }
             if($field === 'estimatedHours') {
                $updateData[$field] = floatval($data[$field]);
             } elseif ($field === 'title' || $field === 'description') {
                 $updateData[$field] = trim($data[$field]);
             }
             else {
                 $updateData[$field] = $data[$field];
             }
        }
    }

    // Always update the 'updated_at' timestamp
    $updateData['updated_at'] = date('c');

    if (empty($updateData)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields provided for update']);
        return;
    }

    $success = $db->update('tasks', $id, $updateData);

    if ($success) {
        // Fetch the updated task data
        $updatedTasks = $db->query('tasks', ['id' => $id]);
        $updatedTask = !empty($updatedTasks) ? array_values($updatedTasks)[0] : null;
        if ($updatedTask) {
             // Ensure all expected fields are in the response
             $responseTask = array_merge([
                 'id' => $id,
                 'title' => null, 'description' => null, 'assignee' => null,
                 'priority' => null, 'dueDate' => null, 'estimatedHours' => null,
                 'status' => null, 'created_by' => null, 'createdAt' => null, 'updated_at' => null
             ], $updatedTask);
            echo json_encode(['success' => true, 'task' => $responseTask]);
        } else {
            // This case should ideally not happen if update reported success
             error_log("Failed to retrieve task after update with ID: " . $id);
             http_response_code(500); // Or maybe 200 with a warning?
             echo json_encode(['error' => 'Task updated but failed to retrieve updated data']);
        }
    } else {
        // Log the actual error if possible from JsonDatabase class
        error_log("Failed to update task with ID: " . $id);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update task in database']);
    }
}

function handleDelete($db) {
    // DELETE data might come via query string
    $id = $_GET['id'] ?? null;

    // Fallback to request body if needed (though less common for DELETE)
    // if (!$id) {
    //     parse_str(file_get_contents('php://input'), $data);
    //     $id = $data['id'] ?? null;
    // }

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Task ID is required']);
        return;
    }

    // Check if task exists before deleting
    $tasks = $db->query('tasks', ['id' => $id]);
    if (empty($tasks)) {
        // Already deleted or never existed, consider it success for idempotency
        http_response_code(204); // No Content
        return;
    }

    $success = $db->delete('tasks', $id);

    if ($success) {
        http_response_code(204); // No Content - Success, no body needed
    } else {
        error_log("Failed to delete task with ID: " . $id);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete task from database']);
    }
}
// --- API Helper Functions --- END

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tasks - Task Management System</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/dashboard2.css">
    <style>
        /* Add styles for hiding elements */
        .task-item[style*="display: none"] {
            /* You might not need specific styles if display: none is sufficient */
            /* Example: visually indicate hidden items differently if needed */
             /* opacity: 0.5; */
        }
        /* Add other styles as needed */
    </style>
</head>

<body class="dashboard-page">
    <div class="dashboard-layout">
        <!-- Sidebar Navigation -->
        <nav class="sidebar">
            <div class="sidebar-header">
                <h2>Task Manager</h2>
            </div>
            <ul class="nav-links">
                <li>
                    <a href="dashboard.php">
                        <svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z" /></svg>
                        Dashboard
                    </a>
                </li>
                <li class="active">
                    <a href="#tasks"> <!-- Changed href to #tasks to avoid page reload -->
                        <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zM14 7h-4v2h4V7zm0 4h-4v2h4v-2zm-6 4h4v2H8v-2z"/></svg>
                        Tasks
                    </a>
                </li>
                 <li>
                    <a href="teams-view.php">
                         <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        Teams
                    </a>
                </li>
                <li>
                    <a href="projects-view.php">
                        <svg viewBox="0 0 24 24"><path d="M14 10H2v2h12v-2zm0-4H2v2h12V6zM2 16h8v-2H2v2zm19.5-4.5L23 13l-6.99 7-4.51-4.5L13 14l3.01 3 5.49-5.5z"/></svg>
                        Projects
                    </a>
                </li>
                 <li>
                    <a href="departments-view.php">
                        <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                        Departments
                    </a>
                </li>
                <li>
                    <a href="notifications-view.php">
                        <svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"/></svg>
                        Notifications
                    </a>
                </li>
                <li>
                    <a href="users-view.php">
                        <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                        Users
                    </a>
                </li>
                 <li>
                    <a href="reports-view.php">
                         <svg viewBox="0 0 24 24"><path d="M8 5H6v14h12V5H8zm10 12H6V7h12v10zM4 3v18h16V3H4zm14 2v10h-4V5h4zM10 5h4v5h-4V5z"/></svg>
                        Reports
                    </a>
                </li>
                 <li>
                    <a href="dashboard.php#settings">
                        <svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
                        Settings
                    </a>
                </li>
                <li class="logout-item">
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
                    <h1>Tasks</h1>
                </div>
                <div class="header-right">
                    <div class="header-icons">
                         <button id="themeToggle" class="theme-toggle" aria-label="Toggle theme">
                            <svg class="sun-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12 9c-1.65 0-3 1.35-3 3s1.35 3 3 3 3-1.35 3-3-1.35-3-3-3zm0 4c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm8-4h-2.07c-.33-.89-.76-1.71-1.27-2.45l1.47-1.47c.39-.39.39-1.02 0-1.41l-1.41-1.41c-.39-.39-1.02-.39-1.41 0l-1.47 1.47C14.71 4.83 13.89 4.4 13 4.07V2c0-.55-.45-1-1-1s-1 .45-1 1v2.07c-.89.33-1.71.76-2.45 1.27L7.05 3.87c-.39-.39-1.02-.39-1.41 0L4.22 5.29c-.39.39-.39 1.02 0 1.41l1.47 1.47c-.51.74-.94 1.56-1.27 2.45H2c-.55 0-1 .45-1 1s.45 1 1 1h2.07c.33.89.76 1.71 1.27 2.45l-1.47 1.47c-.39.39-.39 1.02 0 1.41l1.41 1.41c.39.39 1.02.39 1.41 0l1.47-1.47c.74.51 1.56.94 2.45 1.27V22c0 .55.45 1 1 1s1-.45 1-1v-2.07c.89-.33 1.71-.76 2.45-1.27l1.47 1.47c.39.39 1.02.39 1.41 0l1.41-1.41c.39-.39.39-1.02 0-1.41l-1.47-1.47c.51-.74.94-1.56 1.27-2.45H22c.55 0 1-.45 1-1s-.45-1-1-1z"/></svg>
                            <svg class="moon-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z"/></svg>
                        </button>
                        <button class="notification-btn" aria-label="View notifications">
                            <svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"/></svg>
                            <span class="notification-badge">3</span>
                        </button>
                        <div class="user-menu-container">
                            <button class="user-menu-btn">
                                <svg class="avatar-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                                <span class="username"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                            </button>
                            <div class="user-dropdown">
                                <a href="settings-view.php" class="dropdown-item">
                                     <svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
                                    Settings
                                </a>
                                <a href="../../views/auth/logout.php" class="dropdown-item">
                                    <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                                    Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Task Management Section -->
            <section class="task-management">
                <div class="section-header">
                    <h2>Task Management</h2>
                    <button class="btn-primary" id="createTaskBtn">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                        Create New Task
                    </button>
                </div>

                <!-- Task Filters -->
                <div class="task-filters">
                    <div class="filter-group">
                        <label for="statusFilter">Status</label>
                        <select id="statusFilter">
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
                        <label for="teamFilter">Team</label>
                        <select id="teamFilter">
                            <option value="all">All Teams</option>
                            <!-- Teams will be populated dynamically by JS -->
                        </select>
                    </div>
                    <div class="search-group">
                        <label for="searchTasks">Search</label> <!-- Added for association -->
                        <input type="text" id="searchTasks" placeholder="Search tasks...">
                    </div>
                </div>

                <!-- Task List -->
                <div class="task-list" id="taskList">
                    <?php if (empty($tasks)) : ?>
                        <p class="no-tasks">No tasks found.</p>
                    <?php else : ?>
                        <?php foreach ($tasks as $task) :
                            // Ensure keys exist to avoid undefined index errors
                            $taskId = $task['id'] ?? 'unknown';
                            $taskTitle = $task['title'] ?? 'No Title';
                            $taskDescription = $task['description'] ?? '';
                            $taskStatus = $task['status'] ?? 'pending';
                            $taskPriority = $task['priority'] ?? 'medium';
                            $taskDueDate = $task['dueDate'] ?? '';
                            $taskAssignee = $task['assignee'] ?? 'Unassigned';
                            $taskEstHours = $task['estimatedHours'] ?? 0; // Needed for edit modal
                        ?>
                        <div class="task-item"
                             data-id="<?php echo htmlspecialchars($taskId); ?>"
                             data-status="<?php echo strtolower(htmlspecialchars($taskStatus)); ?>"
                             data-priority="<?php echo strtolower(htmlspecialchars($taskPriority)); ?>"
                             data-assignee="<?php echo htmlspecialchars($taskAssignee); ?>"
                             data-title="<?php echo htmlspecialchars($taskTitle); ?>"
                             data-description="<?php echo htmlspecialchars($taskDescription); ?>"
                             data-estimated-hours="<?php echo htmlspecialchars($taskEstHours); ?>"
                             >
                            <div class="task-info">
                                <h3 class="task-title"><?php echo htmlspecialchars($taskTitle); ?></h3>
                                <p class="task-description"><?php echo htmlspecialchars($taskDescription); ?></p>
                                <div class="task-meta">
                                    <span class="status-badge status-<?php echo strtolower(str_replace('_', '-', htmlspecialchars($taskStatus))); ?>">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $taskStatus))); ?>
                                    </span>
                                    <span class="priority-<?php echo strtolower(htmlspecialchars($taskPriority)); ?>">
                                        <?php echo ucfirst(htmlspecialchars($taskPriority)); ?> priority
                                    </span>
                                    <span>Due: <?php echo formatDate($taskDueDate); ?></span>
                                    <span>Assigned to: <?php echo htmlspecialchars($taskAssignee); ?></span>
                                </div>
                            </div>
                            <div class="task-actions">
                                <button class="btn-icon edit-task-btn" aria-label="Edit task">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                </button>
                                <button class="btn-icon delete-task-btn" aria-label="Delete task">
                                     <svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <!-- Mobile menu toggle -->
    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
            <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" />
        </svg>
    </button>

    <!-- Create Task Modal -->
    <div class="modal" id="createTaskModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create New Task</h2>
                <button class="close-modal" aria-label="Close modal">×</button>
            </div>
            <form id="createTaskForm" class="modal-body" method="POST" action="tasks-view.php">
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
                             <option value="">Select User</option>
                            <!-- Populated by JS -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="taskPriority">Priority</label>
                        <select id="taskPriority" name="priority" required>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
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
                    <button type="button" class="btn-secondary close-modal-btn">Cancel</button>
                    <button type="submit" class="btn-primary">Create Task</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Task Modal -->
    <div class="modal" id="editTaskModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Task</h2>
                <button class="close-modal" aria-label="Close modal">×</button>
            </div>
            <form id="editTaskForm" class="modal-body" method="POST"> <!-- Method overridden by JS -->
                <input type="hidden" id="editTaskId" name="taskId">
                <div class="form-group">
                    <label for="editTaskTitle">Task Title</label>
                    <input type="text" id="editTaskTitle" name="title" required>
                </div>
                <div class="form-group">
                    <label for="editTaskDescription">Description</label>
                    <textarea id="editTaskDescription" name="description" required></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="editAssignee">Assign To</label>
                        <select id="editAssignee" name="assignee" required>
                            <option value="">Select User</option>
                             <!-- Populated by JS -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editTaskPriority">Priority</label>
                        <select id="editTaskPriority" name="priority" required>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="editDueDate">Due Date</label>
                        <input type="datetime-local" id="editDueDate" name="dueDate" required>
                    </div>
                    <div class="form-group">
                        <label for="editStatus">Status</label>
                        <select id="editStatus" name="status" required>
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>
                 <div class="form-group"> <!-- Moved out of form-row for better spacing -->
                    <label for="editEstimatedHours">Estimated Hours</label>
                    <input type="number" id="editEstimatedHours" name="estimatedHours" min="0" step="0.5" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary close-modal-btn">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../../assets/js/theme.js"></script>
    <script>
        // Helper function to escape HTML
        const escapeHtml = (unsafe) => {
            if (unsafe === null || unsafe === undefined) return '';
            const safeString = String(unsafe);
            return safeString
                .replace(/&/g, "&") // Must be first
                .replace(/</g, "<")
                .replace(/>/g, ">")
                .replace(/"/g, "'")
                .replace(/'/g, "'");
        };

         // Format Date for Display (JS version)
         function formatJsDate(dateString) {
             if (!dateString) return 'N/A';
             try {
                 const date = new Date(dateString);
                 if (isNaN(date.getTime())) return 'Invalid Date'; // Check if date is valid
                 return date.toLocaleDateString('en-US', {
                     month: 'short',
                     day: 'numeric',
                     year: 'numeric'
                 });
             } catch (e) {
                 console.error("Error formatting date:", dateString, e);
                 return 'Date Error';
             }
         }

         // Format Date for datetime-local input
        function formatForDateTimeLocal(dateString) {
            if (!dateString) return '';
            try {
                const date = new Date(dateString);
                 if (isNaN(date.getTime())) return ''; // Check if date is valid
                // Pad month, day, hour, minute if needed
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                return `${year}-${month}-${day}T${hours}:${minutes}`;
            } catch (e) {
                console.error("Error formatting date for input:", dateString, e);
                return '';
            }
        }

        // Basic Modal Handling
        function openModal(modal) {
            if (modal) {
                 modal.style.display = 'flex'; // Use flex for centering defined in CSS
                 document.body.style.overflow = 'hidden'; // Prevent background scrolling
                 document.body.classList.add('modal-open');
            }
        }

        function closeModal(modal) {
             if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto'; // Restore background scrolling
                document.body.classList.remove('modal-open');
             }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const createTaskBtn = document.getElementById('createTaskBtn');
            const createTaskModal = document.getElementById('createTaskModal');
            const editTaskModal = document.getElementById('editTaskModal');
            const taskListContainer = document.getElementById('taskList');
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.querySelector('.sidebar');

            // Filter elements
            const statusFilter = document.getElementById('statusFilter');
            const priorityFilter = document.getElementById('priorityFilter');
            const teamFilter = document.getElementById('teamFilter');
            const searchInput = document.getElementById('searchTasks');

            let usersData = [];
            let teamsData = [];

             // --- Mobile Menu Toggle ---
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', () => {
                    sidebar.classList.toggle('active'); // Or 'show-mobile' depending on your CSS
                });
            }

            // --- Modal Controls ---
            if (createTaskBtn) {
                createTaskBtn.addEventListener('click', () => openModal(createTaskModal));
            }

            document.querySelectorAll('.close-modal').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const modal = e.target.closest('.modal');
                    if(modal) closeModal(modal);
                });
            });
             // Also handle Cancel buttons inside modals
             document.querySelectorAll('.close-modal-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const modal = e.target.closest('.modal');
                    if(modal) closeModal(modal);
                });
            });


            window.addEventListener('click', (event) => {
                if (event.target.classList.contains('modal')) {
                    closeModal(event.target);
                }
            });

             // --- Fetch Initial Data (Users & Teams) ---
             Promise.all([
                 fetch('../../database/json/users.json').then(res => res.ok ? res.json() : Promise.reject(`Users fetch failed: ${res.status}`)),
                 fetch('../../database/json/teams.json').then(res => res.ok ? res.json() : Promise.reject(`Teams fetch failed: ${res.status}`))
             ])
             .then(([users, teams]) => {
                 usersData = users;
                 teamsData = teams;
                 populateUserDropdowns(usersData);
                 populateTeamFilter(teamsData);
                 // Attach filter listeners AFTER data is loaded
                 attachFilterListeners();
                 // Initial filter application if needed (usually not required if default is 'all')
                 // filterTasks();
             })
             .catch(error => {
                console.error("Error fetching initial data:", error);
                alert(`Error loading page data: ${error}. Filtering may not work correctly.`);
                // Still attach listeners so basic filtering might work partially
                 attachFilterListeners();
             });

            // --- Populate Dropdowns ---
            function populateUserDropdowns(users) {
                const assigneeSelects = [
                    document.getElementById('assignee'),
                    document.getElementById('editAssignee')
                ];
                assigneeSelects.forEach(select => {
                    if (!select) return;
                    // Keep the first option (placeholder) if it exists, otherwise add one
                    const firstOption = select.options[0]?.value === "" ? select.options[0] : null;
                    select.innerHTML = ''; // Clear existing options (except maybe placeholder)
                    if (firstOption) {
                         select.appendChild(firstOption);
                    } else {
                        const defaultOption = document.createElement('option');
                        defaultOption.value = '';
                        defaultOption.textContent = 'Select User';
                        defaultOption.disabled = true; // Make it unselectable if needed
                        select.appendChild(defaultOption);
                    }

                    users.forEach(user => {
                         // Optionally filter users (e.g., only active ones)
                         // if (user.status === 'active') {
                            const option = document.createElement('option');
                            option.value = user.username; // Use username as value
                            option.textContent = `${user.firstName || ''} ${user.lastName || ''} (${user.username})`;
                            select.appendChild(option);
                         //}
                    });
                });
            }

            function populateTeamFilter(teams) {
                if (!teamFilter) return;
                teamFilter.innerHTML = '<option value="all">All Teams</option>'; // Reset
                teams.forEach(team => {
                    const option = document.createElement('option');
                    option.value = team.id; // Use team ID as value
                    option.textContent = escapeHtml(team.name);
                    teamFilter.appendChild(option);
                });
            }

             // --- Attach Filter Event Listeners ---
             function attachFilterListeners() {
                 if (statusFilter) statusFilter.addEventListener('change', filterTasks);
                 if (priorityFilter) priorityFilter.addEventListener('change', filterTasks);
                 if (teamFilter) teamFilter.addEventListener('change', filterTasks);
                 if (searchInput) searchInput.addEventListener('input', filterTasks); // 'input' for real-time search
             }

             // --- Filter Tasks Logic ---
             function filterTasks() {
                 if (!taskListContainer) return; // Exit if task list doesn't exist

                 const selectedStatus = statusFilter ? statusFilter.value : 'all';
                 const selectedPriority = priorityFilter ? priorityFilter.value : 'all';
                 const selectedTeamId = teamFilter ? teamFilter.value : 'all';
                 const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';

                 // Get members of the selected team (if a team is selected)
                 let teamMemberUsernames = [];
                 if (selectedTeamId !== 'all' && teamsData.length > 0) {
                     const selectedTeam = teamsData.find(team => team.id === selectedTeamId);
                     // IMPORTANT: Assumes team.members contains an array of USERNAMES.
                     // If it contains USER IDs, you need usersData to map IDs to usernames.
                     if (selectedTeam && Array.isArray(selectedTeam.members)) {
                         teamMemberUsernames = selectedTeam.members;
                         // Example if members were User IDs:
                         // teamMemberUsernames = selectedTeam.members.map(userId => {
                         //     const user = usersData.find(u => u.id === userId);
                         //     return user ? user.username : null;
                         // }).filter(Boolean); // Filter out nulls if a user wasn't found
                     }
                 }

                 const taskItems = taskListContainer.querySelectorAll('.task-item');

                 if (taskItems.length === 0) {
                     // Handle case where task list might be empty
                     // console.log("No tasks to filter.");
                     return;
                 }


                 taskItems.forEach(item => {
                     const itemStatus = item.dataset.status || '';
                     const itemPriority = item.dataset.priority || '';
                     const itemAssignee = item.dataset.assignee || '';
                     const itemTitle = (item.dataset.title || '').toLowerCase();
                     const itemDescription = (item.dataset.description || '').toLowerCase();

                     // Check filter conditions
                     const statusMatch = selectedStatus === 'all' || itemStatus === selectedStatus;
                     const priorityMatch = selectedPriority === 'all' || itemPriority === selectedPriority;
                     const teamMatch = selectedTeamId === 'all' || teamMemberUsernames.includes(itemAssignee);
                     const searchMatch = searchTerm === '' || itemTitle.includes(searchTerm) || itemDescription.includes(searchTerm);

                     // Show or hide based on ALL conditions being met
                     if (statusMatch && priorityMatch && teamMatch && searchMatch) {
                         item.style.display = ''; // Show item (use default display)
                     } else {
                         item.style.display = 'none'; // Hide item
                     }
                 });
             }


            // --- Form Submissions (AJAX) ---
            const createTaskForm = document.getElementById('createTaskForm');
            if (createTaskForm) {
                createTaskForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    const submitButton = this.querySelector('button[type="submit"]');
                    submitButton.disabled = true; // Prevent double submission

                    // Basic client-side validation example
                    if (!formData.get('title') || !formData.get('description') || !formData.get('dueDate') || !formData.get('assignee') || !formData.get('estimatedHours')) {
                         alert('Please fill in all required fields.');
                         submitButton.disabled = false;
                         return;
                     }

                    fetch('tasks-view.php', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest' // Identify as AJAX
                        },
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            // Try to parse error JSON, otherwise use status text
                            return response.json().then(errData => {
                                throw new Error(errData.error || `HTTP error ${response.status}`);
                            }).catch(() => {
                                throw new Error(`HTTP error ${response.status} - ${response.statusText}`);
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success && data.task) {
                            addTaskToDOM(data.task); // Add the new task visually
                            this.reset(); // Reset the form
                            closeModal(createTaskModal);
                            alert('Task created successfully!');
                        } else {
                            // Use error from response if available
                            alert('Error creating task: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while creating the task: ' + error.message);
                    })
                    .finally(() => {
                         submitButton.disabled = false; // Re-enable button
                    });
                });
            }

            const editTaskForm = document.getElementById('editTaskForm');
            if (editTaskForm) {
                editTaskForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    const taskId = formData.get('taskId');
                    const submitButton = this.querySelector('button[type="submit"]');
                    submitButton.disabled = true;

                    if (!taskId) {
                        alert('Error: Task ID is missing.');
                        submitButton.disabled = false;
                        return;
                    }

                    // Convert FormData to URLSearchParams for PUT request body
                    const urlEncodedData = new URLSearchParams(formData).toString();

                    fetch(`tasks-view.php`, { // No ID in URL for PUT here, it's in body
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: urlEncodedData // Includes taskId=...
                    })
                    .then(response => {
                         if (!response.ok) {
                            return response.json().then(errData => {
                                throw new Error(errData.error || `HTTP error ${response.status}`);
                            }).catch(() => {
                                throw new Error(`HTTP error ${response.status} - ${response.statusText}`);
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success && data.task) {
                            updateTaskInDOM(data.task); // Update the task visually
                            closeModal(editTaskModal);
                            alert('Task updated successfully!');
                        } else {
                            alert('Error updating task: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while updating the task: ' + error.message);
                    })
                     .finally(() => {
                         submitButton.disabled = false; // Re-enable button
                    });
                });
            }

             // --- Task Actions (Edit/Delete) Event Delegation ---
             if (taskListContainer) {
                taskListContainer.addEventListener('click', function(e) {
                    const editButton = e.target.closest('.edit-task-btn');
                    const deleteButton = e.target.closest('.delete-task-btn');
                    const taskItem = e.target.closest('.task-item');

                    if (!taskItem) return;
                    const taskId = taskItem.dataset.id;

                    if (editButton && taskId) {
                        handleEditTask(taskItem); // Populate and open edit modal
                    }

                    if (deleteButton && taskId) {
                        if (confirm('Are you sure you want to delete this task?')) {
                            fetch(`tasks-view.php?id=${encodeURIComponent(taskId)}`, { // Send ID via query param for DELETE
                                method: 'DELETE',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            })
                            .then(response => {
                                if (response.ok && response.status === 204) { // 204 No Content is success for DELETE
                                    taskItem.remove(); // Remove from UI
                                    alert('Task deleted successfully!');
                                     // Optionally, re-apply filters if needed, though removing should be fine.
                                     // filterTasks();
                                } else if (!response.ok) {
                                    // Try to parse error JSON
                                    return response.json().then(errData => {
                                         throw new Error(errData.error || `Failed to delete: ${response.statusText}`);
                                    });
                                } else {
                                    // Handle unexpected success codes if necessary (e.g., 200 OK with body)
                                    taskItem.remove(); // Assume success if OK but not 204
                                     alert('Task deleted (unexpected status).');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('An error occurred while deleting the task: ' + error.message);
                            });
                        }
                    }
                });
            }

            // --- Function to handle opening the Edit Modal ---
            function handleEditTask(taskItem) {
                 if (!editTaskModal || !taskItem) return;

                 const taskId = taskItem.dataset.id;
                 const taskTitle = taskItem.dataset.title;
                 const taskDescription = taskItem.dataset.description;
                 const taskAssignee = taskItem.dataset.assignee;
                 const taskPriority = taskItem.dataset.priority;
                 const taskStatus = taskItem.dataset.status;
                 const taskEstHours = taskItem.dataset.estimatedHours;

                 // Find the original due date (might need fetching if not stored)
                 // Let's try getting it from the displayed text first as a fallback
                 let dueDateISO = '';
                 const dueDateElement = taskItem.querySelector('.task-meta span:nth-child(3)'); // Assuming 3rd span is Due Date
                 const dueDateText = dueDateElement ? dueDateElement.textContent.replace('Due: ', '').trim() : '';
                 if (dueDateText && dueDateText !== 'N/A') {
                     // Attempt to parse the displayed date back to something datetime-local can use
                     // This is brittle; ideally, store the full ISO date in another data attribute
                     // Example: data-due-date="YYYY-MM-DDTHH:MM:SSZ"
                     try {
                          // We need the full date string from the original task data for accuracy
                          // Let's assume we *need* to fetch full task data if not available
                          // For now, we'll just use the stored data attributes
                          // Fetching the full date would require an API endpoint like GET /tasks/{id}
                          // Example: Fetch `dueDate` from original $task array if possible
                          // For now, set a placeholder or leave blank if not easily available
                           // dueDateISO = formatForDateTimeLocal(taskItem.dataset.dueDateRaw); // Requires adding data-due-date-raw
                           const placeholderDate = new Date(); // Or leave empty
                           dueDateISO = formatForDateTimeLocal(placeholderDate.toISOString());
                           console.warn("Due date in edit modal might be inaccurate. Store full ISO date in data attribute.");

                     } catch (e) { console.error("Could not parse date for edit:", dueDateText); }
                 }


                 // Populate edit form
                 const editForm = editTaskModal.querySelector('#editTaskForm');
                 editForm.querySelector('#editTaskId').value = taskId;
                 editForm.querySelector('#editTaskTitle').value = taskTitle || '';
                 editForm.querySelector('#editTaskDescription').value = taskDescription || '';
                 editForm.querySelector('#editAssignee').value = taskAssignee || '';
                 editForm.querySelector('#editTaskPriority').value = taskPriority || 'medium';
                 editForm.querySelector('#editStatus').value = taskStatus || 'pending';
                 editForm.querySelector('#editEstimatedHours').value = taskEstHours || '0';
                 editForm.querySelector('#editDueDate').value = dueDateISO; // Use formatted date


                 openModal(editTaskModal);
            }

            // --- Function to add a new task row to the DOM ---
            function addTaskToDOM(task) {
                 if (!taskListContainer || !task || !task.id) return;

                  // Remove "No tasks found" message if it exists
                 const noTasksMessage = taskListContainer.querySelector('.no-tasks');
                 if (noTasksMessage) {
                     noTasksMessage.remove();
                 }


                 const statusClass = task.status ? task.status.replace('_', '-') : 'pending';
                 const priorityClass = task.priority ? task.priority.toLowerCase() : 'medium';
                 const priorityLabel = task.priority ? task.priority.charAt(0).toUpperCase() + task.priority.slice(1) : 'Medium';

                 const newTaskHtml = `
                     <div class="task-item"
                          data-id="${escapeHtml(task.id)}"
                          data-status="${escapeHtml(task.status?.toLowerCase())}"
                          data-priority="${escapeHtml(task.priority?.toLowerCase())}"
                          data-assignee="${escapeHtml(task.assignee)}"
                          data-title="${escapeHtml(task.title)}"
                          data-description="${escapeHtml(task.description)}"
                          data-estimated-hours="${escapeHtml(task.estimatedHours)}"
                          >
                         <div class="task-info">
                             <h3 class="task-title">${escapeHtml(task.title)}</h3>
                             <p class="task-description">${escapeHtml(task.description)}</p>
                             <div class="task-meta">
                                 <span class="status-badge status-${statusClass}">
                                     ${escapeHtml(task.status ? task.status.replace('_', ' ') : 'Pending')}
                                 </span>
                                 <span class="priority-${priorityClass}">
                                     ${priorityLabel} priority
                                 </span>
                                 <span>Due: ${formatJsDate(task.dueDate)}</span>
                                 <span>Assigned to: ${escapeHtml(task.assignee || 'Unassigned')}</span>
                             </div>
                         </div>
                         <div class="task-actions">
                             <button class="btn-icon edit-task-btn" aria-label="Edit task">
                                  <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                             </button>
                             <button class="btn-icon delete-task-btn" aria-label="Delete task">
                                  <svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                             </button>
                         </div>
                     </div>
                 `;
                 // Prepend to the list
                 taskListContainer.insertAdjacentHTML('afterbegin', newTaskHtml);

                 // Re-apply filters to ensure the new task is shown/hidden correctly
                 filterTasks();
            }

            // --- Function to update an existing task row in the DOM ---
            function updateTaskInDOM(task) {
                 if (!taskListContainer || !task || !task.id) return;

                 const taskItem = taskListContainer.querySelector(`.task-item[data-id="${task.id}"]`);
                 if (!taskItem) return; // Task not found in DOM

                  const statusClass = task.status ? task.status.replace('_', '-') : 'pending';
                 const priorityClass = task.priority ? task.priority.toLowerCase() : 'medium';
                 const priorityLabel = task.priority ? task.priority.charAt(0).toUpperCase() + task.priority.slice(1) : 'Medium';

                 // Update data attributes
                 taskItem.dataset.status = task.status?.toLowerCase() || 'pending';
                 taskItem.dataset.priority = task.priority?.toLowerCase() || 'medium';
                 taskItem.dataset.assignee = task.assignee || 'Unassigned';
                 taskItem.dataset.title = task.title || '';
                 taskItem.dataset.description = task.description || '';
                 taskItem.dataset.estimatedHours = task.estimatedHours || '0';
                 // taskItem.dataset.dueDateRaw = task.dueDate; // Add this if you store the raw date

                 // Update displayed content
                 taskItem.querySelector('.task-title').textContent = escapeHtml(task.title);
                 taskItem.querySelector('.task-description').textContent = escapeHtml(task.description);

                 const metaSpans = taskItem.querySelectorAll('.task-meta span');
                 if (metaSpans.length >= 4) {
                     const statusBadge = metaSpans[0];
                     statusBadge.className = `status-badge status-${statusClass}`;
                     statusBadge.textContent = escapeHtml(task.status ? ucWords(task.status.replace('_', ' ')) : 'Pending');

                     const priorityBadge = metaSpans[1];
                     priorityBadge.className = `priority-${priorityClass}`;
                     priorityBadge.textContent = `${priorityLabel} priority`;

                     metaSpans[2].textContent = `Due: ${formatJsDate(task.dueDate)}`;
                     metaSpans[3].textContent = `Assigned to: ${escapeHtml(task.assignee || 'Unassigned')}`;
                 }

                 // Re-apply filters to ensure visibility is correct
                  filterTasks();
            }

            // Helper for title case
            function ucWords(str) {
                return str.toLowerCase().replace(/^(.)|\s+(.)/g, function ($1) {
                    return $1.toUpperCase();
                });
            }

        }); // End DOMContentLoaded
    </script>

</body>
</html>