<?php
// --- Error Reporting (Suppress direct output for AJAX, log errors in production) ---
error_reporting(0);
@ini_set('display_errors', 0);

// Start session and require necessary files
session_start();
require_once __DIR__ . '/../../php/config/JsonDatabase.php'; // Make sure this path is correct

// --- Configuration ---
$projectsJsonFilePath = __DIR__ . '/../../database/json/projects.json'; // Path to projects.json
$usersJsonFilePath = __DIR__ . '/../../data/users.json';       // Path to users.json

// --- Helper Functions ---
function send_json_response($data, $statusCode = 200) {
    if (!headers_sent()) {
         header('Content-Type: application/json');
         http_response_code($statusCode);
    }
    echo json_encode($data);
    exit;
}

function generate_unique_id($prefix = 'proj') {
    return $prefix . uniqid();
}

// --- Handle AJAX Data Modification Requests ---
$requestMethod = $_SERVER['REQUEST_METHOD'];
$isAjaxRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Only process data modifications if it's an AJAX request AND user is authorized
if ($isAjaxRequest && isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    $jsonDb = JsonDatabase::getInstance();
    $inputData = json_decode(file_get_contents('php://input'), true);

    // --- POST Request (Create New Project) ---
    if ($requestMethod === 'POST') {
        // Stricter Validation
        if (empty($inputData['name'])) {
            send_json_response(['success' => false, 'message' => 'Project name is required'], 400);
        }

        $allData = $jsonDb->read($projectsJsonFilePath);
        $projects = $allData['projects'] ?? [];

        // Process team members - expect an array of IDs from multi-select
        $teamMembers = [];
        if (!empty($inputData['team_members']) && is_array($inputData['team_members'])) {
             $teamMembers = array_map('intval', $inputData['team_members']);
        }

        $newProject = [
            'id' => generate_unique_id(),
            'name' => isset($inputData['name']) ? htmlspecialchars(trim($inputData['name'])) : 'Unnamed Project',
            'description' => isset($inputData['description']) ? htmlspecialchars(trim($inputData['description'])) : '',
            'status' => isset($inputData['status']) ? $inputData['status'] : 'Planning',
            'start_date' => !empty($inputData['start_date']) ? $inputData['start_date'] : null,
            'end_date' => !empty($inputData['end_date']) ? $inputData['end_date'] : null,
            'budget' => isset($inputData['budget']) && is_numeric($inputData['budget']) ? (float)$inputData['budget'] : 0,
            'department_id' => isset($inputData['department_id']) ? htmlspecialchars(trim($inputData['department_id'])) : null,
            'manager_id' => isset($inputData['manager_id']) ? (int)$inputData['manager_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null),
            'team_members' => $teamMembers,
            'progress' => isset($inputData['progress']) && is_numeric($inputData['progress']) ? max(0, min(100, (int)$inputData['progress'])) : 0,
            'priority' => isset($inputData['priority']) ? $inputData['priority'] : 'medium',
        ];

        $projects[] = $newProject;
        $allData['projects'] = $projects;

        if ($jsonDb->write($projectsJsonFilePath, $allData)) {
            send_json_response(['success' => true, 'message' => 'Project added successfully', 'project' => $newProject]);
        } else {
            send_json_response(['success' => false, 'message' => 'Failed to save project data'], 500);
        }
    }

    // --- PUT Request (Update Existing Project) ---
    elseif ($requestMethod === 'PUT') {
        if (empty($inputData['projectId']) || empty($inputData['name'])) {
             send_json_response(['success' => false, 'message' => 'Project ID and name are required'], 400);
        }

        $projectIdToUpdate = $inputData['projectId'];
        $allData = $jsonDb->read($projectsJsonFilePath);
        $projects = $allData['projects'] ?? [];
        $updated = false;
        $updatedProjectData = null;

        foreach ($projects as $index => $project) {
            if (isset($project['id']) && $project['id'] === $projectIdToUpdate) {
                $teamMembers = $project['team_members'] ?? [];
                if (isset($inputData['team_members']) && is_array($inputData['team_members'])) {
                     $teamMembers = array_map('intval', $inputData['team_members']);
                } elseif (isset($inputData['team_members']) && $inputData['team_members'] === null) {
                     $teamMembers = [];
                }

                $projects[$index]['name'] = htmlspecialchars(trim($inputData['name']));
                $projects[$index]['description'] = isset($inputData['description']) ? htmlspecialchars(trim($inputData['description'])) : ($project['description'] ?? '');
                $projects[$index]['status'] = $inputData['status'] ?? $project['status'];
                $projects[$index]['start_date'] = !empty($inputData['start_date']) ? $inputData['start_date'] : $project['start_date'];
                $projects[$index]['end_date'] = !empty($inputData['end_date']) ? $inputData['end_date'] : $project['end_date'];
                $projects[$index]['budget'] = isset($inputData['budget']) && is_numeric($inputData['budget']) ? (float)$inputData['budget'] : $project['budget'];
                $projects[$index]['department_id'] = isset($inputData['department_id']) ? htmlspecialchars(trim($inputData['department_id'])) : $project['department_id'];
                $projects[$index]['manager_id'] = isset($inputData['manager_id']) ? (int)$inputData['manager_id'] : $project['manager_id'];
                $projects[$index]['team_members'] = $teamMembers;
                $projects[$index]['progress'] = isset($inputData['progress']) && is_numeric($inputData['progress']) ? max(0, min(100, (int)$inputData['progress'])) : $project['progress'];
                $projects[$index]['priority'] = $inputData['priority'] ?? $project['priority'];

                $updated = true;
                $updatedProjectData = $projects[$index];
                break;
            }
        }

        if ($updated) {
            $allData['projects'] = $projects;
            if ($jsonDb->write($projectsJsonFilePath, $allData)) {
                send_json_response(['success' => true, 'message' => 'Project updated successfully', 'project' => $updatedProjectData]);
            } else {
                send_json_response(['success' => false, 'message' => 'Failed to save updated project data'], 500);
            }
        } else {
            send_json_response(['success' => false, 'message' => 'Project not found for update'], 404);
        }
    }

    // --- DELETE Request ---
    elseif ($requestMethod === 'DELETE') {
        if (empty($inputData['id'])) { send_json_response(['success' => false, 'message' => 'Project ID is required'], 400); }
        $projectIdToDelete = $inputData['id'];
        $allData = $jsonDb->read($projectsJsonFilePath); $projects = $allData['projects'] ?? []; $initialCount = count($projects);
        $projects = array_filter($projects, fn($p) => !isset($p['id']) || $p['id'] !== $projectIdToDelete); $projects = array_values($projects);
        if (count($projects) < $initialCount) {
            $allData['projects'] = $projects;
            if ($jsonDb->write($projectsJsonFilePath, $allData)) { send_json_response(['success' => true, 'message' => 'Project deleted successfully']); }
            else { send_json_response(['success' => false, 'message' => 'Failed to save data after deleting project'], 500); }
        } else { send_json_response(['success' => false, 'message' => 'Project not found for deletion'], 404); }
    }

    // --- GET Request for single item (Fetch for Edit) ---
    elseif ($requestMethod === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'project' && isset($_GET['id'])) {
        $projectId = $_GET['id'];
        $allData = $jsonDb->read($projectsJsonFilePath); $projects = $allData['projects'] ?? []; $foundProject = null;
        foreach ($projects as $project) { if (isset($project['id']) && $project['id'] === $projectId) { $foundProject = $project; break; } }
        if ($foundProject) { send_json_response($foundProject); } else { send_json_response(['success' => false, 'message' => 'Project not found'], 404); }
    }
}

// --- Standard Page Load Logic ---
// Auth check for page view
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Load Projects Data
$jsonDb = JsonDatabase::getInstance();
$projects = $jsonDb->query('projects')['projects'] ?? [];

// Load Users Data for the dropdown
$users = [];
$usersJsonFilePath = __DIR__ . '/../../database/json/users.json';
if (file_exists($usersJsonFilePath)) {
    $usersDataJson = file_get_contents($usersJsonFilePath);
    $usersData = json_decode($usersDataJson, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($usersData)) {
         $users = $usersData;
    } else {
        error_log("Failed to decode users.json or it's not an array. Error: " . json_last_error_msg());
        $users = [];
    }
} else {
    error_log("users.json file not found at path: " . $usersJsonFilePath);
    $users = [];
}

// Load Teams Data for the team members dropdown
$teams = [];
$teamsJsonFilePath = __DIR__ . '/../../database/json/teams.json';
if (file_exists($teamsJsonFilePath)) {
    $teamsDataJson = file_get_contents($teamsJsonFilePath);
    $teamsData = json_decode($teamsDataJson, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($teamsData)) {
        $teams = $teamsData;
    } else {
        error_log("Failed to decode teams.json or it's not an array. Error: " . json_last_error_msg());
        $teams = [];
    }
} else {
    error_log("teams.json file not found at path: " . $teamsJsonFilePath);
    $teams = [];
}

// Date formatting function
if (!function_exists('formatDate')) {
    function formatDate($date) {
        return (!empty($date) && strtotime($date) !== false) ? date('M d, Y', strtotime($date)) : 'N/A';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projects - Admin Dashboard</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .modal-body { max-height: 70vh; overflow-y: auto; padding-right: 15px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        .form-group label { display: block; margin-bottom: .3rem; font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: .5rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .form-group textarea { resize: vertical; }
        .form-group select[multiple] { height: auto; min-height: 120px; }
        .mb-2 { margin-bottom: 0.5rem; }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.875rem; }
        .form-text { display: block; margin-top: 0.25rem; font-size: 0.875rem; color: #6c757d; }
    </style>
</head>
<body class="dashboard-page">
    <div class="dashboard-layout">
        <nav class="sidebar">
             <div class="sidebar-header">
                 <h2>Task Manager</h2>
             </div>
             <ul class="nav-links">
                 <li><a href="dashboard.php"><svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg> Dashboard</a></li>
                 <li><a href="tasks-view.php"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z"/></svg> Tasks</a></li>
                 <li><a href="teams-view.php"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg> Teams</a></li>
                 <li class="active"><a href="projects-view.php"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zM7 10h5v5H7z"/></svg> Projects</a></li>
                 <li><a href="departments-view.php"><svg viewBox="0 0 24 24"><path d="M12 2L4.5 20.29l.71.71L12 18l6.79 3 .71-.71z"/></svg> Departments</a></li>
                 <li><a href="notifications-view.php"><svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"/></svg> Notifications</a></li>
                 <li><a href="users-view.php"><svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg> Users</a></li>
                 <li><a href="reports-view.php"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg> Reports</a></li>
                 <li><a href="dashboard.php#settings"><svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg> Settings</a></li>
                 <li class="logout-item"><a href="../../views/auth/logout.php"><svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg> Logout</a></li>
             </ul>
         </nav>

        <main class="main-content">
             <header class="content-header">
                 <div class="header-left">
                     <h1>Projects</h1>
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
                          <div class="user-menu-container">
                              <button class="user-menu-btn">
                                  <svg class="avatar-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                                  <span class="username"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                              </button>
                               <div class="user-dropdown">
                                   <a href="#settings" class="dropdown-item"><svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg> Settings</a>
                                   <a href="../../views/auth/logout.php" class="dropdown-item"><svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg> Logout</a>
                               </div>
                          </div>
                      </div>
                 </div>
            </header>

            <section class="projects-section" id="projects">
                <div class="section-header">
                    <h2>Project Management</h2>
                    <button class="btn-primary" id="addProjectBtn">
                        <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" /></svg>
                        Add Project
                    </button>
                </div>
                <div class="projects-grid">
                    <?php if (empty($projects)) : ?>
                         <div class="no-items-container">
                             <svg class="no-items-icon" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M19.5 2h-15A2.5 2.5 0 002 4.5v15A2.5 2.5 0 004.5 22h15a2.5 2.5 0 002.5-2.5v-15A2.5 2.5 0 0019.5 2zM4.5 4h15a.5.5 0 01.5.5v15a.5.5 0 01-.5.5h-15a.5.5 0 01-.5-.5v-15a.5.5 0 01.5-.5zm5.72 13.28a.75.75 0 01-1.06-1.06l3-3a.75.75 0 011.06 0l3 3a.75.75 0 01-1.06 1.06L12 13.06l-1.78 1.78v-.01zM12 10.75a.75.75 0 01-.75-.75V5a.75.75 0 011.5 0v5a.75.75 0 01-.75.75z"/></svg>
                             <p class="no-items-message">No projects found.</p>
                             <p class="no-items-suggestion">Click "Add Project" above to get started.</p>
                         </div>
                    <?php else : ?>
                        <?php foreach ($projects as $project) : ?>
                            <div class="project-card" data-id="<?php echo htmlspecialchars($project['id'] ?? ''); ?>">
                                <div class="project-header">
                                    <h3><?php echo htmlspecialchars($project['name'] ?? 'Unnamed Project'); ?></h3>
                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $project['status'] ?? 'unknown')); ?>">
                                        <?php echo htmlspecialchars($project['status'] ?? 'Unknown'); ?>
                                    </span>
                                </div>
                                <p class="project-description"><?php echo htmlspecialchars($project['description'] ?? 'No description'); ?></p>
                                <div class="project-meta">
                                    <div class="progress-bar">
                                        <div class="progress" style="width: <?php echo $project['progress'] ?? 0; ?>%"></div>
                                    </div>
                                    <div class="meta-info">
                                        <span>Budget: $<?php echo number_format($project['budget'] ?? 0); ?></span>
                                        <span>Start: <?php echo formatDate($project['start_date'] ?? ''); ?></span>
                                        <span>End: <?php echo formatDate($project['end_date'] ?? ''); ?></span>
                                        <span>Priority: <?php echo ucfirst(htmlspecialchars($project['priority'] ?? 'N/A')); ?></span>
                                    </div>
                                </div>
                                <div class="project-actions">
                                    <button class="btn-icon edit-project-btn" data-project-id="<?php echo htmlspecialchars($project['id'] ?? ''); ?>" aria-label="Edit project">
                                        <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" /></svg>
                                    </button>
                                    <button class="btn-icon delete-project-btn" data-project-id="<?php echo htmlspecialchars($project['id'] ?? ''); ?>" aria-label="Delete project">
                                        <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" /></svg>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <div class="modal" id="projectModal">
                 <div class="modal-content">
                    <div class="modal-header">
                        <h2 id="projectModalTitle">Add New Project</h2>
                        <button class="close-modal" aria-label="Close modal">×</button>
                    </div>
                    <form id="projectForm" class="modal-body">
                        <input type="hidden" id="projectId" name="projectId">

                        <div class="form-group">
                            <label for="projectName">Project Name *</label>
                            <input type="text" id="projectName" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="projectDescription">Description</label>
                            <textarea id="projectDescription" name="description" rows="3"></textarea>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="projectStatus">Status</label>
                                <select id="projectStatus" name="status">
                                    <option value="Planning" selected>Planning</option>
                                    <option value="Active">Active</option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Completed">Completed</option>
                                    <option value="On Hold">On Hold</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="projectPriority">Priority</label>
                                <select id="projectPriority" name="priority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="projectProgress">Progress (%)</label>
                                <input type="number" id="projectProgress" name="progress" min="0" max="100" step="1" value="0">
                            </div>
                            <div class="form-group">
                                <label for="projectBudget">Budget ($)</label>
                                <input type="number" id="projectBudget" name="budget" min="0" step="0.01" placeholder="e.g., 50000">
                            </div>
                            <div class="form-group">
                                <label for="projectStartDate">Start Date</label>
                                <input type="date" id="projectStartDate" name="start_date">
                            </div>
                            <div class="form-group">
                                <label for="projectEndDate">End Date</label>
                                <input type="date" id="projectEndDate" name="end_date">
                            </div>
                            <div class="form-group">
                                <label for="projectManagerId">Manager</label>
                                <select id="projectManagerId" name="manager_id">
                                    <option value="">Select Manager</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo htmlspecialchars($user['id']); ?>">
                                            <?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName'] . ' (' . $user['role'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="projectDepartmentId">Department ID</label>
                                <input type="text" id="projectDepartmentId" name="department_id" placeholder="e.g., dept1">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="projectTeamMembers">Team Members</label>
                            <select id="projectTeamMembers" name="team_members[]" multiple class="form-control">
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo htmlspecialchars($team['id']); ?>">
                                        <?php echo htmlspecialchars($team['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text">Hold Ctrl (or Cmd on Mac) to select multiple teams</small>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn-secondary close-modal">Cancel</button>
                            <button type="submit" class="btn-primary" id="projectSubmitBtn">Add Project</button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>
    <script src="../../assets/js/theme.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const projectModal = document.getElementById('projectModal');
            const projectForm = document.getElementById('projectForm');
            const projectGrid = document.querySelector('.projects-grid');
            const projectModalTitle = document.getElementById('projectModalTitle');
            const projectSubmitBtn = document.getElementById('projectSubmitBtn');
            const projectIdInput = document.getElementById('projectId');
            const teamMembersSelect = document.getElementById('projectTeamMembers');
            
            // --- Modal Handling ---
            function openModal(mode = 'add', projectData = null) {
                projectForm.reset();
                
                // Reset team members selection
                if (teamMembersSelect) {
                    Array.from(teamMembersSelect.options).forEach(option => {
                        option.selected = false;
                    });
                }

                document.getElementById('projectStatus').value = 'Planning';
                document.getElementById('projectPriority').value = 'medium';
                document.getElementById('projectProgress').value = 0;
                document.getElementById('projectBudget').value = '';
                document.getElementById('projectStartDate').value = '';
                document.getElementById('projectEndDate').value = '';
                document.getElementById('projectManagerId').value = '';
                document.getElementById('projectDepartmentId').value = '';

                if (mode === 'add') {
                    projectModalTitle.textContent = 'Add New Project';
                    projectSubmitBtn.textContent = 'Add Project';
                    projectIdInput.value = '';
                } else if (mode === 'edit' && projectData) {
                    projectModalTitle.textContent = 'Edit Project';
                    projectSubmitBtn.textContent = 'Save Changes';
                    projectIdInput.value = projectData.id;

                    document.getElementById('projectName').value = projectData.name || '';
                    document.getElementById('projectDescription').value = projectData.description || '';
                    document.getElementById('projectStatus').value = projectData.status || 'Planning';
                    document.getElementById('projectPriority').value = projectData.priority || 'medium';
                    document.getElementById('projectProgress').value = projectData.progress || 0;
                    document.getElementById('projectBudget').value = projectData.budget || '';
                    document.getElementById('projectStartDate').value = projectData.start_date || '';
                    document.getElementById('projectEndDate').value = projectData.end_date || '';
                    document.getElementById('projectManagerId').value = projectData.manager_id || '';
                    document.getElementById('projectDepartmentId').value = projectData.department_id || '';

                    // Populate team members selection
                    if (teamMembersSelect && projectData.team_members && Array.isArray(projectData.team_members)) {
                        Array.from(teamMembersSelect.options).forEach(option => {
                            option.selected = projectData.team_members.includes(parseInt(option.value));
                        });
                    }
                } else {
                    console.error("Invalid modal mode or missing data for edit.");
                    return;
                }

                if (projectModal) {
                    projectModal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeModal() {
                if (projectModal) {
                    projectModal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                    projectForm.reset();
                }
            }

            document.getElementById('addProjectBtn')?.addEventListener('click', () => openModal('add'));
            projectModal.querySelectorAll('.close-modal, .btn-secondary.close-modal').forEach(btn => btn.addEventListener('click', closeModal));
            projectModal.addEventListener('click', (e) => { if (e.target === projectModal) closeModal(); });

            async function handleProjectRequest(method, body = null, queryParams = '') {
                const url = `projects-view.php${queryParams}`;
                try {
                    const options = {
                        method: method,
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: (body && (method === 'POST' || method === 'PUT' || method === 'DELETE')) ? JSON.stringify(body) : null
                    };
                    const response = await fetch(url, options);
                    let responseData;
                    const contentType = response.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        responseData = await response.json();
                    } else {
                        const text = await response.text();
                        console.error("Non-JSON response received:", text.substring(0, 200));
                        throw new Error(`Non-JSON response received. Check server logs.`);
                    }

                    if (!response.ok) {
                        throw new Error(responseData.message || `HTTP error! Status: ${response.status}`);
                    }
                    return responseData;
                } catch (error) {
                    console.error('API Request Error:', error);
                    const displayError = error.message.startsWith("Non-JSON response") ? error.message : `Error: ${error.message}`;
                    alert(displayError);
                    return null;
                }
            }

            projectForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(projectForm);
                const projectData = {};
                formData.forEach((value, key) => {
                    // Handle multiple selections for team_members
                    if (key === 'team_members[]') {
                        if (!projectData['team_members']) {
                            projectData['team_members'] = [];
                        }
                        projectData['team_members'].push(value);
                    } else {
                        projectData[key] = value;
                    }
                });

                const isEditing = !!projectData.projectId;
                const method = isEditing ? 'PUT' : 'POST';

                console.log(`Submitting project (${isEditing ? 'Edit' : 'Add'}):`, projectData);
                const result = await handleProjectRequest(method, projectData);

                if (result && result.success) {
                    alert(`Project successfully ${isEditing ? 'updated' : 'added'}!`);
                    closeModal();
                    location.reload();
                } else {
                    console.error('Failed to save project.');
                }
            });

            projectGrid.addEventListener('click', async (e) => {
                if (e.target.closest('.edit-project-btn')) {
                    const btn = e.target.closest('.edit-project-btn'); const projectId = btn.dataset.projectId;
                    console.log('Attempting to edit project:', projectId);
                    const project = await handleProjectRequest('GET', null, `?fetch=project&id=${projectId}`);
                    if (project?.id) { openModal('edit', project); }
                    else { console.error("Could not fetch project details:", project?.message); }
                }
                else if (e.target.closest('.delete-project-btn')) {
                    const btn = e.target.closest('.delete-project-btn'); const projectId = btn.dataset.projectId; const projectCard = btn.closest('.project-card');
                    if (confirm('Are you sure you want to delete this project?')) {
                        console.log('Attempting to delete project:', projectId);
                        const result = await handleProjectRequest('DELETE', { id: projectId });
                        if (result && result.success) {
                            alert('Project successfully deleted!'); projectCard?.remove();
                            if (!projectGrid.querySelector('.project-card')) {
                                projectGrid.innerHTML = `<div class="no-items-container"><svg class="no-items-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M19.5 2h-15A2.5 2.5 0 002 4.5v15A2.5 2.5 0 004.5 22h15a2.5 2.5 0 002.5-2.5v-15A2.5 2.5 0 0019.5 2zM4.5 4h15a.5.5 0 01.5.5v15a.5.5 0 01-.5.5h-15a.5.5 0 01-.5-.5v-15a.5.5 0 01.5-.5zm5.72 13.28a.75.75 0 01-1.06-1.06l3-3a.75.75 0 011.06 0l3 3a.75.75 0 01-1.06 1.06L12 13.06l-1.78 1.78v-.01zM12 10.75a.75.75 0 01-.75-.75V5a.75.75 0 011.5 0v5a.75.75 0 01-.75.75z"/></svg><p class="no-items-message">No projects found.</p><p class="no-items-suggestion">Click "Add Project" above to get started.</p></div>`;
                            }
                        } else { console.error('Failed to delete project.'); }
                    }
                }
            });

            const userMenuBtn = document.querySelector('.user-menu-btn');
            const userDropdown = document.querySelector('.user-dropdown');
            if (userMenuBtn && userDropdown) {
                userMenuBtn.addEventListener('click', (e) => { e.stopPropagation(); userDropdown.classList.toggle('show'); });
                document.addEventListener('click', (e) => { if (!userMenuBtn.contains(e.target) && !userDropdown.contains(e.target)) userDropdown.classList.remove('show'); });
            }
        });
    </script>
</body>
</html>