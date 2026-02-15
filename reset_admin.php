<?php
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // New password
    $new_password = 'admin123';
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Update admin user
    $query = "UPDATE users SET password_hash = :hash WHERE role = 'admin'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':hash', $new_hash);
    
    if($stmt->execute()) {
        echo "<h1>Success!</h1>";
        echo "<p>Admin password has been reset to: <strong>admin123</strong></p>";
        echo "<p><a href='login.php'>Go to Login</a></p>";
        echo "<p style='color:red'>Please delete this file (reset_admin.php) after use!</p>";
    } else {
        echo "<h1>Error</h1>";
        echo "<p>Could not update password.</p>";
    }
    
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
