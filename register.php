<?php
/**
 * User Sign-In / Account Registration Portal
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: catalog.php');
    exit;
}

$errorMessage = '';
$successMessage = '';

$formData = [
    'full_name'   => '',
    'email'       => '',
    'id_number'   => '',
    'role'        => 'student',
    'department'  => 'Department of Physics & Nanotechnology',
    'cert_tier'   => 'Tier 1'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        $errorMessage = 'Security Token Expired. Please reload and resubmit.';
    } else {
        $formData['full_name']  = trim((string)($_POST['full_name'] ?? ''));
        $formData['email']      = trim((string)($_POST['email'] ?? ''));
        $formData['id_number']  = trim((string)($_POST['id_number'] ?? ''));
        $formData['role']       = trim((string)($_POST['role'] ?? 'student'));
        $formData['department'] = trim((string)($_POST['department'] ?? ''));
        $formData['cert_tier']  = trim((string)($_POST['cert_tier'] ?? 'Tier 1'));
        
        $password        = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $safetyAgreement = isset($_POST['safety_agreement']);

        // Input Validations
        if (empty($formData['full_name']) || empty($formData['email']) || empty($formData['id_number'])) {
            $errorMessage = 'Please complete all required identity credentials.';
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Please provide a valid institutional email address.';
        } elseif (strlen($password) < 6) {
            $errorMessage = 'Security policy requires a password of at least 6 characters.';
        } elseif ($password !== $confirmPassword) {
            $errorMessage = 'The entered passwords do not match. Please verify.';
        } elseif (!$safetyAgreement) {
            $errorMessage = 'You must confirm adherence to the Laboratory Safety Protocols.';
        } else {
            $pdo = getDBConnection();

            // Duplicate Verification
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR id_number = ?");
            $stmt->execute([$formData['email'], $formData['id_number']]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errorMessage = 'An account with this Institutional Email or Student/Staff ID already exists.';
            } else {
                // Bcrypt hash password
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);

                $insertStmt = $pdo->prepare("
                    INSERT INTO users (id_number, full_name, email, password_hash, role, cert_tier, department, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                $insertStmt->execute([
                    $formData['id_number'],
                    $formData['full_name'],
                    $formData['email'],
                    $passwordHash,
                    $formData['role'],
                    $formData['cert_tier'],
                    $formData['department']
                ]);

                $newUserId = (int)$pdo->lastInsertId();

                // Generate system welcome notification
                createNotification(
                    $newUserId,
                    'Account Profile Provisioned',
                    sprintf('Welcome %s! Your account has been provisioned with %s privileges and %s clearance.', 
                        $formData['full_name'], 
                        getRoleLabel($formData['role']), 
                        $formData['cert_tier']
                    ),
                    'system'
                );

                setFlash('success', 'Registration successful! Your credentials are now active. Please sign in below.');
                header('Location: login.php');
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
    <title>Researcher Registration | LERS Academic Gateway</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-body">

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
        <a href="login.php">Back to Sign-In Gateway</a>
    </div>
</header>

<main class="auth-main-container">
    <div class="auth-card auth-card-wide">
        <div class="auth-card-header">
            <h1 class="auth-title">RESEARCHER & STUDENT REGISTRATION</h1>
            <p class="auth-subtitle">Create verified academic laboratory reservation credentials</p>
        </div>

        <div class="auth-card-body">
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-error" style="margin-bottom: 1.25rem;">
                    <span class="alert-icon">✕</span>
                    <span><?= sanitize($errorMessage) ?></span>
                </div>
            <?php endif; ?>

            <form action="register.php" method="POST">
                <?= csrfField() ?>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="auth-form-group">
                        <label for="full_name" class="auth-label">Full Name & Title: *</label>
                        <input 
                            type="text" 
                            id="full_name" 
                            name="full_name" 
                            class="auth-input" 
                            placeholder="e.g., Kasun Bandara" 
                            value="<?= sanitize($formData['full_name']) ?>" 
                            required
                        >
                    </div>

                    <div class="auth-form-group">
                        <label for="id_number" class="auth-label">Student ID / Staff ID: *</label>
                        <input 
                            type="text" 
                            id="id_number" 
                            name="id_number" 
                            class="auth-input" 
                            placeholder="e.g., ST-2026-112 or SUP-4022" 
                            value="<?= sanitize($formData['id_number']) ?>" 
                            required
                        >
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="auth-form-group">
                        <label for="email" class="auth-label">Institutional Email: *</label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="auth-input" 
                            placeholder="e.g., k.bandara@science.ac.lk" 
                            value="<?= sanitize($formData['email']) ?>" 
                            required
                        >
                    </div>

                    <div class="auth-form-group">
                        <label for="department" class="auth-label">Academic Department: *</label>
                        <select id="department" name="department" class="auth-select">
                            <option value="Department of Physics & Nanotechnology" <?= ($formData['department'] === 'Department of Physics & Nanotechnology') ? 'selected' : '' ?>>
                                Physics & Nanotechnology
                            </option>
                            <option value="Department of Chemistry" <?= ($formData['department'] === 'Department of Chemistry') ? 'selected' : '' ?>>
                                Department of Chemistry
                            </option>
                            <option value="Molecular Biology & Biotechnology" <?= ($formData['department'] === 'Molecular Biology & Biotechnology') ? 'selected' : '' ?>>
                                Molecular Biology & Biotech
                            </option>
                            <option value="School of Computing & HPC Labs" <?= ($formData['department'] === 'School of Computing & HPC Labs') ? 'selected' : '' ?>>
                                School of Computing & HPC
                            </option>
                            <option value="Materials & Chemical Engineering" <?= ($formData['department'] === 'Materials & Chemical Engineering') ? 'selected' : '' ?>>
                                Materials Engineering
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Role Selection -->
                <div class="role-picker-container">
                    <span class="role-picker-label">Designated Stakeholder Role:</span>
                    <div class="role-radio-group">
                        <label class="role-radio-item">
                            <input type="radio" name="role" value="student" <?= ($formData['role'] === 'student') ? 'checked' : '' ?>>
                            <span>Student / Researcher</span>
                        </label>
                        <label class="role-radio-item">
                            <input type="radio" name="role" value="supervisor" <?= ($formData['role'] === 'supervisor') ? 'checked' : '' ?>>
                            <span>Lab Supervisor / Technician</span>
                        </label>
                        <label class="role-radio-item">
                            <input type="radio" name="role" value="admin" <?= ($formData['role'] === 'admin') ? 'checked' : '' ?>>
                            <span>System Administrator</span>
                        </label>
                    </div>
                </div>

                <div class="auth-form-group">
                    <label for="cert_tier" class="auth-label">Active Safety Certification Clearance Tier:</label>
                    <select id="cert_tier" name="cert_tier" class="auth-select">
                        <option value="Tier 1" <?= ($formData['cert_tier'] === 'Tier 1') ? 'selected' : '' ?>>Tier 1: General Laboratory Safety & Spectrometry Basics</option>
                        <option value="Tier 2" <?= ($formData['cert_tier'] === 'Tier 2') ? 'selected' : '' ?>>Tier 2: Chemical Hygiene, Bio-Safety & Centrifugation</option>
                        <option value="Tier 3" <?= ($formData['cert_tier'] === 'Tier 3') ? 'selected' : '' ?>>Tier 3: Laser Safety, High-Vacuum & Electron Microscopy (TEM/SEM)</option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="auth-form-group">
                        <label for="password" class="auth-label">Create Password: *</label>
                        <input type="password" id="password" name="password" class="auth-input" placeholder="Min. 6 characters" required>
                    </div>

                    <div class="auth-form-group">
                        <label for="confirm_password" class="auth-label">Confirm Password: *</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="auth-input" placeholder="Re-enter password" required>
                    </div>
                </div>

                <div style="margin: 1rem 0 1.5rem 0;">
                    <label class="remember-checkbox" style="align-items: flex-start;">
                        <input type="checkbox" name="safety_agreement" value="1" required style="margin-top: 3px;">
                        <span style="font-size: 0.85rem; color: #475569;">
                            I hereby confirm that I have undergone institutional laboratory induction and agree to comply strictly with university lab safety clearance protocols.
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn-auth-submit">
                    <span>📝</span>
                    <span>CREATE ACCOUNT & REQUEST ACCESS</span>
                </button>
            </form>
        </div>

        <div class="auth-card-footer">
            Already holding active laboratory credentials? 
            <a href="login.php">&larr; Return to Sign-In Portal</a>
        </div>
    </div>
</main>

<footer class="auth-page-footer">
    <p>Laboratory Equipment Reservation System &bull; Candidate: Prishan Samarasinghe (MSc in IT)</p>
</footer>

<script src="assets/js/app.js"></script>
</body>
</html>
