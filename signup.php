<?php
session_start();
require_once __DIR__ . '/../../php/config/JsonDatabase.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

// Process signup form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    // Validate required fields
    if (!$username || !$email || !$password || !$confirm_password || !$role) {
        $error = 'Please fill in all fields';
    }
    // Validate email format
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    }
    // Validate password match
    elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    }
    // Validate role
    elseif (!in_array($role, ['hr', 'employee'])) {
        $error = 'Invalid role selected';
    }
    else {
        try {
            $db = JsonDatabase::getInstance();
            
            // Check if email already exists
            $existing_user = $db->query('users', ['email' => $email]);
            if (!empty($existing_user)) {
                $error = 'Email already exists';
            } else {
                // Create new user
                $new_user = [
                    'username' => $username,
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                    'created_at' => date('Y-m-d H:i:s'),
                    'last_login' => null
                ];
                
                $user_id = $db->insert('users', $new_user);
                
                if ($user_id) {
                    // Set session variables
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['role'] = $role;
                    
                    // Redirect based on role
                    switch ($role) {
                        case 'hr':
                            header('Location: ../../views/hr/dashboard.php');
                            break;
                        case 'employee':
                            header('Location: ../../views/employee/dashboard.php');
                            break;
                        default:
                            throw new Exception('Invalid role');
                    }
                    exit;
                } else {
                    throw new Exception('Failed to create user');
                }
            }
        } catch (Exception $e) {
            error_log('Signup error: ' . $e->getMessage());
            $error = 'An error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Task Management System</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <link rel="stylesheet" href="../../assets/css/auth.css">
</head>
<body class="auth-page">
    <div class="theme-toggle-wrapper">
        <button id="themeToggle" class="theme-toggle" aria-label="Toggle theme">
            <svg class="sun-icon" viewBox="0 0 24 24"><path d="M12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.65 0-3 1.35-3 3s1.35 3 3 3 3-1.35 3-3-1.35-3-3-3zm0-2c.28 0 .5-.22.5-.5v-3c0-.28-.22-.5-.5-.5s-.5.22-.5.5v3c0 .28.22.5.5.5zm0 13c-.28 0-.5.22-.5.5v3c0 .28.22.5.5.5s.5-.22.5-.5v-3c0-.28-.22-.5-.5-.5z"/></svg>
            <svg class="moon-icon" viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z"/></svg>
        </button>
    </div>

    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>Task Management System</h1>
                <p>Create your account</p>
            </div>

            <form id="signupForm" class="auth-form" method="post">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required
                           placeholder="Enter your username">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required
                           placeholder="Enter your email">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="password" name="password" required
                               placeholder="Enter your password">
                        <button type="button" class="toggle-password" aria-label="Toggle password visibility">
                            <svg class="eye-icon" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" required
                               placeholder="Confirm your password">
                        <button type="button" class="toggle-password" aria-label="Toggle password visibility">
                            <svg class="eye-icon" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" required>
                        <option value="">Select your role</option>
                        <option value="hr">HR</option>
                        <option value="employee">Employee</option>
                    </select>
                </div>

                <?php if (isset($error) || isset($_GET['error'])): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error ?? $_GET['error']); ?>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn-primary">Sign Up</button>

                <div class="auth-links">
                    <p>Already have an account? <a href="login.php">Sign In</a></p>
                </div>
            </form>
        </div>
    </div>

    <script src="../../assets/js/theme.js"></script>
    <script src="../../assets/js/auth.js"></script>
</body>
</html>