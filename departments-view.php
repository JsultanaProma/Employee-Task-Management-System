<?php
session_start();
require_once __DIR__ . '/../../php/config/JsonDatabase.php';

// Check authentication and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Department Handler Class
class DepartmentHandler {
    private $dataFile = __DIR__ . '/../../database/json/departments.json';
    private $jsonDb;

    public function __construct() {
        $this->jsonDb = JsonDatabase::getInstance();
        if (!file_exists($this->dataFile)) {
            file_put_contents($this->dataFile, json_encode(['departments' => []], JSON_PRETTY_PRINT));
        }
    }

    public function handleFormSubmission() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            $response = ['success' => false, 'message' => ''];

            try {
                if (isset($_POST['action'])) {
                    switch ($_POST['action']) {
                        case 'add_department':
                            $departmentData = [
                                'name' => trim($_POST['name']),
                                'description' => trim($_POST['description']),
                                'head' => trim($_POST['head']),
                                'budget' => (float)$_POST['budget'],
                                'employee_count' => (int)$_POST['employee_count'],
                                'created_at' => gmdate('c') // ISO 8601 format like in departments.json
                            ];

                            // Validation
                            if (empty($departmentData['name'])) {
                                throw new Exception('Department name is required');
                            }
                            if ($departmentData['budget'] < 0) {
                                throw new Exception('Budget cannot be negative');
                            }
                            if ($departmentData['employee_count'] < 0) {
                                throw new Exception('Employee count cannot be negative');
                            }

                            $this->createDepartment($departmentData);
                            $response['success'] = true;
                            $response['message'] = 'Department created successfully';
                            break;

                        case 'edit_department':
                            $departmentData = [
                                'name' => trim($_POST['name']),
                                'description' => trim($_POST['description']),
                                'head' => trim($_POST['head']),
                                'budget' => (float)$_POST['budget'],
                                'employee_count' => (int)$_POST['employee_count']
                            ];
                            $id = $_POST['department_id'];

                            // Validation
                            if (empty($departmentData['name'])) {
                                throw new Exception('Department name is required');
                            }
                            if ($departmentData['budget'] < 0) {
                                throw new Exception('Budget cannot be negative');
                            }
                            if ($departmentData['employee_count'] < 0) {
                                throw new Exception('Employee count cannot be negative');
                            }

                            $this->updateDepartment($id, $departmentData);
                            $response['success'] = true;
                            $response['message'] = 'Department updated successfully';
                            break;

                        case 'delete_department':
                            $id = $_POST['delete_id'];
                            $this->deleteDepartment($id);
                            $response['success'] = true;
                            $response['message'] = 'Department deleted successfully';
                            break;

                        default:
                            throw new Exception('Invalid action');
                    }
                } else {
                    throw new Exception('No action specified');
                }
            } catch (Exception $e) {
                $response['message'] = $e->getMessage();
                error_log('DepartmentHandler Error: ' . $e->getMessage() . ' at ' . date('Y-m-d H:i:s'));
            }

