<?php
/**
 * Screen 01: Authentication & Role-Based Login Gateway
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// If already authenticated, redirect to catalog
if (isLoggedIn()) {
    header('Location: catalog.php');
    exit;
}

$errorMessage = '';
$successMessage = '';

// Check for redirect flash messages
$flashes = getFlashes();
if (!empty($flashes['error'])) {
    $errorMessage = $flashes['error'];
}
if (!empty($flashes['success'])) {
    $successMessage = $flashes['success'];
}
if (!empty($flashes['info'])) {
    $successMessage = $flashes['info'];
}

$identifier = '';
$selectedRole = 'student';

// Handle POST authentication request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        $errorMessage = 'Security Token Validation Failed. Please refresh and try again.';
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $selectedRole = trim((string)($_POST['role'] ?? 'student'));

        if (empty($identifier) || empty($password)) {
            $errorMessage = 'Please provide both your Institutional Email / Student ID and Password.';
        } else {
            $pdo = getDBConnection();
            // Lookup user by either institutional email or student ID number
            $stmt = $pdo->prepare("
                SELECT user_id, id_number, full_name, email, password_hash, role, cert_tier, department
                FROM users
                WHERE email = ? OR id_number = ?
                LIMIT 1
            ");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $errorMessage = 'Invalid institutional credentials. Please check your Student/Staff ID, email, or password.';
            } elseif ($user['role'] !== $selectedRole) {
                $errorMessage = sprintf(
                    'Access Mismatch: This account is registered under "%s". Please select the correct role toggle.',
                    getRoleLabel($user['role'])
                );
            } else {
                // Successful verification
                loginUser($user);

                // Create audit notification or flash
                setFlash('success', 'Authentication successful. Welcome, ' . $user['full_name'] . '!');

                // Redirect based on role
                if ($user['role'] === 'supervisor') {
                    header('Location: approvals.php');
                } elseif ($user['role'] === 'admin') {
                    header('Location: catalog.php');
                } else {
                    header('Location: catalog.php');
                }
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Sign-In Portal | LERS Academic Gateway</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-body">

<!-- Institutional Header Bar (Screen 01) -->
<header class="auth-header-bar">
    <div class="auth-brand">
        <a href="login.php">
            <span>🔬</span>
            <span>LERS | Academic Laboratory Portal</span>
        </a>
    </div>
    <div class="auth-meta-links">
        <span class="security-tag">
            <span>🔒</span>
            <span>SSL/TLS Secured Session</span>
        </span>
        <a href="#helpModal" onclick="alert('Laboratory Equipment Reservation System\nUniversity Faculty Support: lab-admin@science.ac.lk\nHours: Mon-Fri 08:00 - 18:00'); return false;">
            Help & Documentation
        </a>
    </div>
</header>

<!-- Main Centered Sign-In Portal -->
<main class="auth-main-container">
    <div class="auth-card">
        <div class="auth-card-header">
            <h1 class="auth-title">USER SIGN-IN PORTAL</h1>
            <p class="auth-subtitle">Enter faculty institutional credentials</p>
        </div>

        <div class="auth-card-body">
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-error" style="margin-bottom: 1.25rem;">
                    <span class="alert-icon">✕</span>
                    <span><?= sanitize($errorMessage) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success" style="margin-bottom: 1.25rem;">
                    <span class="alert-icon">✓</span>
                    <span><?= sanitize($successMessage) ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" autocomplete="off">
                <?= csrfField() ?>

                <div class="auth-form-group">
                    <label for="identifier" class="auth-label">Institutional Email / Student ID:</label>
                    <input 
                        type="text" 
                        id="identifier" 
                        name="identifier" 
                        class="auth-input" 
                        placeholder="e.g., prishan.s@science.ac.lk or ST-2026-081" 
                        value="<?= sanitize($identifier) ?>" 
                        required 
                        autofocus
                    >
                </div>

                <div class="auth-form-group">
                    <label for="password" class="auth-label">Password:</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="auth-input" 
                        placeholder="••••••••••••" 
                        required
                    >
                </div>

                <!-- Select Role Type (Screen 01 Specification) -->
                <div class="role-picker-container">
                    <span class="role-picker-label">Select Role Type:</span>
                    <div class="role-radio-group">
                        <label class="role-radio-item">
                            <input type="radio" name="role" value="student" <?= ($selectedRole === 'student') ? 'checked' : '' ?>>
                            <span>Student / Researcher</span>
                        </label>
                        <label class="role-radio-item">
                            <input type="radio" name="role" value="supervisor" <?= ($selectedRole === 'supervisor') ? 'checked' : '' ?>>
                            <span>Lab Supervisor / Tech</span>
                        </label>
                        <label class="role-radio-item">
                            <input type="radio" name="role" value="admin" <?= ($selectedRole === 'admin') ? 'checked' : '' ?>>
                            <span>System Admin</span>
                        </label>
                    </div>
                </div>

                <!-- Session & Recovery Options -->
                <div class="auth-options-row">
                    <label class="remember-checkbox">
                        <input type="checkbox" name="remember" value="1" checked>
                        <span>Remember session</span>
                    </label>
                    <a href="#forgot" class="forgot-link" onclick="alert('To reset your institutional key or security PIN, please contact the Laboratory System Administrator at admin@science.ac.lk with your Student/Staff ID.'); return false;">
                        Forgot Key?
                    </a>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-auth-submit">
                    <span>🔒</span>
                    <span>SECURE LOGIN</span>
                </button>
            </form>

            <!-- Quick-Fill Demonstration Bar for Evaluator / Testing -->
            <div class="demo-quickfill-card">
                <div class="demo-quickfill-header">
                    <span>⚡ Quick-Fill Demo Profiles (For MSc IT Evaluation):</span>
                    <span style="font-weight:normal;color:#166534;">Password: <code>Password123!</code></span>
                </div>
                <div class="demo-buttons-row">
                    <button type="button" class="btn-demo-pill" 
                            data-email="prishan.s@science.ac.lk" 
                            data-password="Password123!" 
                            data-role="student">
                        🎓 Student (Prishan)
                    </button>
                    <button type="button" class="btn-demo-pill" 
                            data-email="supervisor@science.ac.lk" 
                            data-password="Password123!" 
                            data-role="supervisor">
                        🔬 Supervisor (Dr. Jayasuriya)
                    </button>
                    <button type="button" class="btn-demo-pill" 
                            data-email="admin@science.ac.lk" 
                            data-password="Password123!" 
                            data-role="admin">
                        🛡️ System Admin (Perera)
                    </button>
                </div>
            </div>
        </div>

        <div class="auth-card-footer">
            New researcher or student? 
            <a href="register.php">Register for Laboratory Access &rarr;</a>
        </div>
    </div>
</main>

<footer class="auth-page-footer">
    <p>Laboratory Equipment Reservation System &bull; Candidate: Prishan Samarasinghe (MSc in IT) &bull; September 2026</p>
</footer>

<script src="assets/js/app.js"></script>
</body>
</html>
