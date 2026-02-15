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

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $itemType = $_POST['itemType'] ?? '';
    $status = $_POST['itemStatus'] ?? 'in_stock';
    $buyingPrice = $_POST['buyingPrice'] ?? 0;
    $sellingPrice = $_POST['sellingPrice'] ?? 0;
    $notes = $_POST['notes'] ?? '';
    $registered_by = $_SESSION['user_id'];
    
    if ($itemType === 'phone') {
        // Add phone
        $brand = $_POST['brand'] ?? '';
        $model = $_POST['model'] ?? '';
        $imei = $_POST['imei'] ?? '';
        $color = $_POST['color'] ?? '';
        $memory = $_POST['memory'] ?? '';
        
        if (empty($brand) || empty($model) || empty($imei)) {
            $response['message'] = 'Please fill all required fields for phone';
            echo json_encode($response);
            exit();
        }
        
        // Check if IMEI already exists
        $checkQuery = "SELECT COUNT(*) as count FROM phones WHERE iemi_number = :imei";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':imei', $imei);
        $checkStmt->execute();
        $result = $checkStmt->fetch();
        
        if ($result['count'] > 0) {
            $response['message'] = 'IMEI number already exists in the system';
            echo json_encode($response);
            exit();
        }
        
        $query = "INSERT INTO phones (iemi_number, brand, model, color, memory, buying_price, selling_price, notes, status, registered_by) 
                  VALUES (:imei, :brand, :model, :color, :memory, :buying_price, :selling_price, :notes, :status, :registered_by)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':imei', $imei);
        $stmt->bindParam(':brand', $brand);
        $stmt->bindParam(':model', $model);
        $stmt->bindParam(':color', $color);
        $stmt->bindParam(':memory', $memory);
        $stmt->bindParam(':buying_price', $buyingPrice);
        $stmt->bindParam(':selling_price', $sellingPrice);
        $stmt->bindParam(':notes', $notes);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':registered_by', $registered_by);
        
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Phone added successfully';
            
            // Log activity
            logActivity($registered_by, 'add_phone', "Added phone: $brand $model ($imei)");
        } else {
            $response['message'] = 'Failed to add phone';
        }
        
    } elseif ($itemType === 'accessory') {
        // Add accessory
        $accessoryName = $_POST['accessoryName'] ?? '';
        $category = $_POST['category'] ?? '';
        $quantity = $_POST['quantity'] ?? 1;
        $accessoryBrand = $_POST['accessoryBrand'] ?? '';
        
        if (empty($accessoryName) || empty($category)) {
            $response['message'] = 'Please fill all required fields for accessory';
            echo json_encode($response);
            exit();
        }
        
        $query = "INSERT INTO accessories (accessory_name, category, brand, buying_price, selling_price, quantity, notes, status, registered_by) 
                  VALUES (:name, :category, :brand, :buying_price, :selling_price, :quantity, :notes, :status, :registered_by)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $accessoryName);
        $stmt->bindParam(':category', $category);
        $stmt->bindParam(':brand', $accessoryBrand);
        $stmt->bindParam(':buying_price', $buyingPrice);
        $stmt->bindParam(':selling_price', $sellingPrice);
        $stmt->bindParam(':quantity', $quantity);
        $stmt->bindParam(':notes', $notes);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':registered_by', $registered_by);
        
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Accessory added successfully';
            
            // Log activity
            logActivity($registered_by, 'add_accessory', "Added accessory: $accessoryName ($category)");
        } else {
            $response['message'] = 'Failed to add accessory';
        }
    } else {
        $response['message'] = 'Invalid item type';
    }
    
} catch(Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
?>