            echo json_encode($response);
            exit;
        }
    }

    public function createDepartment($departmentData) {
        $data = $this->jsonDb->query('departments');
        $departments = $data['departments'] ?? [];

        // Generate a unique ID
        $departmentData['id'] = $this->generateUniqueId($departments);

        // Add new department
        $departments[] = $departmentData;

        // Save to file with locking
        $this->saveData(['departments' => $departments]);
    }

    public function updateDepartment($id, $departmentData) {
        $data = $this->jsonDb->query('departments');
        $departments = $data['departments'] ?? [];

        // Find and update the department
        $found = false;
        foreach ($departments as &$department) {
            if ($department['id'] === $id) {
                $department = array_merge($department, $departmentData);
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new Exception("Department with ID $id not found");
        }

        // Save to file
        $this->saveData(['departments' => $departments]);
    }

    public function deleteDepartment($id) {
        $data = $this->jsonDb->query('departments');
        $departments = $data['departments'] ?? [];

        // Filter out the department
        $initialCount = count($departments);
        $departments = array_filter($departments, function ($department) use ($id) {
            return $department['id'] !== $id;
        });

        if (count($departments) >= $initialCount) {
            throw new Exception("Department with ID $id not found");
        }

        // Save to file
        $this->saveData(['departments' => array_values($departments)]);
    }

    private function generateUniqueId($departments) {
        do {
            $id = 'dept' . bin2hex(random_bytes(3)); // e.g., dept1a2b3c
        } while ($this->idExists($id, $departments));
        return $id;
    }

    private function idExists($id, $departments) {
        foreach ($departments as $department) {
            if ($department['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    private function saveData($data) {
        $fp = fopen($this->dataFile, 'c+');
        if (flock($fp, LOCK_EX)) {
            $json = json_encode($data, JSON_PRETTY_PRINT);
            if ($json === false) {
                flock($fp, LOCK_UN);
                fclose($fp);
                throw new Exception('Failed to encode JSON data');
            }
            ftruncate($fp, 0);
            fwrite($fp, $json);
            fflush($fp);
            flock($fp, LOCK_UN);
        } else {
            fclose($fp);
            throw new Exception('Failed to lock file for writing');
        }
        fclose($fp);
    }
}

// Instantiate handler and process submissions
$handler = new DepartmentHandler();
$handler->handleFormSubmission();

// Load JSON data
$jsonDb = JsonDatabase::getInstance();
$departments = $jsonDb->query('departments')['departments'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments - Task Management System</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
</head>
<body class="dashboard-page" data-theme="light">
    <div class="dashboard-layout">
        <!-- Sidebar Navigation -->
        <nav class="sidebar">
            <div class="sidebar-header">
                <h2>Task Manager</h2>
            </div>
            <ul class="nav-links">
                <li>
                    <a href="dashboard.php">
                        <svg viewBox="0 0 24 24">
                            <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z" />
                        </svg>
                        Dashboard
                    </a>
                </li>
                <li>
                    <a href="tasks-view.php">
                        <svg viewBox="0 0 24 24">
                            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z" />
                        </svg>
                        Tasks
                    </a>
                </li>
                <li>
                    <a href="teams-view.php">
                        <svg viewBox="0 0 24 24">
                            <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" />
                        </svg>
                        Teams
                    </a>
                </li>
                <li>
                    <a href="projects-view.php">
                        <svg viewBox="0 0 24 24">
                            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zM7 10h5v5H7z" />
                        </svg>
                        Projects
                    </a>
                </li>
                <li class="active">
                    <a href="departments-view.php">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 2L4.5 20.29l.71.71L12 18l6.79 3 .71-.71z" />
                        </svg>
                        Departments
                    </a>
                </li>
                <li>
                    <a href="notifications-view.php">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z" />
                        </svg>
                        Notifications
                    </a>
                </li>
                <li>
                    <a href="users-view.php">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                        </svg>
                        Users
                    </a>
                </li>
                <li>
                    <a href="reports-view.php">
                        <svg viewBox="0 0 24 24">
                            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" />
                        </svg>
                        Reports
                    </a>
                </li>
                <li>
                    <a href="dashboard.php#settings">
                        <svg viewBox="0 0 24 24">
                            <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l-.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z" />
                        </svg>
                        Settings
                    </a>
                </li>
                <li class="logout-item">
                    <a href="../../views/auth/logout.php">
                        <svg viewBox="0 0 24 24">
                            <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z" />
                        </svg>
                        Logout
                    </a>
                </li>
            </ul>
        </nav>
        <!-- Main Content Area -->
        <main class="main-content">
            <header class="content-header">
                <div class="header-left">
                    <h1>Departments</h1>
                </div>
                <div class="header-right">
                    <div class="header-icons">
                        <button id="themeToggle" class="theme-toggle" aria-label="Toggle theme">
                            <svg class="sun-icon" viewBox="0 0 24 24">
                                <path d="M12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.65 0-3 1.35-3 3s1.35 3 3 3 3-1.35 3-3-1.35-3-3-3zm0-2c.28 0 .5-.22.5-.5v-3c0-.28-.22-.5-.5-.5s-.5.22-.5.5v3c0 .28.22.5 .5 .5zm0 13c-.28 0-.5.22-.5.5v3c0 .28.22.5 .5 .5s.5-.22 .5-.5v-3c0-.28-.22-.5-.5-.5z" />
                            </svg>
                            <svg class="moon-icon" viewBox="0 0 24 24">
                                <path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z" />
                            </svg>
                        </button>
                        <button class="notification-btn" aria-label="View notifications">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z" />
                            </svg>
                            <span class="notification-badge">3</span>
                        </button>
                        <div class="user-menu-container">
                            <button class="user-menu-btn">
                                <svg class="avatar-icon" viewBox="0 0 24 24">
                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                                </svg>
                                <span class="username"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                            </button>
                            <div class="user-dropdown">
                                <a href="settings-view.php" class="dropdown-item">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l-.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z" />
                                    </svg>
                                    Settings
                                </a>
                                <a href="../../views/auth/logout.php" class="dropdown-item">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z" />
                                    </svg>
                                    Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>
            <!-- Departments Section -->
            <section class="departments-section">
                <div class="section-header">
                    <div class="header-left">
                        <h2>Department Management</h2>
                        <p class="section-description">Manage your organization's departments</p>
                    </div>
                    <div class="header-right">
                        <button class="btn-primary" id="addDepartmentBtn">
                            <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" /></svg>
                            Add Department
                        </button>
                    </div>
                </div>
                <div class="departments-grid">
                    <?php foreach ($departments as $department): ?>
                        <div class="department-card" data-id="<?php echo htmlspecialchars($department['id']); ?>">
                            <div class="department-header">
                                <h3><?php echo htmlspecialchars($department['name']); ?></h3>
                                <div class="status-badge">
                                    <span class="badge badge-active">Active</span>
                                </div>
                            </div>
                            <p class="department-description"><?php echo htmlspecialchars($department['description']); ?></p>
                            <div class="department-meta">
                                <div class="meta-info">
                                    <span><strong>Head:</strong> <?php echo htmlspecialchars($department['head']); ?></span>
                                    <span><strong>Employees:</strong> <?php echo $department['employee_count']; ?></span>
                                    <span><strong>Budget:</strong> $<?php echo number_format($department['budget']); ?></span>
                                </div>
                            </div>
                            <div class="department-actions">
                                <button class="btn-icon edit-department-btn" data-department-id="<?php echo htmlspecialchars($department['id']); ?>" aria-label="Edit department">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" />
                                    </svg>
                                </button>
                                <button class="btn-icon delete-department-btn" data-department-id="<?php echo htmlspecialchars($department['id']); ?>" aria-label="Delete department">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
    <!-- Mobile menu toggle -->
    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
        <svg viewBox="0 0 24 24" width="24" height="24">
            <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" />
        </svg>
    </button>
    <!-- Modal Templates -->
    <!-- Add Department Modal -->
    <div class="modal" id="addDepartmentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Department</h2>
                <button class="close-modal" aria-label="Close modal">×</button>
            </div>
            <form id="addDepartmentForm" class="modal-body">
                <input type="hidden" name="action" value="add_department">
                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description"></textarea>
                </div>
                <div class="form-group">
                    <label for="head">Head</label>
                    <input type="text" id="head" name="head">
                </div>
                <div class="form-group">
                    <label for="budget">Budget ($)</label>
                    <input type="number" id="budget" name="budget" step="0.01" min="0" value="0" required>
                </div>
                <div class="form-group">
                    <label for="employee_count">Employee Count</label>
                    <input type="number" id="employee_count" name="employee_count" min="0" value="0" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary" id="addDepartmentSubmit">Add Department</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Edit Department Modal -->
    <div class="modal" id="editDepartmentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Department</h2>
                <button class="close-modal" aria-label="Close modal">×</button>
            </div>
            <form id="editDepartmentForm" class="modal-body">
                <input type="hidden" name="action" value="edit_department">
                <input type="hidden" id="editDepartmentId" name="department_id">
                <div class="form-group">
                    <label for="editName">Name</label>
                    <input type="text" id="editName" name="name" required>
                </div>
                <div class="form-group">
                    <label for="editDescription">Description</label>
                    <textarea id="editDescription" name="description"></textarea>
                </div>
                <div class="form-group">
                    <label for="editHead">Head</label>
                    <input type="text" id="editHead" name="head">
                </div>
                <div class="form-group">
                    <label for="editBudget">Budget ($)</label>
                    <input type="number" id="editBudget" name="budget" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label for="editEmployeeCount">Employee Count</label>
                    <input type="number" id="editEmployeeCount" name="employee_count" min="0" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary" id="editDepartmentSubmit">Save Changes</button>
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

            // Add animation to department cards
            const departmentCards = document.querySelectorAll('.department-card');
            departmentCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 100 * index);
            });

            // Add Department Modal
            const addDepartmentBtn = document.getElementById('addDepartmentBtn');
            const addDepartmentModal = document.getElementById('addDepartmentModal');
            if (addDepartmentBtn) {
                addDepartmentBtn.addEventListener('click', function() {
                    openModal(addDepartmentModal);
                    document.getElementById('addDepartmentForm').reset();
                });
            }

            // Handle Add Department Form Submission
            const addDepartmentForm = document.getElementById('addDepartmentForm');
            const addDepartmentSubmit = document.getElementById('addDepartmentSubmit');
            if (addDepartmentForm) {
                addDepartmentForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    addDepartmentSubmit.disabled = true;
                    addDepartmentSubmit.textContent = 'Adding...';
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
                        addDepartmentSubmit.disabled = false;
                        addDepartmentSubmit.textContent = 'Add Department';
                        if (data.success) {
                            alert(data.message);
                            closeModal(addDepartmentModal);
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        addDepartmentSubmit.disabled = false;
                        addDepartmentSubmit.textContent = 'Add Department';
                        alert('An error occurred while adding the department: ' + error.message);
                    });
                });
            }

            // Edit Department Buttons
            document.querySelectorAll('.edit-department-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const departmentId = this.dataset.department_id;
                    const department = <?php echo json_encode($departments); ?>.find(d => d.id === departmentId);
                    if (department) {
                        const editModal = document.getElementById('editDepartmentModal');
                        document.getElementById('editDepartmentId').value = department.id;
                        document.getElementById('editName').value = department.name;
                        document.getElementById('editDescription').value = department.description || '';
                        document.getElementById('editHead').value = department.head || '';
                        document.getElementById('editBudget').value = department.budget;
                        document.getElementById('editEmployeeCount').value = department.employee_count;
                        openModal(editModal);
                    }
                });
            });

            // Handle Edit Department Form Submission
            const editDepartmentForm = document.getElementById('editDepartmentForm');
            const editDepartmentSubmit = document.getElementById('editDepartmentSubmit');
            if (editDepartmentForm) {
                editDepartmentForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    editDepartmentSubmit.disabled = true;
                    editDepartmentSubmit.textContent = 'Saving...';
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
                        editDepartmentSubmit.disabled = false;
                        editDepartmentSubmit.textContent = 'Save Changes';
                        if (data.success) {
                            alert(data.message);
                            closeModal(document.getElementById('editDepartmentModal'));
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        editDepartmentSubmit.disabled = false;
                        editDepartmentSubmit.textContent = 'Save Changes';
                        alert('An error occurred while updating the department: ' + error.message);
                    });
                });
            }

            // Delete Department Buttons
            document.querySelectorAll('.delete-department-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const departmentId = this.dataset.department_id;
                    if (confirm('Are you sure you want to delete this department?')) {
                        const formData = new FormData();
                        formData.append('action', 'delete_department');
                        formData.append('delete_id', departmentId);
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
                            if (data.success) {
                                alert(data.message);
                                location.reload();
                            } else {
                                alert('Error: ' + data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while deleting the department: ' + error.message);
                        });
                    }
                });
            });

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