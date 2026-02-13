<?php
/**
 * Input Validation Functions
 * Centralized validation and sanitization
 */

/**
 * Validate IMEI using Luhn algorithm
 * @param string $imei The IMEI to validate
 * @return bool True if valid, false otherwise
 */
function validateIMEI($imei) {
    // Remove any non-digit characters
    $imei = preg_replace('/\D/', '', $imei);
    
    // IMEI should be 15 digits
    if (strlen($imei) !== 15) {
        return false;
    }
    
    // Luhn algorithm check
    $sum = 0;
    $alt = false;
    
    for ($i = strlen($imei) - 1; $i >= 0; $i--) {
        $n = intval($imei[$i]);
        
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n = ($n % 10) + 1;
            }
        }
        
        $sum += $n;
        $alt = !$alt;
    }
    
    return ($sum % 10 === 0);
}

/**
 * Enhanced input sanitization
 * @param mixed $data The data to sanitize
 * @return mixed Sanitized data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    
    return $data;
}

/**
 * Validate phone number format
 * @param string $phone The phone number to validate
 * @return bool True if valid, false otherwise
 */
function validatePhone($phone) {
    // Remove any non-digit characters
    $phone = preg_replace('/\D/', '', $phone);
    
    // Phone should be 9-15 digits
    return strlen($phone) >= 9 && strlen($phone) <= 15;
}

/**
 * Validate email format
 * @param string $email The email to validate
 * @return bool True if valid, false otherwise
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate password strength
 * @param string $password The password to validate
 * @return array ['valid' => bool, 'message' => string]
 */
function validatePassword($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    return [
        'valid' => empty($errors),
        'message' => implode(', ', $errors)
    ];
}

/**
 * Sanitize filename for safe file operations
 * @param string $filename The filename to sanitize
 * @return string Sanitized filename
 */
function sanitizeFilename($filename) {
    // Remove any path components
    $filename = basename($filename);
    
    // Remove special characters
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    
    return $filename;
}
