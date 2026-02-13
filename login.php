<?php
// Check maintenance mode
require_once 'includes/maintenance.php';

// Start session
session_start();

// Database configuration
require_once 'config/database.php';

// Initialize variables
$error = '';
$success = '';

// Initialize rate limiting variables
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

// Check if account is locked
$lockout_duration = 900; // 15 minutes in seconds
$max_attempts = 5;

if ($_SESSION['login_attempts'] >= $max_attempts) {
    $time_since_last_attempt = time() - $_SESSION['last_attempt_time'];
    
    if ($time_since_last_attempt < $lockout_duration) {
        $remaining_time = ceil(($lockout_duration - $time_since_last_attempt) / 60);
        $error = "Too many failed login attempts. Please try again in {$remaining_time} minute(s).";
        $is_locked = true;
    } else {
        // Reset after lockout period
        $_SESSION['login_attempts'] = 0;
        $is_locked = false;
    }
} else {
    $is_locked = false;
}

// Handle logout success message
if (isset($_GET['logout_success'])) {
    $success = 'You have been successfully logged out.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_locked) {
    $role = $_POST['role'] ?? '';
    
if ($role === 'admin') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Validate admin login
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password for admin access.';
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $query = "SELECT * FROM users WHERE username = :username AND role = 'admin' AND is_active = 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Check both plain text and hashed password
                $password_verified = false;
                
                if ($user['password_hash'] === $password) {
                    $password_verified = true;
                }
                else if (password_verify($password, $user['password_hash'])) {
                    $password_verified = true;
                }
                
                if ($password_verified) {
                    // SUCCESSFUL LOGIN - Reset attempts
                    $_SESSION['login_attempts'] = 0;
                    
                    // Set session variables
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['logged_in'] = true;
                    
                    // Update last login
                    $updateQuery = "UPDATE users SET last_login = NOW() WHERE user_id = :user_id";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':user_id', $user['user_id']);
                    $updateStmt->execute();
                    
                    // Log activity
                    $logQuery = "INSERT INTO activity_log (user_id, activity_type, description, ip_address) 
                                 VALUES (:user_id, 'login', 'Admin logged in', :ip_address)";
                    $logStmt = $db->prepare($logQuery);
                    $logStmt->bindParam(':user_id', $user['user_id']);
                    $logStmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR']);
                    $logStmt->execute();
                    
                    // Redirect to admin dashboard
                    header('Location: admin.php');
                    exit();
                } else {
                    // FAILED LOGIN - Increment attempts
                    $_SESSION['login_attempts']++;
                    $_SESSION['last_attempt_time'] = time();
                    
                    $remaining_attempts = $max_attempts - $_SESSION['login_attempts'];
                    if ($remaining_attempts > 0) {
                        $error = "Invalid password. {$remaining_attempts} attempt(s) remaining.";
                    } else {
                        $error = "Too many failed attempts. Account locked for 15 minutes.";
                    }
                }
            } else {
                // FAILED LOGIN - Increment attempts
                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt_time'] = time();
                
                $remaining_attempts = $max_attempts - $_SESSION['login_attempts'];
                if ($remaining_attempts > 0) {
                    $error = "Admin username not found. {$remaining_attempts} attempt(s) remaining.";
                } else {
                    $error = "Too many failed attempts. Account locked for 15 minutes.";
                }
            }
        } catch(PDOException $e) {
            $error = 'Database error. Please try again later.';
        }
    }
}

elseif ($role === 'employee') {
        $employee_name = $_POST['employee_name'] ?? '';
        
        if (empty($employee_name)) {
            $error = 'Please enter your name to continue as employee.';
        } else {
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                // Check if employee exists
                $query = "SELECT * FROM users WHERE full_name LIKE :name AND role = 'employee' AND is_active = 1";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':name', '%' . $employee_name . '%');
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Set session variables
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['logged_in'] = true;
                    
                    // Update last login
                    $updateQuery = "UPDATE users SET last_login = NOW() WHERE user_id = :user_id";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':user_id', $user['user_id']);
                    $updateStmt->execute();
                    
                    // Log activity
                    $logQuery = "INSERT INTO activity_log (user_id, activity_type, description, ip_address) 
                                 VALUES (:user_id, 'login', 'Employee logged in', :ip_address)";
                    $logStmt = $db->prepare($logQuery);
                    $logStmt->bindParam(':user_id', $user['user_id']);
                    $logStmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR']);
                    $logStmt->execute();
                    
                    // Redirect to employee dashboard
                    header('Location: employee.php');
                    exit();
                } else {
                    $error = 'Employee account not found. Please contact your administrator.';
                }
            } catch(PDOException $e) {
                $error = 'Database error. Please try again later.';
            }
        }
    } else {
        $error = 'Please select a role.';
    }
}

