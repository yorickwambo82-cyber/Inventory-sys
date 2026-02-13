<?php
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once 'config/database.php';
require_once 'includes/logActivity.php';

$response = ['success' => false, 'message' => ''];

if (!isset($_GET['id'])) {
    $response['message'] = 'No phone ID provided';
    echo json_encode($response);
    exit();
}

$phone_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get phone info before deleting for logging
    $query = "SELECT * FROM phones WHERE phone_id = :phone_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':phone_id', $phone_id);
    $stmt->execute();
    $phone = $stmt->fetch();
    
    if ($phone) {
        // Mark as unavailable instead of deleting (to keep history)
        $updateQuery = "UPDATE phones SET status = 'unavailable' WHERE phone_id = :phone_id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':phone_id', $phone_id);
        
        if ($updateStmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Phone marked as unavailable';
            
            // Log activity
            logActivity($user_id, 'mark_unavailable_phone', "Marked phone as unavailable: {$phone['brand']} {$phone['model']} ({$phone['iemi_number']})");
        } else {
            $response['message'] = 'Failed to update phone status';
        }
    } else {
        $response['message'] = 'Phone not found';
    }
    
} catch(Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
?>