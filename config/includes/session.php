<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session timeout configuration (15 minutes)
$session_timeout = 900; // 15 minutes in seconds

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// Check if user is admin
function isAdmin() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Check if user is employee
function isEmployee() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'employee';
}

// Check session timeout
function checkSessionTimeout() {
    global $session_timeout;
    
    if (isset($_SESSION['last_activity'])) {
        $seconds_inactive = time() - $_SESSION['last_activity'];
        
        if ($seconds_inactive > $session_timeout) {
            // Session expired
            session_unset();
            session_destroy();
            return false;
        }
    }
    
    $_SESSION['last_activity'] = time(); // Update last activity time
    return true;
}

// Require login for protected pages
function requireLogin() {
    if (!isLoggedIn() || !checkSessionTimeout()) {
        header('Location: login.php');
        exit();
    }
}

// Require admin access
function requireAdmin() {
    requireLogin();
    
    if (!isAdmin()) {
        header('Location: employee.php');
        exit();
    }
}

// Get current user info
function getCurrentUser() {
    if (isLoggedIn()) {
        return [
            'user_id' => $_SESSION['user_id'] ?? 0,
            'username' => $_SESSION['username'] ?? '',
            'full_name' => $_SESSION['full_name'] ?? '',
            'role' => $_SESSION['role'] ?? 'guest'
        ];
    }
    return null;
}

// Logout function
function logout() {
    // Log activity before destroying session
    if (isLoggedIn()) {
        require_once 'config/database.php';
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $logQuery = "INSERT INTO activity_log (user_id, activity_type, description, ip_address) 
                         VALUES (:user_id, 'logout', 'User logged out', :ip_address)";
            $logStmt = $db->prepare($logQuery);
            $logStmt->bindParam(':user_id', $_SESSION['user_id']);
            $logStmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR']);
            $logStmt->execute();
        } catch(Exception $e) {
            // Silently fail if logging fails
        }
    }
    
    // Destroy session
    $_SESSION = array();
    
    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}
?>