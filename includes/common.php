<?php
/**
 * Common Utility Functions
 * Shared functions to reduce code duplication across the application
 */

/**
 * Format currency amount
 * @param float $amount The amount to format
 * @param string $currency Currency symbol (default: FCFA)
 * @return string Formatted currency string
 */
function formatCurrency($amount, $currency = 'FCFA') {
    return number_format($amount, 0, '.', ',') . ' ' . $currency;
}

/**
 * Format date for display
 * @param string $date Date string
 * @param string $format Format string (default: 'M d, Y H:i')
 * @return string Formatted date
 */
function formatDate($date, $format = 'M d, Y H:i') {
    return date($format, strtotime($date));
}

/**
 * Get status badge HTML
 * @param string $status Status value
 * @return string HTML for status badge
 */
function getStatusBadge($status) {
    $badges = [
        'in_stock' => '<span class="badge bg-success">In Stock</span>',
        'sold' => '<span class="badge bg-danger">Sold</span>',
        'transferred' => '<span class="badge bg-warning">Transferred</span>',
        'active' => '<span class="badge bg-success">Active</span>',
        'inactive' => '<span class="badge bg-secondary">Inactive</span>',
    ];
    
    return $badges[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
}

/**
 * Render a dashboard stat card
 * @param string $title Card title
 * @param mixed $value Card value
 * @param string $icon FontAwesome icon class
 * @param string $color Card color (primary, success, danger, warning, info)
 * @return string HTML for stat card
 */
function renderStatsCard($title, $value, $icon, $color = 'primary') {
    $colorClasses = [
        'primary' => 'bg-primary text-white',
        'success' => 'bg-success text-white',
        'danger' => 'bg-danger text-white',
        'warning' => 'bg-warning text-dark',
        'info' => 'bg-info text-white',
    ];
    
    $cardClass = $colorClasses[$color] ?? $colorClasses['primary'];
    
    return <<<HTML
    <div class="card {$cardClass} shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-1 opacity-75">{$title}</h6>
                    <h3 class="mb-0 fw-bold">{$value}</h3>
                </div>
                <div>
                    <i class="{$icon}" style="font-size: 2.5rem; opacity: 0.5;"></i>
                </div>
            </div>
        </div>
    </div>
    HTML;
}

/**
 * Log error to file
 * @param string $message Error message
 * @param string $file File where error occurred
 * @param int $line Line number
 */
function logError($message, $file = '', $line = 0) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}";
    
    if ($file) {
        $logMessage .= " in {$file}";
    }
    
    if ($line) {
        $logMessage .= " on line {$line}";
    }
    
    // If on Vercel (read-only filesystem), write to error log which goes to Runtime Logs
    if (getenv('VERCEL')) {
        error_log($logMessage);
        return;
    }

    $logFile = __DIR__ . '/../logs/error.log';
    $logDir = dirname($logFile);
    
    if (!file_exists($logDir)) {
        // Suppress errors if we can't create directory
        @mkdir($logDir, 0755, true);
    }
    
    $logMessage .= PHP_EOL;
    
    // Try to write to file, fallback to system log if fails
    if (!@error_log($logMessage, 3, $logFile)) {
        error_log($logMessage);
    }
}

/**
 * Sanitize output for HTML display
 * @param string $text Text to sanitize
 * @return string Sanitized text
 */
function sanitizeOutput($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate random string
 * @param int $length Length of string
 * @return string Random string
 */
function generateRandomString($length = 10) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Check if user has permission
 * @param string $required_role Required role (admin/employee)
 * @return bool True if user has permission
 */
function hasPermission($required_role = 'employee') {
    if (!isset($_SESSION['role'])) {
        return false;
    }
    
    if ($required_role === 'admin') {
        return $_SESSION['role'] === 'admin';
    }
    
    return in_array($_SESSION['role'], ['admin', 'employee']);
}

/**
 * Redirect to login if not authenticated
 */
function requireAuth() {
    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        header('Location: login.php');
        exit();
    }
}

/**
 * Redirect to login if not admin
 */
function requireAdmin() {
    requireAuth();
    
    if ($_SESSION['role'] !== 'admin') {
        header('Location: employee.php');
        exit();
    }
}

/**
 * Get user's IP address
 * @return string IP address
 */
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

/**
 * Calculate time ago from timestamp
 * @param string $datetime Datetime string
 * @return string Time ago string
 */
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return $diff . ' second' . ($diff != 1 ? 's' : '') . ' ago';
    }
    
    $diff = round($diff / 60);
    if ($diff < 60) {
        return $diff . ' minute' . ($diff != 1 ? 's' : '') . ' ago';
    }
    
    $diff = round($diff / 60);
    if ($diff < 24) {
        return $diff . ' hour' . ($diff != 1 ? 's' : '') . ' ago';
    }
    
    $diff = round($diff / 24);
    if ($diff < 7) {
        return $diff . ' day' . ($diff != 1 ? 's' : '') . ' ago';
    }
    
    $diff = round($diff / 7);
    if ($diff < 4) {
        return $diff . ' week' . ($diff != 1 ? 's' : '') . ' ago';
    }
    
    return date('M d, Y', $timestamp);
}
