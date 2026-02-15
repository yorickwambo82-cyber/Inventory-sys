<?php
// Database configuration
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'phonestore_db';

$db_port = getenv('DB_PORT') ?: 3306;

// Create database connection
try {
    $conn = mysqli_init();
    
    // SSL Configuration for Remote DBs (Aiven/Azure)
    if (getenv('DB_HOST') !== 'localhost') {
        $conn->ssl_set(NULL, NULL, "/etc/ssl/certs/ca-certificates.crt", NULL, NULL);
        if (!$conn->real_connect($db_host, $db_user, $db_pass, $db_name, $db_port, null, MYSQLI_CLIENT_SSL)) {
            die("Connect Error: " . mysqli_connect_error());
        }
    } else {
        if (!$conn->real_connect($db_host, $db_user, $db_pass, $db_name, $db_port)) {
            die("Connect Error: " . mysqli_connect_error());
        }
    }
    
    // Set charset to utf8mb4
    $conn->set_charset("utf8mb4");
    
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Function to format currency to XAF
function formatXAF($amount) {
    return number_format($amount, 0, ',', ' ') . ' XAF';
}

// Function to prevent SQL injection
function clean_input($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $conn->real_escape_string($data);
}
?>