<?php
/**
 * CSRF Token Protection
 * Generates and validates CSRF tokens for form submissions
 */

/**
 * Generate a CSRF token and store it in session
 * @return string The generated token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token from form submission
 * @param string $token The token to validate
 * @return bool True if valid, false otherwise
 */
function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate HTML input field for CSRF token
 * @return string HTML input field
 */
function csrfField() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Regenerate CSRF token (call after successful form submission)
 */
function regenerateCSRFToken() {
    unset($_SESSION['csrf_token']);
    return generateCSRFToken();
}
