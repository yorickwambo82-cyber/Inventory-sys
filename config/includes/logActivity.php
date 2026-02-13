<?php
// Activity Logging Function
// Logs user activities to activity_log table

function logActivity($user_id, $action_type, $description) {
    try {
        require_once __DIR__ . '/../config/database.php';

        $database = new Database();
        $db = $database->getConnection();

        // Create activity_log table if it doesn't exist
        $create_table = "CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action_type VARCHAR(100),
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        
        $db->exec($create_table);

        $query = "INSERT INTO activity_log (user_id, action_type, description, created_at)
                  VALUES (:user_id, :action_type, :description, NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':action_type', $action_type);
        $stmt->bindParam(':description', $description);
        $stmt->execute();

        return true;
    } catch (Exception $e) {
        error_log('Activity logging error: ' . $e->getMessage());
        return false;
    }
}