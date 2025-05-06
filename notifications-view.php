<?php
session_start();
require_once __DIR__ . '/../../php/config/JsonDatabase.php';

// Check authentication and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Notification Handler Class
class NotificationHandler {
    private $dataFile = '../../database/json/notifications.json';

    public function __construct() {
        if (!file_exists($this->dataFile)) {
            file_put_contents($this->dataFile, json_encode(['notifications' => []]));
        }
    }

    // Delete a notification by ID
    public function deleteNotification($notificationId) {
        try {
            // Read current notifications
            $jsonContent = file_get_contents($this->dataFile);
            $data = json_decode($jsonContent, true);
            
            // Filter out the notification with the given ID
            $data['notifications'] = array_filter(
                $data['notifications'],
                function($notification) use ($notificationId) {
                    return $notification['id'] !== $notificationId;
                }
            );

            // Reindex array and save back to file
            $data['notifications'] = array_values($data['notifications']);
            file_put_contents($this->dataFile, json_encode($data, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            throw new Exception("Error deleting notification: " . $e->getMessage());
        }
    }

    // Mark a notification as read by ID
    public function markAsRead($notificationId) {
        try {
            // Read current notifications
            $jsonContent = file_get_contents($this->dataFile);
            $data = json_decode($jsonContent, true);
            
            // Find and update the notification
            foreach ($data['notifications'] as &$notification) {
                if ($notification['id'] === $notificationId) {
                    $notification['read'] = true;
                    break;
                }
            }

            // Save updated data back to file
            file_put_contents($this->dataFile, json_encode($data, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            throw new Exception("Error marking notification as read: " . $e->getMessage());
        }
    }

    public function handleFormSubmission() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                if (isset($_POST['delete_id'])) {
                    $this->deleteNotification($_POST['delete_id']);
                    $_SESSION['success'] = "Notification deleted successfully";
                } elseif (isset($_POST['mark_read'])) {
                    $this->markAsRead($_POST['mark_read']);
                    $_SESSION['success'] = "Notification marked as read";
                } else {
                    $notificationData = [
                        'title' => $_POST['title'],
                        'message' => $_POST['message'],
                        'priority' => $_POST['priority'],
                        'type' => $_POST['type']
                    ];                    
                }
            } catch (Exception $e) {
                $_SESSION['error'] = $e->getMessage();
            }
            header("Location: ".$_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// Instantiate handler and process submissions
$handler = new NotificationHandler();
$handler->handleFormSubmission();

// Load JSON data
$jsonDb = JsonDatabase::getInstance();
$notifications = $jsonDb->query('notifications')['notifications'] ?? [];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Task Management System</title>
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
                <li>
                    <a href="departments-view.php">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 2L4.5 20.29l.71.71L12 18l6.79 3 .71-.71z" />
                        </svg>
                        Departments
                    </a>
                </li>
                <li class="active">
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
                            <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22 l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l-.36 2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z" />
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
                    <h1>Notifications</h1>
                </div>
                <div class="header-right">
                    <div class="header-icons">
                        <button id="themeToggle" class="theme-toggle" aria-label="Toggle theme">
                            <svg class="sun-icon" viewBox="0 0 24 24">
                                <path d="M12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.65 0-3 1.35-3 3s1.35 3 3 3 3-1.35 3-3-1.35-3-3-3zm0-2c.28 0 .5-.22.5-.5v-3c0-.28-.22-.5-.5-.5s-.5.22-.5.5v3c0 .28.22.5.5.5zm0 13c-.28 0-.5.22-.5.5v3c0 .28.22.5.5.5s.5-.22.5-.5v-3c0-.28-.22-.5-.5-.5z" />
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
                                        <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l-.36 2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.03-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z" />
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
            <!-- Notifications Section -->
            <section class="notifications-section">
                <div class="section-header">
                    <h2>Notifications</h2>
                    <button class="btn-primary" id="markAllReadBtn">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z" />
                        </svg>
                        Mark All as Read
                    </button>
                </div>
                <div class="notifications-list">
                    <?php foreach ($notifications as $notification) : ?>
                        <div class="notification-item <?php echo $notification['read'] ? 'read' : 'unread'; ?>">
                            <div class="notification-icon">
                                <svg viewBox="0 0 24 24">
                                    <?php if ($notification['type'] === 'project') : ?>
                                        <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zM7 10h5v5H7z" />
                                    <?php elseif ($notification['type'] === 'meeting') : ?>
                                        <path d="M17 10h-4v4h4v-4zM7 10h4v4H7v-4zM17 6h-4v4h4V6zM7 6h4v4H7V6z" />
                                    <?php else : ?>
                                        <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z" />
                                    <?php endif; ?>
                                </svg>
                            </div>
                            <div class="notification-content">
                                <h3><?php echo htmlspecialchars($notification['title']); ?></h3>
                                <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                <div class="notification-meta">
                                    <span class="priority-<?php echo $notification['priority']; ?>"><?php echo ucfirst($notification['priority']); ?> priority</span>
                                    <span><?php echo date('M d, Y H:i', strtotime($notification['created_at'])); ?></span>
                                </div>
                            </div>
                            <div class="notification-actions">
                                <?php if (!$notification['read']) : ?>
                                    <button class="btn-icon mark-read" aria-label="Mark as read">
                                        <svg viewBox="0 0 24 24">
                                            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
                                        </svg>
                                    </button>
                                <?php endif; ?>
                                <button class="btn-icon delete-notification" aria-label="Delete notification">
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
    <!-- Scripts -->
    <script src="../../assets/js/theme.js"></script>
    <script src="../../assets/js/dashboard.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Additional event for mobile menu toggle
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.querySelector('.sidebar');

            if (menuToggle) {
                menuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show-mobile');
                });
            }
        });
    </script>
</body>

</html>