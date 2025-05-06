<?php
session_start();
require_once __DIR__ . '/../../php/config/JsonDatabase.php';

// Security check: Ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit;
    } else {
        header('Location: ../../views/auth/login.php');
        exit;
    }
}

$jsonDb = JsonDatabase::getInstance();
$teamsFilePath = __DIR__ . '/../../database/json/teams.json';
$usersFilePath = __DIR__ . '/../../database/json/users.json';

// --- AJAX Request Handling ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json'); // Set header early
    $input = json_decode(file_get_contents('php://input'), true);

    // Check if JSON decoding failed
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Failed to decode JSON input: " . json_last_error_msg());
        error_log("Raw input: " . file_get_contents('php://input'));
        echo json_encode(['success' => false, 'message' => 'Invalid request data received.']);
        exit;
    }

    // Log received data for debugging
    // error_log("Received team action data: " . print_r($input, true));

    if (empty($input['action'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        exit;
    }

    try {
        // Load current teams and users data
        if (!file_exists($teamsFilePath)) throw new Exception("Teams data file not found: $teamsFilePath");
        if (!file_exists($usersFilePath)) throw new Exception("Users data file not found: $usersFilePath");

        // Use JsonDatabase::read directly to ensure we get latest data before writing
        $currentTeams = $jsonDb->read('teams'); // Ensure read method exists and works
        $usersDataJson = file_get_contents($usersFilePath);
        if ($usersDataJson === false) throw new Exception("Failed to read users data file: $usersFilePath");
        $usersData = json_decode($usersDataJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Failed to decode users JSON: ".json_last_error_msg());
        $usersList = $usersData['users'] ?? $usersData;

        // --- Action: Create Team ---
        if ($input['action'] === 'create_team') {
            // ... (validation as before) ...

            // Generate new ID
            $newId = 1;
            if (!empty($currentTeams)) {
                $ids = array_column($currentTeams, 'id');
                 if (!empty($ids)) { // Check if ids array is not empty
                     $newId = max($ids) + 1;
                 }
            }


            // Find lead user by username
            $leadUsername = $input['lead'] ?? null;
            $leadUser = null;
            if ($leadUsername) {
                $filtered = array_filter($usersList, fn($user) => isset($user['username']) && $user['username'] === $leadUsername);
                $leadUser = reset($filtered) ?: null;
            }
             if (!$leadUser) {
                throw new Exception('Invalid team lead selected or lead user data incomplete.');
            }

            // Map member usernames to IDs
            $memberIds = [];
            if (!empty($input['members']) && is_array($input['members'])) {
                foreach ($input['members'] as $username) {
                     $user = null;
                     $filtered = array_filter($usersList, fn($u) => isset($u['username']) && $u['username'] === $username);
                     $user = reset($filtered);
                     if ($user && isset($user['id'])) {
                         $memberIds[] = $user['id'];
                     } else {
                         error_log("Warning: Could not find user ID for member username: " . $username);
                     }
                }
            }

            // Prepare new team data
            $newTeam = [
                'id' => $newId,
                'name' => htmlspecialchars(trim($input['name'])),
                'description' => isset($input['description']) ? htmlspecialchars(trim($input['description'])) : '',
                'department' => htmlspecialchars($input['department']),
                'lead' => [
                    'id' => $leadUser['id'],
                    'name' => ($leadUser['firstName'] ?? '') . ' ' . ($leadUser['lastName'] ?? '')
                ],
                'members' => $memberIds,
                'status' => htmlspecialchars($input['status']),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'metrics' => ['tasks' => 0, 'completed' => 0, 'onTime' => 0, 'efficiency' => 0]
            ];

            // Add the new team
            $currentTeams[] = $newTeam;

            // Update the data using JsonDatabase
            $updateSuccess = $jsonDb->update('teams', $currentTeams); // Assuming update returns true/false or throws Exception
            if (!$updateSuccess) {
                 throw new Exception("Failed to update teams data file. Check permissions and JsonDatabase implementation.");
            }

            echo json_encode(['success' => true, 'message' => 'Team created successfully.', 'new_team' => $newTeam]);
            exit;
        }

        // --- Action: Edit Team ---
        elseif ($input['action'] === 'edit_team') {
            // ... (validation as before for required fields like id, name etc.) ...
             $teamId = isset($input['id']) ? (int)$input['id'] : 0;
             if ($teamId <= 0) {
                 throw new Exception('Invalid or missing Team ID for edit.');
             }

            $teamIndex = -1;
            foreach ($currentTeams as $index => $team) {
                if (isset($team['id']) && $team['id'] === $teamId) {
                    $teamIndex = $index;
                    break;
                }
            }

            if ($teamIndex === -1) {
                throw new Exception('Team not found for editing.');
            }

             // Find lead user by username
             $leadUsername = $input['lead'] ?? null;
             $leadUser = null;
             if ($leadUsername) {
                 $filtered = array_filter($usersList, fn($user) => isset($user['username']) && $user['username'] === $leadUsername);
                 $leadUser = reset($filtered) ?: null;
             }
             if (!$leadUser) {
                 throw new Exception('Invalid team lead selected or lead user data incomplete for edit.');
             }

             // Map member usernames to IDs
             $memberIds = [];
             if (!empty($input['members']) && is_array($input['members'])) {
                 foreach ($input['members'] as $username) {
                     $user = null;
                     $filtered = array_filter($usersList, fn($u) => isset($u['username']) && $u['username'] === $username);
                     $user = reset($filtered);
                     if ($user && isset($user['id'])) {
                         $memberIds[] = $user['id'];
                     } else {
                         error_log("Warning: Could not find user ID for member username during edit: " . $username);
                     }
                 }
             }

            // Update team data in the array
            $currentTeams[$teamIndex]['name'] = htmlspecialchars(trim($input['name']));
            $currentTeams[$teamIndex]['description'] = isset($input['description']) ? htmlspecialchars(trim($input['description'])) : '';
            $currentTeams[$teamIndex]['department'] = htmlspecialchars($input['department']);
            $currentTeams[$teamIndex]['lead'] = [
                 'id' => $leadUser['id'],
                 'name' => ($leadUser['firstName'] ?? '') . ' ' . ($leadUser['lastName'] ?? '')
            ];
            $currentTeams[$teamIndex]['members'] = $memberIds;
            $currentTeams[$teamIndex]['status'] = htmlspecialchars($input['status']);
            $currentTeams[$teamIndex]['updated_at'] = date('Y-m-d H:i:s');

            // Update the data file
             $updateSuccess = $jsonDb->update('teams', $currentTeams);
             if (!$updateSuccess) {
                 throw new Exception("Failed to update teams data file during edit. Check permissions and JsonDatabase implementation.");
             }

            echo json_encode(['success' => true, 'message' => 'Team updated successfully.', 'updated_team' => $currentTeams[$teamIndex]]);
            exit;
        }

        // --- Action: Delete Team --- (Keep as is for now)
        elseif ($input['action'] === 'delete_team') {
             // ... existing delete logic ...
             $teamId = isset($input['id']) ? (int)$input['id'] : 0;
             if ($teamId <= 0) {
                 throw new Exception('Invalid or missing Team ID for delete.');
             }

            $initialCount = count($currentTeams);
            $updatedTeams = array_filter($currentTeams, fn($team) => !isset($team['id']) || $team['id'] !== $teamId);

            if (count($updatedTeams) === $initialCount && $initialCount > 0) { // Check if anything was actually filtered
                throw new Exception('Team not found or could not be deleted.');
            }

            $updatedTeams = array_values($updatedTeams); // Re-index array

            $updateSuccess = $jsonDb->update('teams', $updatedTeams);
             if (!$updateSuccess) {
                 throw new Exception("Failed to update teams data file during delete. Check permissions and JsonDatabase implementation.");
             }

            echo json_encode(['success' => true, 'message' => 'Team deleted successfully.']);
            exit;
        }

        // --- Invalid Action ---
        else {
             throw new Exception('Unknown action specified.');
        }

    } catch (Exception $e) {
        // Log the detailed error to the PHP error log
        error_log("Error processing team action '{$input['action']}': " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        // Send a generic error message back to the client
        echo json_encode(['success' => false, 'message' => 'Server error processing request. Please check server logs. Details: ' . $e->getMessage()]); // Optionally include $e->getMessage() for FE debugging, remove for production
        exit;
    }
}


// --- Load data for initial page display ---
$pageError = null;
try {
    $teams = $jsonDb->query('teams');
} catch (Exception $e) {
    error_log("Error reading teams.json for page load: " . $e->getMessage());
    $teams = [];
    $pageError = "Error loading team data.";
}

// Load users data for modals
try {
    $usersData = json_decode(file_get_contents($usersFilePath), true);
    $usersList = $usersData['users'] ?? $usersData; // Handle both structures
} catch (Exception $e) {
    error_log("Error reading users.json for page load: " . $e->getMessage());
    $usersList = [];
    $pageError = ($pageError ? $pageError . ' ' : '') . "Error loading user data.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teams Management - Admin Dashboard</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/dashboard2.css">
    <style>
        /* Add some basic styling for modals if not already present */
        .modal { display: none; /* Hidden by default */ position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: var(--background-color); margin: auto; padding: 20px; border: 1px solid var(--border-color); width: 90%; max-width: 600px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); color: var(--text-color); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 15px; }
        .modal-header h2 { margin: 0; }
        .close-modal { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-color); }
        .modal-body { max-height: 60vh; overflow-y: auto; padding-right: 10px; /* For scrollbar */}
        .modal-footer { border-top: 1px solid var(--border-color); padding-top: 15px; margin-top: 15px; text-align: right; }
        .team-members-selection { max-height: 150px; overflow-y: auto; border: 1px solid var(--border-color); padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .checkbox-label { display: block; margin-bottom: 5px; }
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
                <li><a href="dashboard.php"><svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg> Dashboard</a></li>
                <li><a href="tasks-view.php"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z"/></svg> Tasks</a></li>
                <li class="active"><a href="teams-view.php"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg> Teams</a></li>
                <li><a href="projects-view.php"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zM7 10h5v5H7z"/></svg> Projects</a></li>
                <li><a href="departments-view.php"><svg viewBox="0 0 24 24"><path d="M12 2L4.5 20.29l.71.71L12 18l6.79 3 .71-.71z"/></svg> Departments</a></li>
                <li><a href="notifications-view.php"><svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"/></svg> Notifications</a></li>
                <li><a href="users-view.php"><svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg> Users</a></li>
                <li><a href="reports-view.php"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg> Reports</a></li>
                <li><a href="dashboard.php#settings"><svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg> Settings</a></li>
                <li class="logout-item"><a href="../../views/auth/logout.php"><svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg> Logout</a></li>
            </ul>
        </nav>

        <!-- Main Content Area -->
        <main class="main-content">
            <header class="content-header">
                <div class="header-left">
                    <h1>Team Management</h1>
                </div>
                <div class="header-right">
                    <!-- Header Icons -->
                    <div class="header-icons">
                        <button id="themeToggle" class="theme-toggle" aria-label="Toggle theme">
                            <svg class="sun-icon" viewBox="0 0 24 24"><path d="M12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.65 0-3 1.35-3 3s1.35 3 3 3 3-1.35 3-3-1.35-3-3-3zm0-2c.28 0 .5-.22.5-.5v-3c0-.28-.22-.5-.5-.5s-.5.22-.5.5v3c0 .28.22.5.5.5zm0 13c-.28 0-.5.22-.5.5v3c0 .28.22.5.5.5s.5-.22.5-.5v-3c0-.28-.22-.5-.5-.5z"/></svg>
                            <svg class="moon-icon" viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z"/></svg>
                        </button>
                        <button class="notification-btn" aria-label="View notifications">
                             <svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"/></svg>
                             <span class="notification-badge">3</span> <!-- Example badge -->
                        </button>
                        <div class="user-menu-container">
                            <button class="user-menu-btn">
                                <svg class="avatar-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                                <span class="username"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                            </button>
                             <div class="user-dropdown">
                                 <a href="dashboard.php#settings" class="dropdown-item"><svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg> Settings</a>
                                 <a href="../../views/auth/logout.php" class="dropdown-item"><svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg> Logout</a>
                             </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Teams Section -->
            <section class="teams-section" id="teams">
                <div class="section-header">
                    <h2>Team Management</h2>
                    <button class="btn-primary" id="addTeamBtn">
                        <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                        Create Team
                    </button>
                </div>

                <!-- Display Page Errors -->
                <?php if ($pageError): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($pageError); ?></div>
                <?php endif; ?>

                <!-- Team Filters -->
                <div class="task-filters">
                    <div class="filter-group">
                        <label for="teamDepartmentFilter">Department</label>
                        <select id="teamDepartmentFilter">
                            <option value="all">All Departments</option>
                            <option value="engineering">Engineering</option>
                            <option value="marketing">Marketing</option>
                            <option value="design">Design</option>
                            <option value="operations">Operations</option>
                            <option value="hr">HR</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="teamStatusFilter">Status</label>
                        <select id="teamStatusFilter">
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="search-group">
                        <label>Search</label>
                        <input type="text" id="searchTeams" placeholder="Search teams by name, dept, lead...">
                    </div>
                </div>

                <!-- Teams List -->
                <div class="team-list" id="teamList">
                    <!-- Teams will be populated dynamically -->
                    <p>Loading teams...</p>
                </div>
            </section>
        </main>
    </div>

    <!-- Mobile menu toggle -->
    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
        <svg viewBox="0 0 24 24" width="24" height="24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
    </button>

    <!-- Create Team Modal -->
    <div class="modal" id="createTeamModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create New Team</h2>
                <button class="close-modal" aria-label="Close modal">×</button>
            </div>
            <form id="createTeamForm" class="modal-body">
                <div class="form-group">
                    <label for="createTeamName">Team Name</label>
                    <input type="text" id="createTeamName" name="name" required>
                </div>
                <div class="form-group">
                    <label for="createTeamDepartment">Department</label>
                    <select id="createTeamDepartment" name="department" required>
                        <option value="" disabled selected>Select Department</option>
                        <option value="engineering">Engineering</option>
                        <option value="marketing">Marketing</option>
                        <option value="design">Design</option>
                        <option value="operations">Operations</option>
                        <option value="hr">HR</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="createTeamDescription">Description</label>
                    <textarea id="createTeamDescription" name="description"></textarea>
                </div>
                <div class="form-group">
                    <label for="createTeamLead">Team Lead</label>
                    <select id="createTeamLead" name="lead" required>
                        <option value="">Select Team Lead</option>
                        <!-- Populated by JS -->
                    </select>
                </div>
                <div class="form-group">
                    <label for="createTeamStatus">Status</label>
                    <select id="createTeamStatus" name="status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Team Members</label>
                    <div class="team-members-selection" id="createTeamMembersSelection">
                        <p>Loading users...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary close-modal">Cancel</button>
                    <button type="submit" class="btn-primary">Create Team</button>
                </div>
            </form>
        </div>
    </div>

     <!-- Edit Team Modal -->
    <div class="modal" id="editTeamModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Team</h2>
                <button class="close-modal" aria-label="Close modal">×</button>
            </div>
            <form id="editTeamForm" class="modal-body">
                 <input type="hidden" id="editTeamId" name="id">
                 <div class="form-group">
                    <label for="editTeamName">Team Name</label>
                    <input type="text" id="editTeamName" name="name" required>
                </div>
                <div class="form-group">
                    <label for="editTeamDepartment">Department</label>
                    <select id="editTeamDepartment" name="department" required>
                         <option value="" disabled>Select Department</option>
                         <option value="engineering">Engineering</option>
                         <option value="marketing">Marketing</option>
                         <option value="design">Design</option>
                         <option value="operations">Operations</option>
                         <option value="hr">HR</option>
                    </select>
                </div>
                 <div class="form-group">
                    <label for="editTeamDescription">Description</label>
                    <textarea id="editTeamDescription" name="description"></textarea>
                </div>
                <div class="form-group">
                    <label for="editTeamLead">Team Lead</label>
                    <select id="editTeamLead" name="lead" required>
                        <option value="">Select Team Lead</option>
                        <!-- Populated by JS -->
                    </select>
                </div>
                 <div class="form-group">
                    <label for="editTeamStatus">Status</label>
                    <select id="editTeamStatus" name="status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                 <div class="form-group">
                    <label>Team Members</label>
                    <div class="team-members-selection" id="editTeamMembersSelection">
                        <p>Loading users...</p>
                    </div>
                </div>
                 <div class="modal-footer">
                    <button type="button" class="btn-secondary close-modal">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../../assets/js/theme.js"></script>
    <script src="../../assets/js/dashboard.js"></script>
    <!-- <script src="../../assets/js/admin-dashboard.js"></script> --> <!-- May contain conflicting modal logic, careful inclusion -->
    <script>
        // Global data stores
        let allTeamsData = <?php echo json_encode($teams ?: []); ?>;
        let allUsersData = <?php echo json_encode($usersList ?: []); ?>;

        // Utility to escape HTML
        function escapeHTML(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/[&<>"']/g, function(match) {
                return {
                    '&': '&',
                    '<': '<', 
                    '>': '>', 
                    '"': '"',
                    "'": "'"
                }[match];
            });
        }

         // Modal Handling Functions
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'flex';
            }
        }

        function closeModal(modalElementOrId) {
            const modal = typeof modalElementOrId === 'string'
                ? document.getElementById(modalElementOrId)
                : modalElementOrId;
            if (modal) {
                modal.style.display = 'none';
                 // Optional: Reset forms inside the modal when closing
                 const form = modal.querySelector('form');
                 if (form) {
                     form.reset();
                     // Clear dynamically added content like member lists if needed
                     const memberSelection = modal.querySelector('.team-members-selection');
                     if (memberSelection) memberSelection.innerHTML = '<p>Loading users...</p>';
                 }
            }
        }

        // Setup event listeners for all close buttons in modals
        function setupModalCloseButtons() {
             document.querySelectorAll('.modal').forEach(modal => {
                 modal.querySelectorAll('.close-modal').forEach(btn => {
                     btn.addEventListener('click', () => closeModal(modal));
                 });
                 // Close modal if clicking outside the content area
                 modal.addEventListener('click', (event) => {
                      if (event.target === modal) {
                          closeModal(modal);
                      }
                 });
             });
        }

        // Populates Lead dropdown and Member checkboxes for a given modal
        function populateTeamModalDropdowns(modalId, currentLeadUsername = null, currentMemberIds = []) {
            const modal = document.getElementById(modalId);
            if (!modal || !allUsersData) return;

            const teamLeadSelect = modal.querySelector('select[name="lead"]');
            const membersContainer = modal.querySelector('.team-members-selection');

            if (!teamLeadSelect || !membersContainer) return; // Elements not found

            // Populate Lead Select
            teamLeadSelect.innerHTML = '<option value="">Select Team Lead</option>'; // Reset
            const activeUsers = allUsersData.filter(user => user.status === 'active');

            activeUsers.forEach(user => {
                const option = document.createElement('option');
                option.value = user.username;
                option.textContent = `${user.firstName} ${user.lastName} (${user.username})`;
                if (user.username === currentLeadUsername) {
                    option.selected = true;
                }
                teamLeadSelect.appendChild(option);
            });

            // Populate Members Checkboxes
            membersContainer.innerHTML = ''; // Reset
            if (activeUsers.length > 0) {
                activeUsers.forEach(user => {
                    const label = document.createElement('label');
                    label.classList.add('checkbox-label');

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = 'members[]'; // Use name consistent with form handling
                    checkbox.value = user.username; // Use username for consistency with lead select

                    // Check if this user is a current member (compare by ID)
                    if (currentMemberIds.includes(user.id)) {
                        checkbox.checked = true;
                    }

                    label.appendChild(checkbox);
                    label.appendChild(document.createTextNode(` ${user.firstName} ${user.lastName} (${user.username})`));
                    membersContainer.appendChild(label);
                    // membersContainer.appendChild(document.createElement('br')); // Use CSS for spacing instead if preferred
                });
            } else {
                membersContainer.innerHTML = '<p>No active users found.</p>';
            }
        }


        // Renders the list of teams
        function renderTeams(teamsToRender) {
            const teamListContainer = document.getElementById('teamList');
            teamListContainer.innerHTML = ''; // Clear previous content

            if (!teamsToRender || teamsToRender.length === 0) {
                teamListContainer.innerHTML = '<p>No teams found matching your criteria.</p>';
                return;
            }

            teamsToRender.forEach(team => {
                const teamItem = document.createElement('div');
                teamItem.classList.add('team-item');
                teamItem.dataset.teamId = team.id; // Add team ID for easy access

                // Find lead name using lead.id - Fallback to stored name if needed
                const leadInfo = team.lead ? (allUsersData.find(u => u.id === team.lead.id) || { name: team.lead.name || 'N/A' }) : { name: 'N/A' };
                const leadName = leadInfo.firstName ? `${leadInfo.firstName} ${leadInfo.lastName}` : (leadInfo.name || 'N/A');

                teamItem.innerHTML = `
                    <div class="team-info">
                        <div class="team-header">
                            <h3 class="team-name">${escapeHTML(team.name)}</h3>
                            <span class="status-badge status-${escapeHTML(team.status || 'unknown').toLowerCase()}">${escapeHTML(team.status || 'Unknown')}</span>
                        </div>
                        <div class="team-details">
                            <span class="team-department">Dept: ${escapeHTML(team.department || 'N/A')}</span>
                            <span class="team-members">Members: ${Array.isArray(team.members) ? team.members.length : 0}</span>
                            <span class="team-lead">Lead: ${escapeHTML(leadName)}</span>
                        </div>
                         <p class="team-description">${escapeHTML(team.description || 'No description')}</p>
                        <div class="team-metrics">
                            <div class="metric">
                                <span class="metric-label">Tasks</span>
                                <span class="metric-value">${team.metrics && team.metrics.tasks !== undefined ? escapeHTML(team.metrics.tasks) : '0'}</span>
                            </div>
                            <div class="metric">
                                <span class="metric-label">Completed</span>
                                <span class="metric-value">${team.metrics && team.metrics.completed !== undefined ? escapeHTML(team.metrics.completed) : '0'}</span>
                            </div>
                            <div class="metric">
                                <span class="metric-label">On Time %</span>
                                <span class="metric-value">${team.metrics && team.metrics.onTime !== undefined ? escapeHTML(team.metrics.onTime) : '0'}%</span>
                            </div>
                            <div class="metric">
                                <span class="metric-label">Efficiency %</span>
                                <span class="metric-value">${team.metrics && team.metrics.efficiency !== undefined ? escapeHTML(team.metrics.efficiency) : '0'}%</span>
                            </div>
                        </div>
                    </div>
                    <div class="team-actions">
                        <button class="btn-icon edit-team-btn" data-team-id="${team.id}" aria-label="Edit team">
                            <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                        </button>
                        <button class="btn-icon delete-team-btn" data-team-id="${team.id}" aria-label="Delete team">
                            <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                        </button>
                        <!-- Add View Button if needed -->
                         <!-- <button class="btn-icon view-team-btn" data-team-id="${team.id}" aria-label="View team"><svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg></button> -->
                    </div>
                `;
                teamListContainer.appendChild(teamItem);
            });
        }

        // Fetches latest teams data and re-renders the list
        function fetchAndRenderTeams() {
            console.log("Refreshing team list...");
            // Fetch latest data (optional, could just re-render filtered `allTeamsData`)
            // For simplicity after CUD operations, we re-fetch to ensure consistency
             fetch('teams-view.php', { // Use a dedicated API endpoint ideally, but fetching the page works for now if it can return JSON
                 headers: {
                     'Accept': 'application/json' // Indicate we prefer JSON if possible, though this page isn't set up for that on GET
                 }
             })
             .then(response => {
                 // Attempt to re-parse the initial data embedded in the reloaded page source
                 // This is NOT ideal. A proper API endpoint returning JSON is better.
                 // As a fallback, we fetch the JSON file directly.
                 return fetch('../../database/json/teams.json?t=' + Date.now()); // Add cache buster
             })
             .then(response => {
                 if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                 return response.json();
             })
             .then(data => {
                 allTeamsData = data; // Update global store
                 applyFilters(); // Re-apply filters to the new data
                 console.log("Team list refreshed.");
             })
             .catch(error => {
                 console.error('Error refreshing teams:', error);
                 document.getElementById('teamList').innerHTML = `<p class="alert alert-danger">Error refreshing team list: ${error.message}. Please reload the page.</p>`;
             });
        }


        // --- Event Handlers ---

        // Handle Create Team Form Submission
        function handleCreateTeamSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const teamData = Object.fromEntries(formData.entries());

            // Collect selected members (checkbox values are usernames)
            const selectedMembers = [];
            form.querySelectorAll('input[name="members[]"]:checked').forEach(checkbox => {
                selectedMembers.push(checkbox.value);
            });
            teamData.members = selectedMembers;
            teamData.action = 'create_team'; // Add action type

            // Basic client-side validation (can be expanded)
            if (!teamData.name || !teamData.department || !teamData.lead || !teamData.status) {
                alert('Please fill in all required fields.');
                return;
            }

            console.log('Submitting Create Team:', teamData);

            fetch('teams-view.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest' // Crucial for PHP detection
                },
                body: JSON.stringify(teamData)
            })
            .then(response => response.json()) // Always expect JSON back
            .then(data => {
                console.log('Create Team Response:', data);
                if (data.success) {
                    alert(data.message || 'Team created successfully!');
                    closeModal('createTeamModal');
                    fetchAndRenderTeams(); // Refresh the list
                } else {
                    alert(`Error creating team: ${data.message || 'Unknown error'}`);
                }
            })
            .catch(error => {
                console.error('Error submitting create form:', error);
                alert('An error occurred while creating the team. Please check the console and try again.');
            });
        }

        // Handle Edit Team Button Click (Event Delegation)
        function handleEditTeamClick(teamId) {
            const team = allTeamsData.find(t => t.id === teamId);
            if (!team) {
                alert('Team data not found.');
                return;
            }

            console.log('Editing Team:', team);

            const modal = document.getElementById('editTeamModal');
            modal.querySelector('#editTeamId').value = team.id;
            modal.querySelector('#editTeamName').value = team.name;
            modal.querySelector('#editTeamDescription').value = team.description || '';
            modal.querySelector('#editTeamDepartment').value = team.department;
            modal.querySelector('#editTeamStatus').value = team.status;

            // Find lead username by ID
            const leadUser = allUsersData.find(u => u.id === (team.lead ? team.lead.id : null));
            const leadUsername = leadUser ? leadUser.username : null;

            // Populate dropdowns, passing current lead username and member IDs
            populateTeamModalDropdowns('editTeamModal', leadUsername, team.members || []);

            openModal('editTeamModal');
        }


         // Handle Edit Team Form Submission
        function handleEditTeamSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const teamData = Object.fromEntries(formData.entries());

            // Collect selected members (checkbox values are usernames)
            const selectedMembers = [];
            form.querySelectorAll('input[name="members[]"]:checked').forEach(checkbox => {
                selectedMembers.push(checkbox.value);
            });
            teamData.members = selectedMembers;
            teamData.action = 'edit_team'; // Add action type

            // Basic client-side validation
             if (!teamData.id || !teamData.name || !teamData.department || !teamData.lead || !teamData.status) {
                alert('Please fill in all required fields.');
                return;
            }

            console.log('Submitting Edit Team:', teamData);

            fetch('teams-view.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(teamData)
            })
             .then(response => response.json())
             .then(data => {
                 console.log('Edit Team Response:', data);
                 if (data.success) {
                    alert(data.message || 'Team updated successfully!');
                    closeModal('editTeamModal');
                    fetchAndRenderTeams(); // Refresh the list
                } else {
                    alert(`Error updating team: ${data.message || 'Unknown error'}`);
                }
            })
            .catch(error => {
                console.error('Error submitting edit form:', error);
                alert('An error occurred while updating the team. Please check the console and try again.');
            });
        }

        // Handle Delete Team Button Click (Event Delegation)
        function handleDeleteTeamClick(teamId) {
            const team = allTeamsData.find(t => t.id === teamId);
            if (!team) {
                 alert('Team not found.');
                 return;
            }

             if (confirm(`Are you sure you want to delete the team "${team.name}"? This action cannot be undone.`)) {
                 console.log('Deleting Team ID:', teamId);
                 fetch('teams-view.php', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-Requested-With': 'XMLHttpRequest'
                     },
                     body: JSON.stringify({ action: 'delete_team', id: teamId })
                 })
                 .then(response => response.json())
                 .then(data => {
                    console.log('Delete Team Response:', data);
                     if (data.success) {
                         alert(data.message || 'Team deleted successfully!');
                         fetchAndRenderTeams(); // Refresh the list
                     } else {
                         alert(`Error deleting team: ${data.message || 'Unknown error'}`);
                     }
                 })
                 .catch(error => {
                     console.error('Error deleting team:', error);
                     alert('An error occurred while deleting the team. Please check the console and try again.');
                 });
             }
        }


        // --- Filtering and Searching ---

        function applyFilters() {
            const departmentFilter = document.getElementById('teamDepartmentFilter').value;
            const statusFilter = document.getElementById('teamStatusFilter').value;
            const searchTerm = document.getElementById('searchTeams').value.toLowerCase().trim();

            const filteredTeams = allTeamsData.filter(team => {
                // Department filter
                const matchesDepartment = departmentFilter === 'all' || team.department === departmentFilter;
                // Status filter
                const matchesStatus = statusFilter === 'all' || team.status === statusFilter;

                // Search filter (check name, department, lead name)
                 const leadName = (team.lead && team.lead.name) ? team.lead.name.toLowerCase() : '';
                 const matchesSearch = searchTerm === '' ||
                                     (team.name && team.name.toLowerCase().includes(searchTerm)) ||
                                     (team.department && team.department.toLowerCase().includes(searchTerm)) ||
                                     leadName.includes(searchTerm) ||
                                     (team.description && team.description.toLowerCase().includes(searchTerm)); // Added description search


                return matchesDepartment && matchesStatus && matchesSearch;
            });

            renderTeams(filteredTeams);
        }

        function setupTeamFilters() {
            const departmentFilter = document.getElementById('teamDepartmentFilter');
            const statusFilter = document.getElementById('teamStatusFilter');
            const searchInput = document.getElementById('searchTeams');

            departmentFilter.addEventListener('change', applyFilters);
            statusFilter.addEventListener('change', applyFilters);
            // Use 'input' for real-time search, 'change' triggers on blur
            searchInput.addEventListener('input', applyFilters);

            // Initial render based on default filter values
            applyFilters();
        }

        // --- Initialization ---
        document.addEventListener('DOMContentLoaded', function() {
            // Basic setup
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.querySelector('.sidebar');
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', () => sidebar.classList.toggle('show-mobile'));
            }

            // Initialize modal close buttons
            setupModalCloseButtons();

            // Setup button to open Create modal
            const addTeamBtn = document.getElementById('addTeamBtn');
            if (addTeamBtn) {
                addTeamBtn.addEventListener('click', () => {
                    // Populate dropdowns for the create modal before opening
                    populateTeamModalDropdowns('createTeamModal');
                    openModal('createTeamModal');
                });
            }

            // Setup form submissions
            const createTeamForm = document.getElementById('createTeamForm');
            if (createTeamForm) {
                createTeamForm.addEventListener('submit', handleCreateTeamSubmit);
            }
            const editTeamForm = document.getElementById('editTeamForm');
             if (editTeamForm) {
                 editTeamForm.addEventListener('submit', handleEditTeamSubmit);
             }


            // Setup event delegation for action buttons in the team list
             const teamListContainer = document.getElementById('teamList');
             if (teamListContainer) {
                 teamListContainer.addEventListener('click', (event) => {
                     const editButton = event.target.closest('.edit-team-btn');
                     const deleteButton = event.target.closest('.delete-team-btn');
                     // Add view button logic here if needed
                     // const viewButton = event.target.closest('.view-team-btn');

                     if (editButton) {
                         const teamId = parseInt(editButton.dataset.teamId, 10);
                         if (!isNaN(teamId)) {
                             handleEditTeamClick(teamId);
                         }
                     } else if (deleteButton) {
                         const teamId = parseInt(deleteButton.dataset.teamId, 10);
                         if (!isNaN(teamId)) {
                            handleDeleteTeamClick(teamId);
                         }
                     }
                     // else if (viewButton) { /* Handle view action */ }
                 });
             }

            // Load initial teams and setup filters
            renderTeams(allTeamsData); // Initial render with data loaded by PHP
            setupTeamFilters(); // Setup filter listeners
        });

    </script>
</body>
</html>