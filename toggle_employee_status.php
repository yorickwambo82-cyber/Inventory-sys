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

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);
$user_id = $data['user_id'] ?? null;
$status = $data['status'] ?? '';

if (!$user_id || !in_array($status, ['active', 'inactive'])) {
    $response['message'] = 'Invalid request data';
    echo json_encode($response);
    exit();
}

$admin_id = $_SESSION['user_id'];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get user info before updating
    $query = "SELECT * FROM users WHERE user_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) {
        $updateQuery = "UPDATE users SET status = :status WHERE user_id = :user_id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':status', $status);
        $updateStmt->bindParam(':user_id', $user_id);
        
        if ($updateStmt->execute()) {
            $response['success'] = true;
            $response['message'] = "Employee status updated to $status";
            
            // Log activity
            $action = $status === 'active' ? 'activate_employee' : 'deactivate_employee';
            logActivity($admin_id, $action, "{$action}: {$user['full_name']} ({$user['username']})");
        } else {
            $response['message'] = 'Failed to update employee status';
        }
    } else {
        $response['message'] = 'Employee not found';
    }
    
} catch(Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
?>