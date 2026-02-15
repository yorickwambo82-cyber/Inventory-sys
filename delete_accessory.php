<?php
header('Content-Type: application/json');

require_once 'includes/session.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once 'config/database.php';
require_once 'includes/logActivity.php';

$response = ['success' => false, 'message' => ''];

if (!isset($_GET['id'])) {
    $response['message'] = 'No accessory ID provided';
    echo json_encode($response);
    exit();
}

$accessory_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get accessory info before updating for logging
    $query = "SELECT * FROM accessories WHERE accessory_id = :accessory_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':accessory_id', $accessory_id);
    $stmt->execute();
    $accessory = $stmt->fetch();
    
    if ($accessory) {
        // Mark as unavailable instead of deleting
        $updateQuery = "UPDATE accessories SET status = 'unavailable' WHERE accessory_id = :accessory_id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':accessory_id', $accessory_id);
        
        if ($updateStmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Accessory marked as unavailable';
            
            // Log activity
            logActivity($user_id, 'mark_unavailable_accessory', "Marked accessory as unavailable: {$accessory['accessory_name']}");
        } else {
            $response['message'] = 'Failed to update accessory status';
        }
    } else {
        $response['message'] = 'Accessory not found';
    }
    
} catch(Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
?>