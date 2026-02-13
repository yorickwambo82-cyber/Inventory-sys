<?php
/**
 * Maintenance Mode Configuration
 * 
 * To enable maintenance mode:
 * 1. Change $maintenance_mode to true
 * 2. Optionally add your IP to $allowed_ips to bypass maintenance
 */

// Enable/Disable maintenance mode
$maintenance_mode = false; // Change to true to enable

// IP addresses that can bypass maintenance mode (e.g., your IP)
$allowed_ips = [
    '127.0.0.1',           // Localhost
    '::1',                 // Localhost IPv6
    // Add your IP here, e.g., '123.456.789.012'
];

// Check if maintenance mode is enabled
if ($maintenance_mode) {
    $user_ip = $_SERVER['REMOTE_ADDR'];
    
    // Check if user's IP is in allowed list
    if (!in_array($user_ip, $allowed_ips)) {
        // Show maintenance page
        http_response_code(503);
        header('Retry-After: 3600'); // Retry after 1 hour
        include('maintenance.html');
        exit();
    }
}
