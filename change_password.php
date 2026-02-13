<?php
session_start();

// Include database connection
require_once 'config/database.php';
require_once 'includes/logActivity.php';
require_once 'includes/csrf.php';
require_once 'includes/validators.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$error = '';
$success = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate inputs
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "All fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } else {
            // Validate password strength
            $passwordValidation = validatePassword($new_password);
            if (!$passwordValidation['valid']) {
                $error = $passwordValidation['message'];
            } else {
                try {
                    $database = new Database();
                    $db = $database->getConnection();
                    
                    // Get current password hash
                    $query = "SELECT password_hash FROM users WHERE user_id = :user_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();
                    $user = $stmt->fetch();
                    
                    if (!$user) {
                        $error = "User not found.";
                    } else {
                        // Check both plain text and hashed password (for backward compatibility)
                        $password_verified = false;
                        
                        if ($user['password_hash'] === $current_password) {
                            // Plain text password match
                            $password_verified = true;
                        } elseif (password_verify($current_password, $user['password_hash'])) {
                            // Hashed password match
                            $password_verified = true;
                        }
                        
                        if (!$password_verified) {
                            $error = "Current password is incorrect.";
                        } else {
                            // Hash new password
                            $new_password_hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
                            
                            // Update password
                            $updateQuery = "UPDATE users SET password_hash = :password WHERE user_id = :user_id";
                            $updateStmt = $db->prepare($updateQuery);
                            $updateStmt->bindParam(':password', $new_password_hash);
                            $updateStmt->bindParam(':user_id', $user_id);
                            
                            if ($updateStmt->execute()) {
                                $success = "Password changed successfully!";
                                
                                // Log activity
                                logActivity($user_id, 'password_change', 'User changed their password');
                                
                                // Regenerate CSRF token
                                regenerateCSRFToken();
                            } else {
                                $error = "Failed to update password. Please try again.";
                            }
                        }
                    }
                } catch (Exception $e) {
                    $error = "An error occurred. Please try again later.";
                    error_log("Password change error: " . $e->getMessage());
                }
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
    <title>Change Password - PhoneStock Pro</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #1e3a5f;
            --primary-hover: #2c5282;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .password-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 500px;
            width: 100%;
            padding: 40px;
        }
        
        .password-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .password-header i {
            font-size: 50px;
            color: var(--primary);
            margin-bottom: 15px;
        }
        
        .password-header h2 {
            color: var(--primary);
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .password-header p {
            color: #6c757d;
            font-size: 14px;
        }
        
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        
        .form-control {
            border-radius: 8px;
            padding: 12px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(30, 58, 95, 0.15);
        }
        
        .btn-primary {
            background: var(--primary);
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(30, 58, 95, 0.3);
        }
        
        .btn-secondary {
            border-radius: 8px;
            padding: 12px;
            font-weight: 600;
        }
        
        .password-strength {
            height: 5px;
            border-radius: 3px;
            margin-top: 8px;
            background: #e0e0e0;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            transition: all 0.3s;
            width: 0%;
        }
        
        .strength-weak { background: #dc3545; width: 33%; }
        .strength-medium { background: #ffc107; width: 66%; }
        .strength-strong { background: #28a745; width: 100%; }
        
        .password-requirements {
            font-size: 12px;
            color: #6c757d;
            margin-top: 10px;
        }
        
        .password-requirements li {
            margin-bottom: 5px;
        }
        
        .password-requirements .valid {
            color: #28a745;
        }
        
        .password-requirements .invalid {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="password-card">
        <div class="password-header">
            <i class="fas fa-key"></i>
            <h2>Change Password</h2>
            <p>Update your account password</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="passwordForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="change_password" value="1">
            
            <div class="mb-4">
                <label class="form-label">Current Password</label>
                <div class="input-group">
                    <input type="password" class="form-control" name="current_password" id="current_password" required>
                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')">
                        <i class="fas fa-eye" id="current_password_icon"></i>
                    </button>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label">New Password</label>
                <div class="input-group">
                    <input type="password" class="form-control" name="new_password" id="new_password" required>
                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                        <i class="fas fa-eye" id="new_password_icon"></i>
                    </button>
                </div>
                <div class="password-strength">
                    <div class="password-strength-bar" id="strength-bar"></div>
                </div>
                <ul class="password-requirements" id="requirements">
                    <li id="req-length"><i class="fas fa-circle"></i> At least 8 characters</li>
                    <li id="req-upper"><i class="fas fa-circle"></i> One uppercase letter</li>
                    <li id="req-lower"><i class="fas fa-circle"></i> One lowercase letter</li>
                    <li id="req-number"><i class="fas fa-circle"></i> One number</li>
                </ul>
            </div>
            
            <div class="mb-4">
                <label class="form-label">Confirm New Password</label>
                <div class="input-group">
                    <input type="password" class="form-control" name="confirm_password" id="confirm_password" required>
                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                        <i class="fas fa-eye" id="confirm_password_icon"></i>
                    </button>
                </div>
                <small class="text-muted" id="match-message"></small>
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Change Password
                </button>
                <a href="<?php echo $user_role === 'admin' ? 'admin.php' : 'employee.php'; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '_icon');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Password strength checker
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('strength-bar');
            
            let strength = 0;
            const requirements = {
                length: password.length >= 8,
                upper: /[A-Z]/.test(password),
                lower: /[a-z]/.test(password),
                number: /[0-9]/.test(password)
            };
            
            // Update requirement indicators
            document.getElementById('req-length').className = requirements.length ? 'valid' : 'invalid';
            document.getElementById('req-upper').className = requirements.upper ? 'valid' : 'invalid';
            document.getElementById('req-lower').className = requirements.lower ? 'valid' : 'invalid';
            document.getElementById('req-number').className = requirements.number ? 'valid' : 'invalid';
            
            // Calculate strength
            Object.values(requirements).forEach(met => {
                if (met) strength++;
            });
            
            // Update strength bar
            strengthBar.className = 'password-strength-bar';
            if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
            } else if (strength === 3) {
                strengthBar.classList.add('strength-medium');
            } else {
                strengthBar.classList.add('strength-strong');
            }
        });
        
        // Password match checker
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            const message = document.getElementById('match-message');
            
            if (confirmPassword.length > 0) {
                if (newPassword === confirmPassword) {
                    message.textContent = '✓ Passwords match';
                    message.style.color = '#28a745';
                } else {
                    message.textContent = '✗ Passwords do not match';
                    message.style.color = '#dc3545';
                }
            } else {
                message.textContent = '';
            }
        });
    </script>
</body>
</html>