// Check if user is already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin.php');
    } else {
        header('Location: employee.php');
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PhoneStock Pro</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #1e3a5f;
            --primary-dark: #2c5282;
            --primary-light: #3182ce;
            --secondary: #2f855a;
            --danger: #c53030;
            --warning: #b7791f;
            --dark: #1a202c;
            --gray: #4a5568;
            --light: #f7fafc;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1e3a5f 0%, #2c5282 100%);
            position: relative;
            overflow: hidden;
            padding: 20px;
        }
        
        /* Animated Background */
        body::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background: 
                radial-gradient(circle at 20% 50%, rgba(49, 130, 206, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(44, 82, 130, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 40% 20%, rgba(30, 58, 95, 0.3) 0%, transparent 50%);
            animation: gradientShift 15s ease infinite;
        }
        
        @keyframes gradientShift {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            50% { transform: translate(-50px, -50px) rotate(180deg); }
        }
        
        /* Floating Particles */
        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            pointer-events: none;
            animation: float 20s infinite;
        }
        
        .particle:nth-child(1) { width: 80px; height: 80px; left: 10%; top: 20%; animation-delay: 0s; }
        .particle:nth-child(2) { width: 60px; height: 60px; left: 80%; top: 60%; animation-delay: 2s; }
        .particle:nth-child(3) { width: 100px; height: 100px; left: 50%; top: 80%; animation-delay: 4s; }
        .particle:nth-child(4) { width: 40px; height: 40px; left: 70%; top: 10%; animation-delay: 6s; }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); opacity: 0.3; }
            50% { transform: translateY(-100px) rotate(180deg); opacity: 0.6; }
        }
        
        /* Main Container */
        .login-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 1100px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 650px;
        }
        
        /* Left Side - Branding */
        .login-left {
            background: linear-gradient(135deg, #1e3a5f 0%, #2c5282 100%);
            padding: 60px 50px;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        .login-left::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 40px 40px;
            animation: patternMove 30s linear infinite;
        }
        
        @keyframes patternMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(40px, 40px); }
        }
        
        .brand-content {
            position: relative;
            z-index: 2;
        }
        
        .brand-logo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 40px;
            font-size: 32px;
            font-weight: 800;
        }
        
        .brand-logo i {
            font-size: 42px;
            background: linear-gradient(135deg, #fff 0%, #f0f0f0 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
        }
        
        .brand-title {
            font-size: 48px;
            font-weight: 900;
            line-height: 1.2;
            margin-bottom: 20px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .brand-subtitle {
            font-size: 18px;
            opacity: 0.95;
            line-height: 1.6;
            margin-bottom: 40px;
        }
        
        .features {
            list-style: none;
        }
        
        .features li {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            font-size: 16px;
            opacity: 0.95;
        }
        
        .features i {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        /* Right Side - Form */
        .login-right {
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .login-header h2 {
            font-size: 32px;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 10px;
        }
        
        .login-header p {
            color: var(--gray);
            font-size: 16px;
        }
        
        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--secondary);
        }
        
        /* Role Selection */
        .role-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 35px;
        }
        
        .role-tab {
            padding: 20px;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
        }
        
        .role-tab:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.15);
        }
        
        .role-tab.active {
            border-color: var(--primary);
            background: linear-gradient(135deg, #f0f4ff 0%, #e0e7ff 100%);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.2);
        }
        
        .role-tab.active.employee-tab {
            border-color: var(--secondary);
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.2);
        }
        
        .role-icon {
            font-size: 32px;
            margin-bottom: 12px;
            color: var(--primary);
        }
        
        .role-tab.active.employee-tab .role-icon {
            color: var(--secondary);
        }
        
        .role-tab h4 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--dark);
        }
        
        .role-tab p {
            font-size: 13px;
            color: var(--gray);
            margin: 0;
        }
        
        /* Form Styles */
        .login-form {
            display: none;
        }
        
        .login-form.active {
            display: block;
            animation: fadeIn 0.4s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .form-label i {
            color: var(--primary);
        }
        
        .form-control {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }
        
        .employee-form .form-control:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
        }
        
        .form-control::placeholder {
            color: #94a3b8;
        }
        
        .form-text {
            font-size: 13px;
            color: var(--gray);
            margin-top: 6px;
            display: block;
        }
        
        .show-password {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            font-size: 14px;
            color: var(--gray);
            cursor: pointer;
        }
        
        .show-password input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        /* Buttons */
        .btn-login {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .btn-admin {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
        }
        
        .btn-admin:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.5);
        }
        
        .btn-employee {
            background: linear-gradient(135deg, var(--secondary) 0%, #059669 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        
        .btn-employee:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.5);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        /* Info Box */
        .info-box {
            margin-top: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px;
            border-left: 4px solid var(--primary);
        }
        
        .info-box h6 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 12px;
            font-size: 14px;
        }
        
        .info-box h6 i {
            color: var(--primary);
        }
        
        .info-box p {
            font-size: 13px;
            color: var(--gray);
            margin: 6px 0;
        }
        
        .info-box strong {
            color: var(--dark);
        }
        
        .text-danger {
            color: var(--danger) !important;
        }
        
        /* Loading Spinner */
        .spinner {
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: none;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 968px) {
            .login-container {
                grid-template-columns: 1fr;
                max-width: 500px;
            }
            
            .login-left {
                display: none;
            }
            
            .login-right {
                padding: 40px 30px;
            }
        }
        
        @media (max-width: 576px) {
            body {
                padding: 10px;
            }
            
            .login-right {
                padding: 30px 20px;
            }
            
            .login-header h2 {
                font-size: 26px;
            }
            
            .role-tabs {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Floating Particles -->
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    
    <div class="login-container">
        <!-- Left Side - Branding -->
        <div class="login-left">
            <div class="brand-content">
                <div class="brand-logo">
                    <i class="fas fa-mobile-alt"></i>
                    <span>PhoneStock Pro</span>
                </div>
                
                <h1 class="brand-title">Welcome Back!</h1>
                
                <p class="brand-subtitle">
                    Manage your phone inventory with ease. Track sales, monitor stock, and grow your business efficiently.
                </p>
                
                <ul class="features">
                    <li>
                        <i class="fas fa-bolt"></i>
                        <span>Lightning-fast inventory management</span>
                    </li>
                    <li>
                        <i class="fas fa-chart-line"></i>
                        <span>Real-time sales analytics & reports</span>
                    </li>
                    <li>
                        <i class="fas fa-shield-alt"></i>
                        <span>Secure role-based access control</span>
                    </li>
                    <li>
                        <i class="fas fa-sync-alt"></i>
                        <span>Automatic stock synchronization</span>
                    </li>
                </ul>
            </div>
        </div>
        
        <!-- Right Side - Login Form -->
        <div class="login-right">
            <div class="login-header">
                <h2>Sign In</h2>
                <p>Select your role and enter your credentials</p>
            </div>
            
            <!-- Alert Messages -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Role Selection Tabs -->
            <div class="role-tabs">
                <div class="role-tab employee-tab active" onclick="selectRole('employee')">
                    <div class="role-icon">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <h4>Employee</h4>
                    <p>Quick access</p>
                </div>
                
                <div class="role-tab admin-tab" onclick="selectRole('admin')">
                    <div class="role-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h4>Admin</h4>
                    <p>Full control</p>
                </div>
            </div>
            
            <!-- Employee Login Form -->
            <form id="employeeForm" class="login-form employee-form active" method="POST">
                <input type="hidden" name="role" value="employee">
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-signature"></i>
                        Your Full Name
                    </label>
                    <input 
                        type="text" 
                        class="form-control" 
                        name="employee_name" 
                        placeholder="Enter your full name"
                        value="<?php echo isset($_POST['employee_name']) ? htmlspecialchars($_POST['employee_name']) : ''; ?>"
                        required
                    >
                    <small class="form-text">This will be recorded for activity tracking</small>
                </div>
                
                <button type="submit" class="btn-login btn-employee">
                    <span class="spinner"></span>
                    <span>Continue as Employee</span>
                </button>
                
                <div class="info-box">
                    <h6>
                        <i class="fas fa-info-circle"></i>
                        Employee Access
                    </h6>
                    <p>• No password required for employees</p>
                    <p>• Your name will be recorded for tracking</p>
                    <p>• Limited to data entry and viewing</p>
                </div>
            </form>
            
            <!-- Admin Login Form -->
            <form id="adminForm" class="login-form admin-form" method="POST">
                <input type="hidden" name="role" value="admin">
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-user"></i>
                        Username
                    </label>
                    <input 
                        type="text" 
                        class="form-control" 
                        name="username" 
                        placeholder="Enter admin username"
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                        required
                    >
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-lock"></i>
                        Password
                    </label>
                    <input 
                        type="password" 
                        class="form-control" 
                        name="password" 
                        id="password"
                        placeholder="Enter admin password"
                        required
                    >
                    <label class="show-password">
                        <input type="checkbox" onclick="togglePassword()">
                        <span>Show password</span>
                    </label>
                </div>
                
                <button type="submit" class="btn-login btn-admin">
                    <span class="spinner"></span>
                    <span>Login as Admin</span>
                </button>
                
                <div class="info-box">
                    <h6>
                        <i class="fas fa-key"></i>
                        Default Credentials
                    </h6>
                    <p><strong>Username:</strong> admin</p>
                    <p><strong>Password:</strong> admin123</p>
                    <p class="text-danger">⚠️ Change these after first login!</p>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Role selection
        function selectRole(role) {
            // Update tabs
            document.querySelectorAll('.role-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            if (role === 'employee') {
                document.querySelector('.employee-tab').classList.add('active');
                document.getElementById('employeeForm').classList.add('active');
                document.getElementById('adminForm').classList.remove('active');
            } else {
                document.querySelector('.admin-tab').classList.add('active');
                document.getElementById('adminForm').classList.add('active');
                document.getElementById('employeeForm').classList.remove('active');
            }
        }
        
        // Toggle password visibility
        function togglePassword() {
            const passwordField = document.getElementById('password');
            passwordField.type = passwordField.type === 'password' ? 'text' : 'password';
        }
        
        // Form submission with loading state
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const btn = this.querySelector('.btn-login');
                const spinner = this.querySelector('.spinner');
                btn.disabled = true;
                spinner.style.display = 'block';
            });
        });
    </script>
</body>
</html>