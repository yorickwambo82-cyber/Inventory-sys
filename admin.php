<?php
// Strong cache control
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// Check maintenance mode
require_once 'includes/maintenance.php';

session_start();

// Force session regeneration
session_regenerate_id(true);

// Include database connection
require_once 'config/database.php';
require_once 'includes/logActivity.php';

// Check if user is logged in as admin
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    // Log logout activity if user_id exists
    if (isset($_SESSION['user_id'])) {
        try {
            logActivity($_SESSION['user_id'], 'logout', 'Session timeout or invalid access');
        } catch (Exception $e) {
            error_log('Logout logging failed: ' . $e->getMessage());
        }
    }
    session_destroy();
    header("Location: login.php?logout_success=1&nocache=" . time());
    exit();
}

// Handle logout - REDIRECTS TO login.php
if (isset($_GET['logout'])) {
    $user_id_for_logout = $_SESSION['user_id'] ?? null;
    
    if ($user_id_for_logout) {
        try {
            logActivity($user_id_for_logout, 'logout', 'Admin logged out manually');
        } catch (Exception $e) {
            error_log('Logout logging failed: ' . $e->getMessage());
        }
    }
    
    session_destroy();
    header("Location: login.php?logout_success=1&nocache=" . time());
    exit();
}

// Get user info
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];

// Initialize variables
$error = '';
$success = '';
$search_term = '';
$search_type = 'both';

// Get all shops for management
$all_shops = [];
try {
    $database = new Database();
    $db = $database->getConnection();
    $query = "SELECT shop_id, shop_name, status FROM shops ORDER BY shop_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $all_shops = $stmt->fetchAll();
} catch(Exception $e) {
    $all_shops = [];
}

// Get all users for management
$all_users = [];
try {
    $database = new Database();
    $db = $database->getConnection();
    $query = "SELECT user_id, username, full_name, role, is_active, last_login FROM users ORDER BY role, full_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $all_users = $stmt->fetchAll();
} catch(Exception $e) {
    $all_users = [];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new phone
    if (isset($_POST['add_phone'])) {
        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $imei = trim($_POST['imei'] ?? '');
        $color = trim($_POST['color'] ?? '');
        $memory = trim($_POST['memory'] ?? '');
        $buying_price = floatval($_POST['buying_price'] ?? 0);
        $selling_price = floatval($_POST['selling_price'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if (!empty($brand) && !empty($model) && !empty($imei)) {
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                $checkQuery = "SELECT phone_id FROM phones WHERE iemi_number = :imei AND status = 'in_stock'";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->bindParam(':imei', $imei);
                $checkStmt->execute();
                
                if ($checkStmt->fetch()) {
                    $error = "IMEI already exists in stock!";
                } else {
                    $query = "INSERT INTO phones (iemi_number, brand, model, color, memory, buying_price, selling_price, notes, registered_by, status) 
                              VALUES (:imei, :brand, :model, :color, :memory, :buying_price, :selling_price, :notes, :registered_by, 'in_stock')";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':imei', $imei);
                    $stmt->bindParam(':brand', $brand);
                    $stmt->bindParam(':model', $model);
                    $stmt->bindParam(':color', $color);
                    $stmt->bindParam(':memory', $memory);
                    $stmt->bindParam(':buying_price', $buying_price);
                    $stmt->bindParam(':selling_price', $selling_price);
                    $stmt->bindParam(':notes', $notes);
                    $stmt->bindParam(':registered_by', $user_id);
                    $stmt->execute();
                    
                    logActivity($user_id, 'add_phone', "Added phone: $brand $model ($imei)");
                    
                    $success = "Phone added successfully!";
                    header("Location: admin.php?success=phone_added&nocache=" . time());
                    exit();
                }
            } catch(Exception $e) {
                $error = "Error adding phone: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields (Brand, Model, IMEI)";
        }
    }
    
    // Add new accessory
    if (isset($_POST['add_accessory'])) {
        $accessory_name = trim($_POST['accessory_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $brand = trim($_POST['accessory_brand'] ?? '');
        $buying_price = floatval($_POST['buying_price'] ?? 0);
        $selling_price = floatval($_POST['accessory_selling_price'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 1);
        
        if (!empty($accessory_name) && !empty($category)) {
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                $checkQuery = "SELECT accessory_id FROM accessories WHERE accessory_name = :name AND category = :category AND status = 'in_stock'";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->bindParam(':name', $accessory_name);
                $checkStmt->bindParam(':category', $category);
                $checkStmt->execute();
                
                $existing = $checkStmt->fetch();
                if ($existing) {
                    $updateQuery = "UPDATE accessories SET quantity = quantity + :quantity WHERE accessory_id = :id";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':quantity', $quantity);
                    $updateStmt->bindParam(':id', $existing['accessory_id']);
                    $updateStmt->execute();
                    
                    logActivity($user_id, 'update_accessory', "Updated quantity for accessory: $accessory_name (+$quantity)");
                } else {
                    $query = "INSERT INTO accessories (accessory_name, category, brand, buying_price, selling_price, quantity, registered_by, status) 
                              VALUES (:name, :category, :brand, :buying_price, :selling_price, :quantity, :registered_by, 'in_stock')";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':name', $accessory_name);
                    $stmt->bindParam(':category', $category);
                    $stmt->bindParam(':brand', $brand);
                    $stmt->bindParam(':buying_price', $buying_price);
                    $stmt->bindParam(':selling_price', $selling_price);
                    $stmt->bindParam(':quantity', $quantity);
                    $stmt->bindParam(':registered_by', $user_id);
                    $stmt->execute();
                    
                    logActivity($user_id, 'add_accessory', "Added accessory: $accessory_name ($category)");
                }
                
                $success = "Accessory " . ($existing ? "quantity updated" : "added") . " successfully!";
                header("Location: admin.php?success=accessory_added&nocache=" . time());
                exit();
            } catch(Exception $e) {
                $error = "Error adding accessory: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields";
        }
    }
    
    // Sell phone
    if (isset($_POST['sell_phone'])) {
        $phone_id = intval($_POST['phone_id'] ?? 0);
        $sale_price = floatval($_POST['sale_price'] ?? 0);
        $customer_name = trim($_POST['customer_name'] ?? '');
        $customer_phone = trim($_POST['customer_phone'] ?? '');
        $payment_method = trim($_POST['payment_method'] ?? 'cash');
        $notes = trim($_POST['sale_notes'] ?? '');
        
        if (!empty($phone_id) && $sale_price > 0) {
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                $query = "SELECT * FROM phones WHERE phone_id = :phone_id AND status = 'in_stock'";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':phone_id', $phone_id);
                $stmt->execute();
                $phone = $stmt->fetch();
                
                if ($phone) {
                    $db->beginTransaction();
                    
                    $query = "INSERT INTO sales (item_id, item_type, sale_price, customer_name, customer_phone, payment_method, notes, sold_by) 
                              VALUES (:item_id, 'phone', :sale_price, :customer_name, :customer_phone, :payment_method, :notes, :sold_by)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':item_id', $phone_id);
                    $stmt->bindParam(':sale_price', $sale_price);
                    $stmt->bindParam(':customer_name', $customer_name);
                    $stmt->bindParam(':customer_phone', $customer_phone);
                    $stmt->bindParam(':payment_method', $payment_method);
                    $stmt->bindParam(':notes', $notes);
                    $stmt->bindParam(':sold_by', $user_id);
                    $stmt->execute();
                    
                    $query = "UPDATE phones SET status = 'sold', sold_date = CURDATE() WHERE phone_id = :phone_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':phone_id', $phone_id);
                    $stmt->execute();
                    
                    $db->commit();
                    
                    logActivity($user_id, 'sell_phone', "Sold phone: {$phone['brand']} {$phone['model']} for XAF " . number_format($sale_price, 2));
                    
                    $success = "Phone sold successfully!";
                    header("Location: admin.php?success=phone_sold&nocache=" . time());
                    exit();
                } else {
                    $error = "Phone not found or already sold!";
                }
            } catch(Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                $error = "Error selling phone: " . $e->getMessage();
            }
        } else {
            $error = "Please select a phone and enter sale price";
        }
    }
    
    // Sell accessory
    if (isset($_POST['sell_accessory'])) {
        $accessory_id = intval($_POST['accessory_id'] ?? 0);
        $sale_quantity = intval($_POST['sale_quantity'] ?? 1);
        $sale_price_per_unit = floatval($_POST['sale_price'] ?? 0);
        $customer_name = trim($_POST['customer_name'] ?? '');
        $customer_phone = trim($_POST['customer_phone'] ?? '');
        $payment_method = trim($_POST['payment_method'] ?? 'cash');
        $notes = trim($_POST['sale_notes'] ?? '');
        
        if (!empty($accessory_id) && $sale_quantity > 0 && $sale_price_per_unit > 0) {
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                $query = "SELECT * FROM accessories WHERE accessory_id = :accessory_id AND status = 'in_stock'";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':accessory_id', $accessory_id);
                $stmt->execute();
                $accessory = $stmt->fetch();
                
                if ($accessory && $accessory['quantity'] >= $sale_quantity) {
                    $total_sale_price = $sale_price_per_unit * $sale_quantity;
                    
                    $db->beginTransaction();
                    
                    $query = "INSERT INTO sales (item_id, item_type, quantity, sale_price, customer_name, customer_phone, payment_method, notes, sold_by) 
                              VALUES (:item_id, 'accessory', :quantity, :sale_price, :customer_name, :customer_phone, :payment_method, :notes, :sold_by)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':item_id', $accessory_id);
                    $stmt->bindParam(':quantity', $sale_quantity);
                    $stmt->bindParam(':sale_price', $total_sale_price);
                    $stmt->bindParam(':customer_name', $customer_name);
                    $stmt->bindParam(':customer_phone', $customer_phone);
                    $stmt->bindParam(':payment_method', $payment_method);
                    $stmt->bindParam(':notes', $notes);
                    $stmt->bindParam(':sold_by', $user_id);
                    $stmt->execute();
                    
                    $new_quantity = $accessory['quantity'] - $sale_quantity;
                    $new_status = ($new_quantity <= 0) ? 'out_of_stock' : 'in_stock';
                    
                    $query = "UPDATE accessories SET quantity = :quantity, status = :status WHERE accessory_id = :accessory_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':quantity', $new_quantity);
                    $stmt->bindParam(':status', $new_status);
                    $stmt->bindParam(':accessory_id', $accessory_id);
                    $stmt->execute();
                    
                    $db->commit();
                    
                    logActivity($user_id, 'sell_accessory', "Sold accessory: {$accessory['accessory_name']} x$sale_quantity for XAF " . number_format($total_sale_price, 2));
                    
                    $success = "Accessory sold successfully! Total: XAF " . number_format($total_sale_price, 2);
                    header("Location: admin.php?success=accessory_sold&nocache=" . time());
                    exit();
                } else {
                    $error = "Not enough stock available!";
                }
            } catch(Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                $error = "Error selling accessory: " . $e->getMessage();
            }
        } else {
            $error = "Please select an accessory and enter quantity and price";
        }
    }
    
    // Transfer item
    if (isset($_POST['transfer_item'])) {
        $item_type = trim($_POST['item_type'] ?? '');
        
        if ($item_type === 'phone') {
            $item_id = intval($_POST['phone_transfer_id'] ?? 0);
        } else if ($item_type === 'accessory') {
            $item_id = intval($_POST['accessory_transfer_id'] ?? 0);
        } else {
            $item_id = 0;
        }
        
        $quantity = intval($_POST['transfer_quantity'] ?? 1);
        $destination_shop = intval($_POST['destination_shop'] ?? 0);
        $notes = trim($_POST['transfer_notes'] ?? '');
        
        if (!empty($item_type) && !empty($item_id) && !empty($destination_shop)) {
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                if ($item_type === 'phone') {
                    $query = "SELECT * FROM phones WHERE phone_id = :item_id AND status = 'in_stock'";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->execute();
                    $item = $stmt->fetch();
                    
                    if ($item) {
                        $db->beginTransaction();
                        
                        $query = "INSERT INTO transfers (item_id, item_type, quantity, source_shop_id, destination_shop_id, transferred_by, notes, transfer_date) 
                                  VALUES (:item_id, :item_type, 1, 1, :destination_shop, :transferred_by, :notes, NOW())";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->bindParam(':item_type', $item_type);
                        $stmt->bindParam(':destination_shop', $destination_shop);
                        $stmt->bindParam(':transferred_by', $user_id);
                        $stmt->bindParam(':notes', $notes);
                        $stmt->execute();
                        
                        $query = "UPDATE phones SET status = 'sold' WHERE phone_id = :item_id";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->execute();
                        
                        $db->commit();
                        
                        logActivity($user_id, 'transfer_phone', "Transferred phone: {$item['brand']} {$item['model']} to Shop $destination_shop");
                        
                        $success = "Phone transferred successfully!";
                        header("Location: admin.php?success=phone_transferred&nocache=" . time());
                        exit();
                    }
                } else if ($item_type === 'accessory') {
                    $query = "SELECT * FROM accessories WHERE accessory_id = :item_id AND status = 'in_stock'";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->execute();
                    $item = $stmt->fetch();
                    
                    if ($item && $item['quantity'] >= $quantity) {
                        $db->beginTransaction();
                        
                        $query = "INSERT INTO transfers (item_id, item_type, quantity, source_shop_id, destination_shop_id, transferred_by, notes, transfer_date) 
                                  VALUES (:item_id, :item_type, :quantity, 1, :destination_shop, :transferred_by, :notes, NOW())";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->bindParam(':item_type', $item_type);
                        $stmt->bindParam(':quantity', $quantity);
                        $stmt->bindParam(':destination_shop', $destination_shop);
                        $stmt->bindParam(':transferred_by', $user_id);
                        $stmt->bindParam(':notes', $notes);
                        $stmt->execute();
                        
                        $new_quantity = $item['quantity'] - $quantity;
                        $new_status = ($new_quantity <= 0) ? 'out_of_stock' : 'in_stock';
                        
                        $query = "UPDATE accessories SET quantity = :quantity, status = :status WHERE accessory_id = :item_id";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':quantity', $new_quantity);
                        $stmt->bindParam(':status', $new_status);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->execute();
                        
                        $db->commit();
                        
                        logActivity($user_id, 'transfer_accessory', "Transferred accessory: {$item['accessory_name']} x$quantity to Shop $destination_shop");
                        
                        $success = "Accessory transferred successfully!";
                        header("Location: admin.php?success=accessory_transferred&nocache=" . time());
                        exit();
                    }
                }
            } catch(Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                $error = "Error transferring item: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields for transfer";
        }
    }

    
    // Add new user
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $role = trim($_POST['role'] ?? 'employee');
        $password = trim($_POST['password'] ?? '');
        
        if (!empty($username) && !empty($full_name)) {
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                // Check if username exists
                $checkQuery = "SELECT user_id FROM users WHERE username = :username";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->bindParam(':username', $username);
                $checkStmt->execute();
                
                if ($checkStmt->fetch()) {
                    $error = "Username already exists!";
                } else {
                    // Start transaction
                    $db->beginTransaction();
                    
                    // For admin, we might want to set a password (hashed)
                    // For employee, we are currently just using name matching in login, 
                    // but we can store a dummy password or leave it blank if the schema allows.
                    // based on login check, admin needs hash.
                    
                    $password_hash = '';
                    if (!empty($password)) {
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    } else {
                         // Default password for employees if not provided, for security
                        $password_hash = password_hash('123456', PASSWORD_DEFAULT);
                    }

                    $query = "INSERT INTO users (username, full_name, role, password_hash, is_active, created_at) 
                              VALUES (:username, :full_name, :role, :password_hash, 1, NOW())";
                    
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':username', $username);
                    $stmt->bindParam(':full_name', $full_name);
                    $stmt->bindParam(':role', $role);
                    $stmt->bindParam(':password_hash', $password_hash);
                    $stmt->execute();
                    
                    $new_user_id = $db->lastInsertId();
                    
                    $db->commit();
                    
                    logActivity($user_id, 'add_user', "Added new user: $full_name ($role)");
                    
                    $success = "User added successfully!";
                    header("Location: admin.php?success=user_added&nocache=" . time());
                    exit();
                }
            } catch(Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                $error = "Error adding user: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields";
        }
    }
}

// Check for success messages
if (isset($_GET['success'])) {
    $success_messages = [
        'phone_added' => "Phone added successfully!",
        'accessory_added' => "Accessory added successfully!",
        'phone_sold' => "Phone sold successfully!",
        'accessory_sold' => "Accessory sold successfully!",
        'phone_transferred' => "Phone transferred successfully!",
        'accessory_transferred' => "Accessory transferred successfully!",
        'user_saved' => "User saved successfully!",
        'phone_deleted' => "Phone deleted successfully!",
        'phone_deleted' => "Phone deleted successfully!",
        'accessory_deleted' => "Accessory deleted successfully!",
        'user_added' => "User added successfully!",
        'user_deleted' => "User deleted successfully!"
    ];
    
    if (isset($success_messages[$_GET['success']])) {
        $success = $success_messages[$_GET['success']];
    }
}

// Handle delete operations
if (isset($_GET['delete_phone'])) {
    $phone_id = intval($_GET['delete_phone']);
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT * FROM phones WHERE phone_id = :phone_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':phone_id', $phone_id);
        $stmt->execute();
        $phone = $stmt->fetch();
        
        if ($phone) {
            $updateQuery = "UPDATE phones SET status = 'damaged' WHERE phone_id = :phone_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':phone_id', $phone_id);
            $updateStmt->execute();
            
            logActivity($user_id, 'delete_phone', "Deleted phone: {$phone['brand']} {$phone['model']} ({$phone['iemi_number']})");
            
            $success = "Phone deleted successfully!";
            header("Location: admin.php?success=phone_deleted&nocache=" . time());
            exit();
        }
    } catch(Exception $e) {
        $error = "Error deleting phone: " . $e->getMessage();
    }
}

if (isset($_GET['delete_accessory'])) {
    $accessory_id = intval($_GET['delete_accessory']);
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT * FROM accessories WHERE accessory_id = :accessory_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':accessory_id', $accessory_id);
        $stmt->execute();
        $accessory = $stmt->fetch();
        
        if ($accessory) {
            $updateQuery = "UPDATE accessories SET status = 'out_of_stock' WHERE accessory_id = :accessory_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':accessory_id', $accessory_id);
            $updateStmt->execute();
            
            logActivity($user_id, 'delete_accessory', "Deleted accessory: {$accessory['accessory_name']}");
            
            $success = "Accessory deleted successfully!";
            header("Location: admin.php?success=accessory_deleted&nocache=" . time());
            exit();
        }
    } catch(Exception $e) {
        $error = "Error deleting accessory: " . $e->getMessage();
    }
}

// Handle delete user
if (isset($_GET['delete_user'])) {
    $user_id_to_delete = intval($_GET['delete_user']);
    
    // Prevent self-deletion
    if ($user_id_to_delete == $_SESSION['user_id']) {
        $error = "You cannot delete your own account!";
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $query = "SELECT * FROM users WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $user_id_to_delete);
            $stmt->execute();
            $user_to_delete = $stmt->fetch();
            
            if ($user_to_delete) {
                // Soft delete - set is_active to 0
                $updateQuery = "UPDATE users SET is_active = 0 WHERE user_id = :user_id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(':user_id', $user_id_to_delete);
                $updateStmt->execute();
                
                logActivity($user_id, 'delete_user', "Deleted user: {$user_to_delete['full_name']} ({$user_to_delete['username']})");
                
                $success = "User deleted successfully!";
                header("Location: admin.php?success=user_deleted&nocache=" . time());
                exit();
            } else {
                 $error = "User not found!";
            }
        } catch(Exception $e) {
            $error = "Error deleting user: " . $e->getMessage();
        }
    }
}

// Handle search functionality
$phone_results = [];
$accessory_results = [];
if (isset($_GET['search'])) {
    $search_term = $_GET['search'] ?? '';
    $search_type = $_GET['search_type'] ?? 'both';
    
    if (!empty($search_term)) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $search_term_like = "%$search_term%";
            
            if ($search_type === 'phone' || $search_type === 'both') {
                $query = "SELECT * FROM phones WHERE 
                         brand LIKE :phone_search1 OR 
                         model LIKE :phone_search2 OR 
                         iemi_number LIKE :phone_search3 OR 
                         color LIKE :phone_search4 
                         ORDER BY created_at DESC";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':phone_search1', $search_term_like);
                $stmt->bindParam(':phone_search2', $search_term_like);
                $stmt->bindParam(':phone_search3', $search_term_like);
                $stmt->bindParam(':phone_search4', $search_term_like);
                $stmt->execute();
                $phone_results = $stmt->fetchAll();
            }
            
            if ($search_type === 'accessory' || $search_type === 'both') {
                $query = "SELECT * FROM accessories WHERE 
                         accessory_name LIKE :acc_search1 OR 
                         category LIKE :acc_search2 OR 
                         brand LIKE :acc_search3 
                         ORDER BY created_at DESC";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':acc_search1', $search_term_like);
                $stmt->bindParam(':acc_search2', $search_term_like);
                $stmt->bindParam(':acc_search3', $search_term_like);
                $stmt->execute();
                $accessory_results = $stmt->fetchAll();
            }
        } catch(Exception $e) {
            $error = "Error searching: " . $e->getMessage();
        }
    }
}

// Get statistics from database
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Today's stats
    $query = "SELECT COUNT(*) as today_count FROM phones WHERE DATE(created_at) = CURDATE()";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $today_count = $stmt->fetch()['today_count'] ?? 0;
    
    // Total phones
    $query = "SELECT COUNT(*) as total_phones FROM phones WHERE status = 'in_stock'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $total_phones = $stmt->fetch()['total_phones'] ?? 0;
    
    // Total accessories
    $query = "SELECT SUM(quantity) as total_accessories FROM accessories WHERE status = 'in_stock'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $total_accessories = $stmt->fetch()['total_accessories'] ?? 0;
    
    // Low stock
    $query = "SELECT COUNT(*) as low_stock FROM accessories WHERE quantity < 5 AND status = 'in_stock'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $low_stock = $stmt->fetch()['low_stock'] ?? 0;
    
    // Today's sales
    $query = "SELECT COUNT(*) as today_sales, COALESCE(SUM(sale_price), 0) as today_revenue FROM sales WHERE DATE(sale_date) = CURDATE()";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $today_sales_data = $stmt->fetch();
    $today_sales = $today_sales_data['today_sales'] ?? 0;
    $today_revenue = $today_sales_data['today_revenue'] ?? 0;
    
    // Monthly revenue
    $query = "SELECT COALESCE(SUM(sale_price), 0) as monthly_revenue FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $monthly_revenue = $stmt->fetch()['monthly_revenue'] ?? 0;
    
    // All time revenue
    $query = "SELECT COALESCE(SUM(sale_price), 0) as all_time_revenue FROM sales";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $all_time_revenue = $stmt->fetch()['all_time_revenue'] ?? 0;
    
    // Stock analysis by brand
    $query = "SELECT brand, COUNT(*) as count FROM phones WHERE status = 'in_stock' GROUP BY brand ORDER BY count DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stock_by_brand = $stmt->fetchAll();
    
    // Stock analysis by memory
    $query = "SELECT memory, COUNT(*) as count FROM phones WHERE status = 'in_stock' GROUP BY memory ORDER BY count DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stock_by_memory = $stmt->fetchAll();
    
    // Stock status
    $query = "SELECT 
        SUM(CASE WHEN status = 'in_stock' THEN 1 ELSE 0 END) as in_stock,
        SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold,
        SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned,
        SUM(CASE WHEN status = 'damaged' THEN 1 ELSE 0 END) as damaged
        FROM phones";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stock_status = $stmt->fetch();
    
    // Sales by payment method
    $query = "SELECT payment_method, COUNT(*) as count, SUM(sale_price) as total FROM sales GROUP BY payment_method";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $sales_by_payment = $stmt->fetchAll();
    
    // Sales trend (last 7 days)
    $query = "SELECT DATE(sale_date) as date, COUNT(*) as sales, SUM(sale_price) as revenue FROM sales WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(sale_date) ORDER BY date DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $sales_trend = $stmt->fetchAll();
    
    // Recent phones
    $query = "SELECT * FROM phones ORDER BY created_at DESC LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $recent_phones = $stmt->fetchAll();
    
    // Recent accessories
    $query = "SELECT * FROM accessories ORDER BY created_at DESC LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $recent_accessories = $stmt->fetchAll();
    
    // All phones for view stock
    $query = "SELECT * FROM phones ORDER BY created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $all_phones = $stmt->fetchAll();
    
    // All accessories for view stock
    $query = "SELECT * FROM accessories WHERE status = 'in_stock' AND quantity > 0 ORDER BY created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $all_accessories = $stmt->fetchAll();
    
    // Auto-mark accessories with 0 quantity
    $query = "UPDATE accessories SET status = 'out_of_stock' WHERE quantity = 0 AND status = 'in_stock'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    
} catch(Exception $e) {
    // Set defaults if database error
    $today_count = 0;
    $total_phones = 0;
    $total_accessories = 0;
    $low_stock = 0;
    $today_sales = 0;
    $today_revenue = 0;
    $monthly_revenue = 0;
    $all_time_revenue = 0;
    $stock_by_brand = [];
    $stock_by_memory = [];
    $stock_status = ['in_stock' => 0, 'sold' => 0, 'returned' => 0, 'damaged' => 0];
    $sales_by_payment = [];
    $sales_trend = [];
    $recent_phones = [];
    $recent_accessories = [];
    $all_phones = [];
    $all_accessories = [];
}

// Get recent activities from activity_log
$recent_activities = [];
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "
        SELECT 
            al.*, 
            u.full_name,
            u.role
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.user_id
        ORDER BY al.created_at DESC
        LIMIT 30
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $recent_activities = $stmt->fetchAll();
    
} catch(Exception $e) {
    // Continue without activities if error
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - PhoneStock Pro</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Barcode Scanner Library -->
    <script src="https://unpkg.com/@zxing/library@0.19.1"></script>
    
    <style>
        :root {
            /* Professional admin color palette */
            --primary-color: #1e3a5f;
            --secondary-color: #2c5282;
            --accent-color: #3182ce;
            --success-color: #2f855a;
            --danger-color: #c53030;
            --warning-color: #b7791f;
            --info-color: #2b6cb0;
            --light-bg: #f7fafc;
            --dark-bg: #1a202c;
            --border-color: #e2e8f0;
            --text-primary: #1a202c;
            --text-secondary: #4a5568;
            --card-shadow: 0 2px 4px rgba(0,0,0,0.08);
            --card-hover-shadow: 0 8px 16px rgba(0,0,0,0.12);
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--light-bg);
            color: var(--text-primary);
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        /* ========== SIDEBAR ========== */
        .sidebar {
            background: linear-gradient(180deg, var(--primary-color) 0%, var(--dark-bg) 100%);
            color: white;
            height: 100vh;
            position: fixed;
            width: 260px;
            padding: 0;
            box-shadow:4px 0 12px rgba(0,0,0,0.15);
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom:1px solid rgba(255,255,255,0.1);
            flex-shrink: 0;
        }
        
        .sidebar-header h4 {
            color: white !important;
            font-weight: 700;
        }
        
        .sidebar-header p,
        .sidebar-header .text-muted {
            color: rgba(255, 255, 255, 0.85) !important;
        }
        
        .sidebar-user {
            padding: 15px 20px;
            border-bottom:1px solid rgba(255,255,255,0.1);
            flex-shrink: 0;
        }
        
        .sidebar-user h5 {
            color: white !important;
            font-weight: 600;
        }
        
        .sidebar-user p,
        .sidebar-user .text-muted,
        .sidebar-user .small {
            color: rgba(255, 255, 255, 0.85) !important;
        }
        
        /* SCROLLABLE NAVIGATION */
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 10px 0;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.3) transparent;
        }
        
        .sidebar-nav::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar-nav::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 3px;
        }
        
        .sidebar-nav::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.5);
        }
        
        .nav-link {
            color: #cbd5e0;
            padding: 14px 24px;
            border-left: 4px solid transparent;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            white-space: nowrap;
            text-decoration: none;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.08);
            border-left-color: var(--accent-color);
            color: white;
            padding-left: 28px;
        }
        
        .nav-link.active {
            background: rgba(255,255,255,0.15);
            border-left-color: var(--success-color);
            color: white;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.1);
        }
        
        .nav-link i {
            width: 24px;
            margin-right: 12px;
            opacity: 0.9;
            text-align: center;
        }
        
        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
            flex-shrink: 0;
        }
        
        /* ========== MAIN CONTENT ========== */
        .main-content {
            margin-left: 260px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        
        /* ========== ANIMATED STAT CARDS ========== */
        .stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border-left: 5px solid var(--accent-color);
            position: relative;
            overflow: hidden;
            flex: 1;
            min-width: 180px;
            max-width: 220px;
        }
        
        /* HOVER ANIMATION - Card expands */
        .stat-card:hover {
            transform: scale(1.05) translateY(-8px);
            box-shadow: var(--card-hover-shadow);
            z-index: 10;
            max-width: 280px;
            flex: 1.5;
        }
        
        .stat-card.primary { border-left-color: var(--primary-color); }
        .stat-card.success { border-left-color: var(--success-color); }
        .stat-card.danger { border-left-color: var(--danger-color); }
        .stat-card.warning { border-left-color: var(--warning-color); }
        .stat-card.info { border-left-color: var(--info-color); }
        
        .stat-card-body {
            padding: 20px 16px;
            position: relative;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
            transition: font-size 0.3s ease;
        }
        
        .stat-card:hover .stat-number {
            font-size: 2.4rem;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        .stat-icon {
            font-size: 3rem;
            opacity: 0.08;
            position: absolute;
            right: 10px;
            top: 10px;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover .stat-icon {
            opacity: 0.15;
            transform: scale(1.2) rotate(5deg);
        }
        
        /* ========== QUICK ACTIONS ========== */
        .quick-action {
            background: white;
            padding: 18px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border:1px solid var(--border-color);
            box-shadow: var(--card-shadow);
        }
        
        .quick-action:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-hover-shadow);
            border-color: var(--accent-color);
        }
        
        .action-icon {
            font-size: 1.8rem;
            margin-bottom: 12px;
            color: var(--accent-color);
            transition: transform 0.3s ease;
        }
        
        .quick-action:hover .action-icon {
            transform: scale(1.2);
        }
        
        /* ========== CARDS ========== */
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 14px 20px;
            border-bottom: none;
            font-weight: 600;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* ========== TABLES ========== */
        .table {
            background: white;
        }
        
        .table thead th {
            font-weight: 600;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-color);
            padding: 12px;
            background: var(--light-bg);
        }
        
        .table tbody tr {
            transition: background 0.2s ease;
        }
        
        .table tbody tr:hover {
            background: rgba(49, 130, 206, 0.05);
        }
        
        .table tbody td {
            padding: 12px;
            vertical-align: middle;
        }
        
        /* ========== ACTIVITY TIMELINE ========== */
        .activity-timeline {
            position: relative;
            padding-left: 24px;
        }
        
        .activity-timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(180deg, var(--accent-color) 0%, var(--border-color) 100%);
        }
        
        .activity-item {
            position: relative;
            margin-bottom: 18px;
            padding: 16px;
            background: white;
            border-radius: 8px;
            border:1px solid var(--border-color);
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
        }
        
        .activity-item:hover {
            box-shadow: var(--card-hover-shadow);
            transform: translateX(5px);
        }
        
        .activity-dot {
            position: absolute;
            left: -27px;
            top: 20px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 0 3px;
        }
        
        .activity-dot.sale { background: var(--danger-color); box-shadow: 0 0 0 3px var(--danger-color); }
        .activity-dot.transfer { background: var(--warning-color); box-shadow: 0 0 0 3px var(--warning-color); }
        .activity-dot.add { background: var(--success-color); box-shadow: 0 0 0 3px var(--success-color); }
        .activity-dot.admin { background: var(--primary-color); box-shadow: 0 0 0 3px var(--primary-color); }
        .activity-dot.employee { background: var(--info-color); box-shadow: 0 0 0 3px var(--info-color); }
        
        /* ========== FORMS ========== */
        .form-control, .form-select {
            border:1px solid var(--border-color);
            border-radius: 6px;
            padding: 10px 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        /* ========== BUTTONS ========== */
        .btn {
            border-radius: 6px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .btn-primary {
            background: var(--accent-color);
            border-color: var(--accent-color);
        }
        
        .btn-primary:hover {
            background: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .btn-success {
            background: var(--success-color);
            border-color: var(--success-color);
        }
        
        .btn-danger {
            background: var(--danger-color);
            border-color: var(--danger-color);
        }
        
        .btn-warning {
            background: var(--warning-color);
            border-color: var(--warning-color);
        }
        
        .btn-danger:hover {
            background: var(--danger-color);
            filter: brightness(1.1);
        }
        
        /* ============================================
           BADGE OVERRIDES - Professional Colors
           ============================================ */
        .badge {
            font-weight: 600;
            padding: 0.35em 0.65em;
        }
        
        .badge.bg-primary,
        .bg-primary {
            background-color: var(--accent-color) !important;
            color: white !important;
        }
        
        .badge.bg-success,
        .bg-success {
            background-color: var(--success-color) !important;
            color: white !important;
        }
        
        .badge.bg-danger,
        .bg-danger {
            background-color: var(--danger-color) !important;
            color: white !important;
        }
        
        .badge.bg-warning,
        .bg-warning {
            background-color: var(--warning-color) !important;
            color: white !important;
        }
        
        .badge.bg-info,
        .bg-info {
            background-color: var(--info-color) !important;
            color: white !important;
        }
        
        .badge.bg-secondary,
        .bg-secondary {
            background-color: #64748b !important;
            color: white !important;
        }
        
        /* Text color utilities */
        .text-primary {
            color: var(--accent-color) !important;
        }
        
        .text-success {
            color: var(--success-color) !important;
        }
        
        .text-danger {
            color: var(--danger-color) !important;
        }
        
        .text-warning {
            color: var(--warning-color) !important;
        }
        
        .text-info {
            color: var(--info-color) !important;
        }
        
        /* ========== ALERTS ========== */
        .alert {
            border-radius: 8px;
            border: none;
            padding: 14px 20px;
            box-shadow: var(--card-shadow);
        }
        
        /* ========== RESPONSIVE MOBILE DESIGN ========== */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                width: 280px;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
            .stats-row {
                flex-direction: column;
                gap: 10px;
            }
            
            .stat-card {
                min-width: 100%;
                max-width: 100%;
                flex: none;
            }
            
            .stat-card:hover {
                max-width: 100%;
                flex: none;
            }
        }
        
        @media (max-width: 768px) {
            .stat-number {
                font-size: 1.8rem;
            }
            
            .stat-card:hover .stat-number {
                font-size: 2rem;
            }
            
            .stat-card-body {
                padding: 16px 14px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .card-header {
                padding: 12px 15px;
                font-size: 0.95rem;
            }
            
            .card-body {
                padding: 15px;
            }
            
            .btn {
                padding: 8px 16px;
                font-size: 0.9rem;
            }
        }
        
        /* ========== MOBILE TOGGLE BUTTON ========== */
        .mobile-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 14px;
            cursor: pointer;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .mobile-toggle:hover {
            background: var(--accent-color);
        }
        
        @media (max-width: 992px) {
            .mobile-toggle {
                display: flex;
                align-items: center;
                gap: 8px;
            }
        }
        
        /* ========== OVERLAY ========== */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .sidebar-overlay.show {
            display: block;
            opacity: 1;
        }
        
        /* ========== ANALYSIS CARDS ========== */
        .analysis-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            margin-bottom: 20px;
        }
        
        .analysis-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
            background: var(--light-bg);
        }
        
        .progress-bar {
            background: var(--accent-color);
            transition: width 0.5s ease;
        }
        
        .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding: 10px 14px;
            background: var(--light-bg);
            border-radius: 6px;
        }
        
        .stat-label-side {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .stat-value {
            font-weight: 700;
            color: var(--accent-color);
        }
    </style>
</head>
<body>
    <!-- Mobile Toggle Button -->
    <button class="mobile-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
        <span>Menu</span>
    </button>
    
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h4 class="mb-1"><i class="fas fa-mobile-alt"></i> PhoneStock Pro</h4>
            <p class="text-muted mb-0 small">Admin Dashboard</p>
        </div>
        
        <div class="sidebar-user">
            <p class="mb-1 small text-muted">Welcome,</p>
            <h5 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h5>
            <p class="text-muted small">
                <i class="fas fa-id-badge me-1"></i> Admin ID: <?php echo $user_id; ?>
            </p>
        </div>
        
        <!-- SCROLLABLE NAVIGATION -->
        <nav class="nav flex-column sidebar-nav">
            <a class="nav-link active" href="#" onclick="showSection('dashboard')">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a class="nav-link" href="#" onclick="showSection('addPhone')">
                <i class="fas fa-plus-circle"></i> Add Phone
            </a>
            <a class="nav-link" href="#" onclick="showSection('addAccessory')">
                <i class="fas fa-box"></i> Add Accessory
            </a>
            <a class="nav-link" href="#" onclick="showSection('sellItem')">
                <i class="fas fa-shopping-cart"></i> Sell Item
            </a>
            <a class="nav-link" href="#" onclick="showSection('transferItem')">
                <i class="fas fa-exchange-alt"></i> Transfer Item
            </a>
            <a class="nav-link" href="#" onclick="showSection('viewStock')">
                <i class="fas fa-eye"></i> View Stock
            </a>
            <a class="nav-link" href="#" onclick="showSection('manageUsers')">
                <i class="fas fa-users-cog"></i> Manage Users
            </a>
            <a class="nav-link" href="#" onclick="showSection('stockAnalysis')">
                <i class="fas fa-chart-pie"></i> Stock Analysis
            </a>
            <a class="nav-link" href="#" onclick="showSection('salesAnalysis')">
                <i class="fas fa-chart-line"></i> Sales Analysis
            </a>
            <a class="nav-link" href="#" onclick="showSection('myActivity')">
                <i class="fas fa-history"></i> My Activity
            </a>
            <a class="nav-link" href="#" onclick="showSection('settings')">
                <i class="fas fa-cog"></i> Settings
            </a>
            <a class="nav-link" href="#" onclick="showSection('reports')">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
        </nav>
        
        <div class="sidebar-footer">
            <!-- LOGOUT BUTTON - Redirects to login.php -->
            <a href="admin.php?logout=1&nocache=<?php echo time(); ?>" 
               class="btn btn-outline-danger w-100"
               style="text-decoration: none;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h1 class="mb-1">Admin Dashboard</h1>
                <p class="text-muted mb-0">Monitor and manage your phone store inventory</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted">
                    <i class="fas fa-calendar-alt me-1"></i>
                    <?php echo date('F j, Y'); ?>
                </span>
                <button class="btn btn-success" onclick="showSection('addPhone')">
                    <i class="fas fa-plus"></i> Quick Add Phone
                </button>
            </div>
        </div>
        
        <!-- Display Messages -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- ========== ANIMATED STATS CARDS ========== -->
        <div class="stats-row">
            <div class="stat-card primary">
                <div class="stat-card-body">
                    <i class="fas fa-mobile-alt stat-icon"></i>
                    <div class="stat-number"><?php echo $today_count; ?></div>
                    <div class="stat-label">Added Today</div>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-card-body">
                    <i class="fas fa-boxes stat-icon"></i>
                    <div class="stat-number"><?php echo $total_phones; ?></div>
                    <div class="stat-label">Phones in Stock</div>
                </div>
            </div>
            <div class="stat-card info">
                <div class="stat-card-body">
                    <i class="fas fa-box stat-icon"></i>
                    <div class="stat-number"><?php echo $total_accessories; ?></div>
                    <div class="stat-label">Accessories</div>
                </div>
            </div>
            <div class="stat-card warning">
                <div class="stat-card-body">
                    <i class="fas fa-shopping-bag stat-icon"></i>
                    <div class="stat-number"><?php echo $today_sales; ?></div>
                    <div class="stat-label">Today's Sales</div>
                </div>
            </div>
            <div class="stat-card danger">
                <div class="stat-card-body">
                    <i class="fas fa-money-bill-wave stat-icon"></i>
                    <div class="stat-number">XAF <?php echo number_format($today_revenue, 0); ?></div>
                    <div class="stat-label">Today's Revenue</div>
                </div>
            </div>
            <div class="stat-card primary">
                <div class="stat-card-body">
                    <i class="fas fa-exclamation-triangle stat-icon"></i>
                    <div class="stat-number"><?php echo $low_stock; ?></div>
                    <div class="stat-label">Low Stock Items</div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <h5 class="mb-3" style="color: var(--primary-color); font-weight: 600;">Quick Actions</h5>
                <div class="row g-3">
                    <div class="col-md-2 col-4">
                        <div class="quick-action" onclick="showSection('addPhone')">
                            <div class="action-icon">
                                <i class="fas fa-mobile-alt"></i>
                            </div>
                            <h6>Add Phone</h6>
                        </div>
                    </div>
                    <div class="col-md-2 col-4">
                        <div class="quick-action" onclick="showSection('addAccessory')">
                            <div class="action-icon">
                                <i class="fas fa-headphones"></i>
                            </div>
                            <h6>Add Accessory</h6>
                        </div>
                    </div>
                    <div class="col-md-2 col-4">
                        <div class="quick-action" onclick="showSection('sellItem')">
                            <div class="action-icon">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                            <h6>Sell Item</h6>
                        </div>
                    </div>
                    <div class="col-md-2 col-4">
                        <div class="quick-action" onclick="showSection('transferItem')">
                            <div class="action-icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <h6>Transfer Item</h6>
                        </div>
                    </div>
                    <div class="col-md-2 col-4">
                        <div class="quick-action" onclick="showBarcodeScanner()">
                            <div class="action-icon">
                                <i class="fas fa-barcode"></i>
                            </div>
                            <h6>Scan IMEI</h6>
                        </div>
                    </div>
                    <div class="col-md-2 col-4">
                        <div class="quick-action" onclick="location.reload()">
                            <div class="action-icon">
                                <i class="fas fa-sync-alt"></i>
                            </div>
                            <h6>Refresh</h6>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Dashboard Content -->
        <div id="dashboardContent">
            <!-- Dashboard Section -->
            <div id="dashboard" class="section">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-mobile-alt me-2"></i>Recently Added Phones</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($recent_phones) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Brand</th>
                                                    <th>Model</th>
                                                    <th>IMEI</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($recent_phones as $phone): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($phone['brand']); ?></td>
                                                    <td><?php echo htmlspecialchars($phone['model']); ?></td>
                                                    <td><code><?php echo substr($phone['iemi_number'], 0, 8) . '...'; ?></code></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $phone['status'] == 'in_stock' ? 'success' : 'secondary'; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $phone['status'])); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center">No phones added yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-box me-2"></i>Recent Accessories</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($recent_accessories) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Category</th>
                                                    <th>Qty</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($recent_accessories as $accessory): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($accessory['accessory_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($accessory['category']); ?></td>
                                                    <td><?php echo $accessory['quantity']; ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $accessory['status'] == 'in_stock' ? 'success' : 'secondary'; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $accessory['status'])); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center">No accessories in database.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity Timeline -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Activities</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($recent_activities) > 0): ?>
                                    <div class="activity-timeline">
                                        <?php foreach($recent_activities as $activity): 
                                            $dot_class = 'admin';
                                            if (strpos($activity['action_type'], 'sell') !== false) $dot_class = 'sale';
                                            elseif (strpos($activity['action_type'], 'transfer') !== false) $dot_class = 'transfer';
                                            elseif (strpos($activity['action_type'], 'add') !== false) $dot_class = 'add';
                                            elseif ($activity['role'] == 'employee') $dot_class = 'employee';
                                        ?>
                                            <div class="activity-item">
                                                <div class="activity-dot <?php echo $dot_class; ?>"></div>
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <p class="mb-1"><?php echo htmlspecialchars($activity['description']); ?></p>
                                                        <small class="text-muted">
                                                            <i class="fas fa-user me-1"></i>
                                                            <?php echo htmlspecialchars($activity['full_name'] ?? 'Unknown'); ?>
                                                            <span class="badge bg-<?php echo $activity['role'] == 'admin' ? 'primary' : 'info'; ?> ms-2">
                                                                <?php echo ucfirst($activity['role'] ?? 'User'); ?>
                                                            </span>
                                                            <br>
                                                            <i class="fas fa-clock me-1"></i>
                                                            <?php echo date('M d, Y H:i:s', strtotime($activity['created_at'])); ?>
                                                        </small>
                                                    </div>
                                                    <span class="badge bg-<?php 
                                                        echo $dot_class == 'sale' ? 'danger' : 
                                                             ($dot_class == 'transfer' ? 'warning' : 
                                                             ($dot_class == 'add' ? 'success' : 
                                                             ($dot_class == 'employee' ? 'info' : 'primary'))); 
                                                    ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $activity['action_type'])); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center">No recent activities recorded.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Add Phone Section -->
            <div id="addPhone" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-mobile-alt me-2"></i>Add New Phone</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" onsubmit="return validatePhoneForm()">
                            <input type="hidden" name="add_phone" value="1">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Brand *</label>
                                        <select class="form-select" name="brand" id="brand" required>
                                            <option value="">Select Brand</option>
                                            <option>Samsung</option>
                                            <option>Apple</option>
                                            <option>Xiaomi</option>
                                            <option>Huawei</option>
                                            <option>Oppo</option>
                                            <option>Vivo</option>
                                            <option>Realme</option>
                                            <option>OnePlus</option>
                                            <option>Google</option>
                                            <option>Other</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Model *</label>
                                        <input type="text" class="form-control" name="model" id="model" placeholder="e.g., Galaxy S23" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">IMEI Number *</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="imei" id="imei" placeholder="15-digit IMEI" required minlength="15" maxlength="15">
                                            <button type="button" class="btn btn-outline-secondary" onclick="showBarcodeScanner('imei')">
                                                <i class="fas fa-barcode"></i> Scan IMEI
                                            </button>
                                        </div>
                                        <small class="text-muted">Enter 15-digit IMEI number or use barcode scanner</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Color</label>
                                        <input type="text" class="form-control" name="color" id="color" placeholder="e.g., Black, Blue, White">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Memory</label>
                                        <select class="form-select" name="memory" id="memory">
                                            <option value="">Select Memory</option>
                                            <option>64GB</option>
                                            <option>128GB</option>
                                            <option>256GB</option>
                                            <option>512GB</option>
                                            <option>1TB</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Buying Price (XAF)</label>
                                        <input type="number" class="form-control" name="buying_price" id="buying_price" step="0.01" placeholder="0.00" min="0">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Selling Price (XAF)</label>
                                        <input type="number" class="form-control" name="selling_price" id="selling_price" step="0.01" placeholder="0.00" min="0">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" id="notes" rows="2" placeholder="Any additional information..."></textarea>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save"></i> Save Phone
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Clear Form
                                </button>
                                <button type="button" class="btn btn-primary" onclick="showSection('dashboard')">
                                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Add Accessory Section -->
            <div id="addAccessory" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-box me-2"></i>Add New Accessory</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" onsubmit="return validateAccessoryForm()">
                            <input type="hidden" name="add_accessory" value="1">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Accessory Name *</label>
                                        <input type="text" class="form-control" name="accessory_name" id="accessory_name" placeholder="e.g., iPhone 14 Case, Samsung Charger" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Category *</label>
                                        <select class="form-select" name="category" id="category" required>
                                            <option value="">Select Category</option>
                                            <option>Phone Case</option>
                                            <option>Screen Protector</option>
                                            <option>Charger</option>
                                            <option>Earphones/Headphones</option>
                                            <option>Power Bank</option>
                                            <option>Cable</option>
                                            <option>Memory Card</option>
                                            <option>Other</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Brand</label>
                                        <input type="text" class="form-control" name="accessory_brand" id="accessory_brand" placeholder="e.g., Apple, Samsung, Generic">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Quantity *</label>
                                        <input type="number" class="form-control" name="quantity" id="quantity" value="1" min="1" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Buying Price (XAF)</label>
                                        <input type="number" class="form-control" name="buying_price" id="buying_price" step="0.01" placeholder="0.00" min="0">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Selling Price (XAF) each</label>
                                        <input type="number" class="form-control" name="accessory_selling_price" id="accessory_selling_price" step="0.01" placeholder="0.00" min="0">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save"></i> Save Accessory
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Clear Form
                                </button>
                                <button type="button" class="btn btn-primary" onclick="showSection('dashboard')">
                                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Sell Item Section -->
            <div id="sellItem" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-shopping-cart me-2"></i>Sell Item</h5>
                    </div>
                    <div class="card-body">
                        <ul class="nav nav-tabs" id="sellTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="sell-phone-tab" data-bs-toggle="tab" data-bs-target="#sell-phone" type="button" role="tab">
                                    <i class="fas fa-mobile-alt me-1"></i> Sell Phone
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="sell-accessory-tab" data-bs-toggle="tab" data-bs-target="#sell-accessory" type="button" role="tab">
                                    <i class="fas fa-box me-1"></i> Sell Accessory
                                </button>
                            </li>
                        </ul>
                        
                        <div class="tab-content mt-3" id="sellTabContent">
                            <!-- Sell Phone Tab -->
                            <div class="tab-pane fade show active" id="sell-phone" role="tabpanel">
                                <form method="POST" action="" onsubmit="return validateSellPhoneForm()">
                                    <input type="hidden" name="sell_phone" value="1">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Select Phone *</label>
                                                <select class="form-select" name="phone_id" id="sell_phone_id" required onchange="updatePhoneSalePrice()">
                                                    <option value="">Select a phone...</option>
                                                    <?php foreach($all_phones as $phone): ?>
                                                    <?php if ($phone['status'] == 'in_stock'): ?>
                                                    <option value="<?php echo $phone['phone_id']; ?>" data-price="<?php echo $phone['selling_price']; ?>">
                                                        <?php echo htmlspecialchars($phone['brand'] . ' ' . $phone['model']); ?> - 
                                                        <?php echo htmlspecialchars($phone['iemi_number']); ?>
                                                    </option>
                                                    <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Sale Price (XAF) *</label>
                                                <input type="number" class="form-control" name="sale_price" id="sale_price" step="0.01" required min="0">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Customer Name</label>
                                                <input type="text" class="form-control" name="customer_name" placeholder="Optional">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Customer Phone</label>
                                                <input type="text" class="form-control" name="customer_phone" placeholder="Optional">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Payment Method *</label>
                                                <select class="form-select" name="payment_method" required>
                                                    <option value="cash">Cash</option>
                                                    <option value="mobile_money">Mobile Money</option>
                                                    <option value="card">Card</option>
                                                    <option value="credit">Credit</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Notes</label>
                                                <textarea class="form-control" name="sale_notes" rows="3" placeholder="Sale notes..."></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-danger">
                                            <i class="fas fa-cash-register"></i> Complete Sale
                                        </button>
                                        <button type="reset" class="btn btn-secondary">
                                            <i class="fas fa-redo"></i> Clear Form
                                        </button>
                                        <button type="button" class="btn btn-primary" onclick="showSection('dashboard')">
                                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Sell Accessory Tab -->
                            <div class="tab-pane fade" id="sell-accessory" role="tabpanel">
                                <form method="POST" action="" onsubmit="return validateSellAccessoryForm()">
                                    <input type="hidden" name="sell_accessory" value="1">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Select Accessory *</label>
                                                <select class="form-select" name="accessory_id" id="sell_accessory_id" required onchange="updateAccessorySalePrice()">
                                                    <option value="">Select an accessory...</option>
                                                    <?php foreach($all_accessories as $accessory): ?>
                                                    <option value="<?php echo $accessory['accessory_id']; ?>" 
                                                            data-price="<?php echo $accessory['selling_price']; ?>"
                                                            data-quantity="<?php echo $accessory['quantity']; ?>">
                                                        <?php echo htmlspecialchars($accessory['accessory_name']); ?> - 
                                                        Stock: <?php echo $accessory['quantity']; ?> - 
                                                        XAF <?php echo number_format($accessory['selling_price'], 2); ?> each
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Quantity *</label>
                                                        <input type="number" class="form-control" name="sale_quantity" id="sale_quantity" value="1" min="1" required onchange="calculateTotalAccessoryPrice()">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Price Each (XAF) *</label>
                                                        <input type="number" class="form-control" name="sale_price" id="accessory_sale_price" step="0.01" required min="0" onchange="calculateTotalAccessoryPrice()">
                                                    </div>
                                                </div>
                                                </div>
                                            <div class="mb-3">
                                                <div class="alert alert-info">
                                                    <strong>Total Price:</strong> XAF <span id="total_accessory_price">0.00</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Customer Name</label>
                                                <input type="text" class="form-control" name="customer_name" placeholder="Optional">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Customer Phone</label>
                                                <input type="text" class="form-control" name="customer_phone" placeholder="Optional">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Payment Method *</label>
                                                <select class="form-select" name="payment_method" required>
                                                    <option value="cash">Cash</option>
                                                    <option value="mobile_money">Mobile Money</option>
                                                    <option value="card">Card</option>
                                                    <option value="credit">Credit</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Notes</label>
                                        <textarea class="form-control" name="sale_notes" rows="2" placeholder="Sale notes..."></textarea>
                                    </div>
                                    
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-danger">
                                            <i class="fas fa-cash-register"></i> Complete Sale
                                        </button>
                                        <button type="reset" class="btn btn-secondary">
                                            <i class="fas fa-redo"></i> Clear Form
                                        </button>
                                        <button type="button" class="btn btn-primary" onclick="showSection('dashboard')">
                                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Transfer Item Section -->
            <div id="transferItem" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-exchange-alt me-2"></i>Transfer Item to Another Shop</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" onsubmit="return validateTransferForm()">
                            <input type="hidden" name="transfer_item" value="1">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Item Type *</label>
                                        <select class="form-select" name="item_type" id="item_type" required onchange="toggleTransferFields()">
                                            <option value="">Select Type</option>
                                            <option value="phone">Phone</option>
                                            <option value="accessory">Accessory</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3" id="phone_select_field" style="display: none;">
                                        <label class="form-label">Select Phone *</label>
                                        <select class="form-select" name="phone_transfer_id" id="transfer_phone_id" required>
                                            <option value="">Select a phone...</option>
                                            <?php foreach($all_phones as $phone): ?>
                                            <?php if ($phone['status'] == 'in_stock'): ?>
                                            <option value="<?php echo $phone['phone_id']; ?>">
                                                <?php echo htmlspecialchars($phone['brand'] . ' ' . $phone['model']); ?> - 
                                                <?php echo htmlspecialchars($phone['iemi_number']); ?>
                                            </option>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3" id="accessory_select_field" style="display: none;">
                                        <label class="form-label">Select Accessory *</label>
                                        <select class="form-select" name="accessory_transfer_id" id="transfer_accessory_id" required onchange="updateTransferQuantity()">
                                            <option value="">Select an accessory...</option>
                                            <?php foreach($all_accessories as $accessory): ?>
                                            <option value="<?php echo $accessory['accessory_id']; ?>" data-quantity="<?php echo $accessory['quantity']; ?>">
                                                <?php echo htmlspecialchars($accessory['accessory_name']); ?> - 
                                                Stock: <?php echo $accessory['quantity']; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3" id="quantity_field" style="display: none;">
                                        <label class="form-label">Quantity *</label>
                                        <input type="number" class="form-control" name="transfer_quantity" id="transfer_quantity" value="1" min="1" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Destination Shop *</label>
                                        <select class="form-select" name="destination_shop" required>
                                            <option value="">Select destination shop...</option>
                                            <?php foreach($all_shops as $shop): ?>
                                            <?php if ($shop['shop_id'] != 1): ?>
                                            <option value="<?php echo $shop['shop_id']; ?>">
                                                <?php echo htmlspecialchars($shop['shop_name']); ?>
                                            </option>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Transfer Notes</label>
                                        <textarea class="form-control" name="transfer_notes" rows="4" placeholder="Reason for transfer, special instructions..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Transferring items will automatically update stock quantities and track movement between shops.
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-truck"></i> Complete Transfer
                                </button>
                                <button type="reset" class="btn btn-secondary" onclick="resetTransferForm()">
                                    <i class="fas fa-redo"></i> Clear Form
                                </button>
                                <button type="button" class="btn btn-primary" onclick="showSection('dashboard')">
                                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- View Stock Section -->
            <div id="viewStock" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-eye me-2"></i>View All Stock</h5>
                    </div>
                    <div class="card-body">
                        <!-- Search Bar -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <form method="GET" action="" class="row g-2">
                                    <div class="col-md-8">
                                        <input type="text" class="form-control" name="search" placeholder="Search by brand, model, IMEI, accessory name..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select" name="search_type">
                                            <option value="both" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] == 'both') ? 'selected' : ''; ?>>Both Phones & Accessories</option>
                                            <option value="phone" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] == 'phone') ? 'selected' : ''; ?>>Phones Only</option>
                                            <option value="accessory" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] == 'accessory') ? 'selected' : ''; ?>>Accessories Only</option>
                                        </select>
                                    </div>
                                    <div class="col-md-1">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <?php if (!empty($search_term)): ?>
                            <div class="alert alert-info">
                                Search results for: <strong><?php echo htmlspecialchars($search_term); ?></strong>
                                <a href="admin.php" class="float-end btn btn-sm btn-outline-info">
                                    <i class="fas fa-times"></i> Clear Search
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Search Results -->
                        <?php if (isset($_GET['search']) && !empty($search_term)): ?>
                            <?php if (count($phone_results) > 0 || count($accessory_results) > 0): ?>
                                <!-- Phones Table -->
                                <?php if (count($phone_results) > 0): ?>
                                    <h5 class="mt-4">Phones (<?php echo count($phone_results); ?>)</h5>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Brand</th>
                                                    <th>Model</th>
                                                    <th>IMEI</th>
                                                    <th>Color</th>
                                                    <th>Memory</th>
                                                    <th>Price</th>
                                                    <th>Status</th>
                                                    <th>Added On</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($phone_results as $phone): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($phone['brand']); ?></td>
                                                    <td><?php echo htmlspecialchars($phone['model']); ?></td>
                                                    <td><code><?php echo htmlspecialchars($phone['iemi_number']); ?></code></td>
                                                    <td><?php echo htmlspecialchars($phone['color']); ?></td>
                                                    <td><?php echo htmlspecialchars($phone['memory']); ?></td>
                                                    <td><?php echo number_format($phone['selling_price'], 2); ?> XAF</td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $phone['status'] == 'in_stock' ? 'success' : 
                                                            ($phone['status'] == 'sold' ? 'danger' : 'secondary'); 
                                                        ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $phone['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M d, Y', strtotime($phone['created_at'])); ?></td>
                                                    <td>
                                                        <?php if ($phone['status'] == 'in_stock'): ?>
                                                        <a href="admin.php?delete_phone=<?php echo $phone['phone_id']; ?>" 
                                                           class="btn btn-sm btn-danger" 
                                                           onclick="return confirm('Delete this phone?')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Accessories Table -->
                                <?php if (count($accessory_results) > 0): ?>
                                    <h5 class="mt-4">Accessories (<?php echo count($accessory_results); ?>)</h5>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Category</th>
                                                    <th>Brand</th>
                                                    <th>Quantity</th>
                                                    <th>Price Each</th>
                                                    <th>Status</th>
                                                    <th>Added On</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($accessory_results as $accessory): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($accessory['accessory_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($accessory['category']); ?></td>
                                                    <td><?php echo htmlspecialchars($accessory['brand']); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo $accessory['quantity'] < 5 ? 'bg-danger' : 'bg-primary'; ?>">
                                                            <?php echo $accessory['quantity']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo number_format($accessory['selling_price'], 2); ?> XAF</td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $accessory['status'] == 'in_stock' ? 'success' : 'danger'; 
                                                        ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $accessory['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M d, Y', strtotime($accessory['created_at'])); ?></td>
                                                    <td>
                                                        <?php if ($accessory['status'] == 'in_stock'): ?>
                                                        <a href="admin.php?delete_accessory=<?php echo $accessory['accessory_id']; ?>" 
                                                           class="btn btn-sm btn-danger" 
                                                           onclick="return confirm('Delete this accessory?')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-search me-2"></i> No results found for your search.
                                </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <!-- All Phones Table -->
                            <h5 class="mt-4">All Phones (<?php echo count($all_phones); ?>)</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Brand</th>
                                            <th>Model</th>
                                            <th>IMEI</th>
                                            <th>Color</th>
                                            <th>Memory</th>
                                            <th>Price</th>
                                            <th>Status</th>
                                            <th>Added On</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($all_phones) > 0): ?>
                                            <?php foreach($all_phones as $phone): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($phone['brand']); ?></td>
                                                <td><?php echo htmlspecialchars($phone['model']); ?></td>
                                                <td><code><?php echo htmlspecialchars($phone['iemi_number']); ?></code></td>
                                                <td><?php echo htmlspecialchars($phone['color']); ?></td>
                                                <td><?php echo htmlspecialchars($phone['memory']); ?></td>
                                                <td><?php echo number_format($phone['selling_price'], 2); ?> XAF</td>
                                                <td>
                                                    <span class="badge bg-<?php echo $phone['status'] == 'in_stock' ? 'success' : 'secondary'; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $phone['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($phone['created_at'])); ?></td>
                                                <td>
                                                    <?php if ($phone['status'] == 'in_stock'): ?>
                                                    <a href="admin.php?delete_phone=<?php echo $phone['phone_id']; ?>" 
                                                       class="btn btn-sm btn-danger" 
                                                       onclick="return confirm('Delete this phone?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="9" class="text-center text-muted">No phones in stock.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- All Accessories Table -->
                            <h5 class="mt-4">All Accessories (<?php echo count($all_accessories); ?>)</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Name</th>
                                            <th>Category</th>
                                            <th>Brand</th>
                                            <th>Quantity</th>
                                            <th>Price Each</th>
                                            <th>Status</th>
                                            <th>Added On</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($all_accessories) > 0): ?>
                                            <?php foreach($all_accessories as $accessory): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($accessory['accessory_name']); ?></td>
                                                <td><?php echo htmlspecialchars($accessory['category']); ?></td>
                                                <td><?php echo htmlspecialchars($accessory['brand']); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $accessory['quantity'] < 5 ? 'bg-danger' : 'bg-primary'; ?>">
                                                        <?php echo $accessory['quantity']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo number_format($accessory['selling_price'], 2); ?> XAF</td>
                                                <td>
                                                    <span class="badge bg-<?php echo $accessory['status'] == 'in_stock' ? 'success' : 'secondary'; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $accessory['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($accessory['created_at'])); ?></td>
                                                <td>
                                                    <a href="admin.php?delete_accessory=<?php echo $accessory['accessory_id']; ?>" 
                                                       class="btn btn-sm btn-danger" 
                                                       onclick="return confirm('Delete this accessory?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center text-muted">No accessories in stock.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Manage Users Section -->
            <div id="manageUsers" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-users-cog me-2"></i>Manage Users</h5>
                        <button class="btn btn-sm btn-light" onclick="showAddUserModal()">
                            <i class="fas fa-plus"></i> Add User
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Full Name</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Last Login</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($all_users as $user): ?>
                                    <tr>
                                        <td><?php echo $user['user_id']; ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $user['role'] == 'admin' ? 'primary' : 'info'; ?>">
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'Never'; ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="editUser(<?php echo $user['user_id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                            <a href="admin.php?delete_user=<?php echo $user['user_id']; ?>" 
                                               class="btn btn-sm btn-danger" 
                                               onclick="return confirm('Are you sure you want to delete this user?');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Add User Modal (Hidden by default, toggled via JS/CSS or we can just make it a section) -->
                <!-- We will implement it as a collapsible card inside the Manage Users section for simplicity -->
                <div class="card mt-4" id="addUserForm" style="display: none;">
                     <div class="card-header bg-light text-dark">
                        <h6 class="mb-0">Add New User</h6>
                     </div>
                     <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="add_user" value="1">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Full Name *</label>
                                        <input type="text" class="form-control" name="full_name" required placeholder="e.g. John Doe">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Username *</label>
                                        <input type="text" class="form-control" name="username" required placeholder="e.g. john_doe">
                                        <small class="text-muted">Auto-generated from name if left blank (logic in JS recommended but we'll force input)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Role *</label>
                                        <select class="form-select" name="role">
                                            <option value="employee">Employee</option>
                                            <option value="admin">Admin</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Password</label>
                                        <input type="password" class="form-control" name="password" placeholder="Leave blank for default (123456)">
                                        <small class="text-muted">Required for Admin login.</small>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-secondary" onclick="document.getElementById('addUserForm').style.display='none'">Cancel</button>
                                <button type="submit" class="btn btn-primary">Create User</button>
                            </div>
                        </form>
                     </div>
                </div>
                </div>
            </div>
            
            <!-- Stock Analysis Section -->
            <div id="stockAnalysis" class="section" style="display: none;">
                <h4 class="mb-4" style="color: var(--primary-color); font-weight: 600;">Stock Analysis</h4>
                
                <div class="row">
                    <!-- Stock by Brand -->
                    <div class="col-md-6">
                        <div class="analysis-card">
                            <div class="analysis-title"><i class="fas fa-mobile-alt me-2"></i>Stock by Brand</div>
                            <?php if (count($stock_by_brand) > 0): ?>
                                <?php $max_brand = max(array_column($stock_by_brand, 'count')); ?>
                                <?php foreach($stock_by_brand as $brand): ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="fw-semibold"><?php echo htmlspecialchars($brand['brand']); ?></span>
                                        <span class="text-primary fw-bold"><?php echo $brand['count']; ?></span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" 
                                             style="width: <?php echo ($brand['count'] / $max_brand) * 100; ?>%"
                                             aria-valuenow="<?php echo $brand['count']; ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="<?php echo $max_brand; ?>">
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted text-center">No stock data available.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Stock by Memory -->
                    <div class="col-md-6">
                        <div class="analysis-card">
                            <div class="analysis-title"><i class="fas fa-memory me-2"></i>Stock by Memory</div>
                            <?php if (count($stock_by_memory) > 0): ?>
                                <?php $max_memory = max(array_column($stock_by_memory, 'count')); ?>
                                <?php foreach($stock_by_memory as $memory): ?>
                                <?php if (!empty($memory['memory'])): ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="fw-semibold"><?php echo htmlspecialchars($memory['memory']); ?></span>
                                        <span class="text-primary fw-bold"><?php echo $memory['count']; ?></span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" 
                                             style="width: <?php echo ($memory['count'] / $max_memory) * 100; ?>%"
                                             aria-valuenow="<?php echo $memory['count']; ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="<?php echo $max_memory; ?>">
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted text-center">No memory data available.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Stock Status Overview -->
                <div class="analysis-card">
                    <div class="analysis-title"><i class="fas fa-chart-pie me-2"></i>Stock Status Overview</div>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-row">
                                <span class="stat-label-side">In Stock</span>
                                <span class="stat-value text-success"><?php echo $stock_status['in_stock'] ?? 0; ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-row">
                                <span class="stat-label-side">Sold</span>
                                <span class="stat-value text-danger"><?php echo $stock_status['sold'] ?? 0; ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-row">
                                <span class="stat-label-side">Returned</span>
                                <span class="stat-value text-warning"><?php echo $stock_status['returned'] ?? 0; ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-row">
                                <span class="stat-label-side">Damaged</span>
                                <span class="stat-value text-secondary"><?php echo $stock_status['damaged'] ?? 0; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Low Stock Alert -->
                <div class="analysis-card">
                    <div class="analysis-title"><i class="fas fa-exclamation-triangle me-2"></i>Low Stock Items</div>
                    <?php try {
                        $database = new Database();
                        $db = $database->getConnection();
                        $query = "SELECT accessory_name, category, quantity FROM accessories WHERE quantity < 5 AND status = 'in_stock' ORDER BY quantity ASC LIMIT 10";
                        $stmt = $db->prepare($query);
                        $stmt->execute();
                        $low_stock_items = $stmt->fetchAll();
                        
                        if (count($low_stock_items) > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Accessory Name</th>
                                        <th>Category</th>
                                        <th>Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($low_stock_items as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['accessory_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['category']); ?></td>
                                        <td>
                                            <span class="badge bg-danger"><?php echo $item['quantity']; ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="text-success"><i class="fas fa-check-circle me-2"></i>No low stock items at the moment!</p>
                        <?php endif;
                    } catch(Exception $e) { ?>
                        <p class="text-muted">Unable to load low stock data.</p>
                    <?php } ?>
                </div>
            </div>
            
            <!-- Sales Analysis Section -->
            <div id="salesAnalysis" class="section" style="display: none;">
                <h4 class="mb-4" style="color: var(--primary-color); font-weight: 600;">Sales Analysis</h4>
                
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="stat-card success">
                            <div class="stat-card-body">
                                <i class="fas fa-money-bill-wave stat-icon"></i>
                                <div class="stat-number">XAF <?php echo number_format($today_revenue, 0); ?></div>
                                <div class="stat-label">Today's Revenue</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card info">
                            <div class="stat-card-body">
                                <i class="fas fa-chart-line stat-icon"></i>
                                <div class="stat-number">XAF <?php echo number_format($monthly_revenue, 0); ?></div>
                                <div class="stat-label">Monthly Revenue</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card primary">
                            <div class="stat-card-body">
                                <i class="fas fa-globe stat-icon"></i>
                                <div class="stat-number">XAF <?php echo number_format($all_time_revenue, 0); ?></div>
                                <div class="stat-label">All Time Revenue</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Sales by Payment Method -->
                <div class="analysis-card">
                    <div class="analysis-title"><i class="fas fa-credit-card me-2"></i>Sales by Payment Method</div>
                    <?php if (count($sales_by_payment) > 0): ?>
                        <?php $total_sales = array_sum(array_column($sales_by_payment, 'count')); ?>
                        <?php foreach($sales_by_payment as $payment): ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-semibold">
                                    <?php 
                                    $payment_label = ucfirst($payment['payment_method']);
                                    if ($payment['payment_method'] == 'mobile_money') $payment_label = 'Mobile Money';
                                    echo $payment_label;
                                    ?>
                                </span>
                                <span>
                                    <span class="text-primary fw-bold"><?php echo $payment['count']; ?> sales</span>
                                    <span class="text-muted ms-2">XAF <?php echo number_format($payment['total'], 0); ?></span>
                                </span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" 
                                     style="width: <?php echo ($payment['count'] / $total_sales) * 100; ?>%"
                                     aria-valuenow="<?php echo $payment['count']; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="<?php echo $total_sales; ?>">
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center">No sales data available.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Sales Trend (Last 7 Days) -->
                <div class="analysis-card">
                    <div class="analysis-title"><i class="fas fa-chart-area me-2"></i>Sales Trend (Last 7 Days)</div>
                    <?php if (count($sales_trend) > 0): ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Sales Count</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($sales_trend as $trend): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($trend['date'])); ?></td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $trend['sales']; ?></span>
                                    </td>
                                    <td class="fw-bold text-success">XAF <?php echo number_format($trend['revenue'], 0); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="text-muted text-center">No sales trend data available for last 7 days.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Recent Sales -->
                <div class="analysis-card">
                    <div class="analysis-title"><i class="fas fa-history me-2"></i>Recent Sales</div>
                    <?php try {
                        $database = new Database();
                        $db = $database->getConnection();
                        
                        $query = "
                            SELECT s.*, 
                                   CASE WHEN s.item_type = 'phone' THEN 
                                       (SELECT CONCAT(brand, ' ', model) FROM phones WHERE phone_id = s.item_id)
                                   ELSE 
                                       (SELECT accessory_name FROM accessories WHERE accessory_id = s.item_id)
                                   END as item_name
                            FROM sales s
                            ORDER BY s.sale_date DESC
                            LIMIT 10
                        ";
                        
                        $stmt = $db->prepare($query);
                        $stmt->execute();
                        $recent_sales = $stmt->fetchAll();
                        
                        if (count($recent_sales) > 0): ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Price</th>
                                            <th>Payment</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($recent_sales as $sale): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($sale['item_name']); ?></td>
                                            <td class="fw-bold text-success">XAF <?php echo number_format($sale['sale_price'], 0); ?></td>
                                            <td><?php echo ucfirst($sale['payment_method']); ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($sale['sale_date'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center">No recent sales recorded.</p>
                        <?php endif;
                    } catch(Exception $e) {
                        echo '<p class="text-muted text-center">Could not load recent sales data.</p>';
                    }
                    ?>
                </div>
            </div>
            
            <!-- My Activity Section -->
            <div id="myActivity" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>My Activity Log</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <select class="form-select" id="activityFilter" onchange="filterActivities()">
                                    <option value="all">All Activities</option>
                                    <option value="add">Added Items</option>
                                    <option value="sell">Sales</option>
                                    <option value="transfer">Transfers</option>
                                    <option value="system">System Actions</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="activityPeriod" onchange="filterActivities()">
                                    <option value="today">Today</option>
                                    <option value="week">This Week</option>
                                    <option value="month">This Month</option>
                                    <option value="all">All Time</option>
                                </select>
                            </div>
                        </div>
                        
                        <?php
                        try {
                            $database = new Database();
                            $db = $database->getConnection();
                            
                            $query = "
                                SELECT 'activity' as source, action_type as type, description, created_at as date_time
                                FROM activity_log 
                                WHERE user_id = :user_id1
                                
                                UNION ALL
                                
                                SELECT 'sale' as source, 'sale' as type, 
                                       CONCAT('Sold ', 
                                           CASE WHEN item_type = 'phone' THEN 
                                               (SELECT CONCAT(brand, ' ', model) FROM phones WHERE phone_id = s.item_id)
                                           ELSE 
                                               (SELECT accessory_name FROM accessories WHERE accessory_id = s.item_id)
                                           END,
                                           ' for XAF ', FORMAT(sale_price, 2),
                                           CASE WHEN customer_name IS NOT NULL AND customer_name != '' THEN 
                                               CONCAT(' to ', customer_name)
                                           ELSE ''
                                           END
                                       ) as description,
                                       sale_date as date_time
                                FROM sales s
                                WHERE sold_by = :user_id2
                                
                                UNION ALL
                                
                                SELECT 'transfer' as source, 'transfer' as type,
                                       CONCAT('Transferred ', 
                                           CASE WHEN item_type = 'phone' THEN 
                                               (SELECT CONCAT(brand, ' ', model) FROM phones WHERE phone_id = t.item_id)
                                           ELSE 
                                               (SELECT CONCAT(accessory_name, ' x', quantity) FROM accessories WHERE accessory_id = t.item_id)
                                           END,
                                           ' to Shop ', destination_shop_id
                                       ) as description,
                                       transfer_date as date_time
                                FROM transfers t
                                WHERE transferred_by = :user_id3
                                
                                ORDER BY date_time DESC
                                LIMIT 100
                            ";
                            
                            $stmt = $db->prepare($query);
                            $stmt->bindParam(':user_id1', $user_id);
                            $stmt->bindParam(':user_id2', $user_id);
                            $stmt->bindParam(':user_id3', $user_id);
                            $stmt->execute();
                            $all_activities = $stmt->fetchAll();
                            
                            if (count($all_activities) > 0): ?>
                                <div class="activity-timeline" id="activityTimeline">
                                    <?php foreach($all_activities as $activity): 
                                        $activity_type = $activity['type'];
                                        $activity_class = '';
                                        $badge_color = '';
                                        
                                        if ($activity['source'] == 'sale') {
                                            $activity_class = 'sale';
                                            $badge_color = 'danger';
                                        } elseif ($activity['source'] == 'transfer') {
                                            $activity_class = 'transfer';
                                            $badge_color = 'warning';
                                        } elseif (strpos($activity_type, 'add_') === 0) {
                                            $activity_class = 'add';
                                            $badge_color = 'success';
                                        } else {
                                            $activity_class = 'admin';
                                            $badge_color = 'primary';
                                        }
                                    ?>
                                        <div class="activity-item" data-type="<?php echo $activity_class; ?>" data-date="<?php echo $activity['date_time']; ?>">
                                            <div class="activity-dot <?php echo $activity_class; ?>"></div>
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <p class="mb-1"><?php echo htmlspecialchars($activity['description']); ?></p>
                                                    <small class="text-muted">
                                                        <i class="fas fa-clock me-1"></i>
                                                        <?php echo date('M d, Y H:i:s', strtotime($activity['date_time'])); ?>
                                                    </small>
                                                </div>
                                                <span class="badge bg-<?php echo $badge_color; ?>">
                                                    <?php 
                                                    if ($activity['source'] == 'sale') echo 'Sale';
                                                    elseif ($activity['source'] == 'transfer') echo 'Transfer';
                                                    else echo ucfirst(str_replace('_', ' ', $activity_type));
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center">No activity recorded yet.</p>
                            <?php endif;
                        } catch(Exception $e) {
                            echo '<div class="alert alert-warning">';
                            echo '<i class="fas fa-exclamation-triangle me-2"></i>';
                            echo 'Could not load activity log.';
                            echo '</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <!-- Reports Section -->
            <div id="reports" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Reports & Analytics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="analysis-card">
                                    <h3 class="mb-3">Daily Report</h3>
                                    <p class="text-muted">Today's sales and activities</p>
                                    <a href="report_daily.php" target="_blank" class="btn btn-primary">
                                        <i class="fas fa-file-pdf me-2"></i>Generate Daily Report
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="analysis-card">
                                    <h3 class="mb-3">Weekly Report</h3>
                                    <p class="text-muted">This week's performance</p>
                                    <a href="report_weekly.php" target="_blank" class="btn btn-success">
                                        <i class="fas fa-file-excel me-2"></i>Generate Weekly Report
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="analysis-card">
                                    <h3 class="mb-3">Monthly Report</h3>
                                    <p class="text-muted">This month's overview</p>
                                    <a href="report_monthly.php" target="_blank" class="btn btn-warning">
                                        <i class="fas fa-chart-line me-2"></i>Generate Monthly Report
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Statistics -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Recent Sales</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php
                                        try {
                                            $database = new Database();
                                            $db = $database->getConnection();
                                            
                                            $query = "
                                                SELECT s.*, 
                                                       CASE WHEN s.item_type = 'phone' THEN 
                                                           (SELECT CONCAT(brand, ' ', model) FROM phones WHERE phone_id = s.item_id)
                                                       ELSE 
                                                           (SELECT accessory_name FROM accessories WHERE accessory_id = s.item_id)
                                                       END as item_name
                                                FROM sales s
                                                ORDER BY s.sale_date DESC
                                                LIMIT 10
                                            ";
                                            
                                            $stmt = $db->prepare($query);
                                            $stmt->execute();
                                            $recent_sales = $stmt->fetchAll();
                                            
                                            if (count($recent_sales) > 0): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm">
                                                        <thead>
                                                            <tr>
                                                                <th>Item</th>
                                                                <th>Amount</th>
                                                                <th>Date</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach($recent_sales as $sale): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($sale['item_name']); ?></td>
                                                                <td>XAF <?php echo number_format($sale['sale_price'], 2); ?></td>
                                                                <td><?php echo date('M d, H:i', strtotime($sale['sale_date'])); ?></td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <p class="text-muted text-center">No sales recorded yet.</p>
                                            <?php endif;
                                        } catch(Exception $e) {
                                            echo '<p class="text-muted text-center">Could not load sales data.</p>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Recent Transfers</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php
                                        try {
                                            $database = new Database();
                                            $db = $database->getConnection();
                                            
                                            $query = "
                                                SELECT t.*,
                                                       CASE WHEN t.item_type = 'phone' THEN 
                                                           (SELECT CONCAT(brand, ' ', model) FROM phones WHERE phone_id = t.item_id)
                                                       ELSE 
                                                           (SELECT accessory_name FROM accessories WHERE accessory_id = t.item_id)
                                                       END as item_name
                                                FROM transfers t
                                                ORDER BY t.transfer_date DESC
                                                LIMIT 10
                                            ";
                                            
                                            $stmt = $db->prepare($query);
                                            $stmt->execute();
                                            $recent_transfers = $stmt->fetchAll();
                                            
                                            if (count($recent_transfers) > 0): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm">
                                                        <thead>
                                                            <tr>
                                                                <th>Item</th>
                                                                <th>To Shop</th>
                                                                <th>Date</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach($recent_transfers as $transfer): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($transfer['item_name']); ?></td>
                                                                <td>Shop <?php echo $transfer['destination_shop_id']; ?></td>
                                                                <td><?php echo date('M d, H:i', strtotime($transfer['transfer_date'])); ?></td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <p class="text-muted text-center">No transfers recorded yet.</p>
                                            <?php endif;
                                        } catch(Exception $e) {
                                            echo '<p class="text-muted text-center">Could not load transfer data.</p>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Settings Section -->
    <div id="settings" class="section" style="display: none;">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-cog me-2"></i>Account Settings</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8 mx-auto">
                        <h6 class="mb-3"><i class="fas fa-key me-2"></i>Change Password</h6>
                        <p class="text-muted small mb-4">Update your account password. Make sure to use a strong password with at least 8 characters, including uppercase, lowercase, and numbers.</p>
                        
                        <iframe src="change_password.php" style="width: 100%; height: 600px; border: none; border-radius: 8px;"></iframe>
                        
                        <div class="alert alert-info mt-4">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Security Tips:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Use a unique password that you don't use elsewhere</li>
                                <li>Never share your password with anyone</li>
                                <li>Change your password regularly</li>
                                <li>Log out when using shared computers</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Barcode Scanner Modal (IMEI SCANNER) -->
    <div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="barcodeScannerModalLabel">
                        <i class="fas fa-barcode me-2"></i>IMEI Barcode Scanner
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="barcode-scanner-container" class="mb-3" style="height: 300px;">
                        <div class="viewport" style="height: 100%; position: relative;">
                            <video id="barcode-video" muted playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>
                            <div class="scanner-frame" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 80%; height: 100px; border: 3px solid var(--accent-color); border-radius: 5px; box-shadow: 0 0 0 1000px rgba(0,0,0,0.5);"></div>
                        </div>
                    </div>
                    
                    <div id="barcode-result" class="mt-3">
                        <div class="alert alert-info" id="result">
                            <i class="fas fa-info-circle me-2"></i>
                            <span id="result-text">Point camera at a barcode/IMEI to scan...</span>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="input-group mb-3">
                                <input type="text" class="form-control" id="manual-barcode" placeholder="Or enter IMEI manually">
                                <button class="btn btn-primary" type="button" onclick="useManualBarcode()">
                                    <i class="fas fa-keyboard"></i> Use
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2 justify-content-end">
                                <button class="btn btn-success" id="start-scan-btn" onclick="startBarcodeScanner()">
                                    <i class="fas fa-play me-2"></i>Start Scan
                                </button>
                                <button class="btn btn-danger" id="stop-scan-btn" onclick="stopBarcodeScanner()" style="display: none;">
                                    <i class="fas fa-stop me-2"></i>Stop Scan
                                </button>
                                <button class="btn btn-warning" onclick="resetBarcodeScanner()">
                                    <i class="fas fa-redo me-2"></i>Reset
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Close
                    </button>
                    <button type="button" class="btn btn-primary" id="use-barcode-btn" style="display: none;" onclick="useScannedBarcode()">
                        <i class="fas fa-check me-2"></i>Use This Code
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ========== MOBILE SIDEBAR TOGGLE ==========
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        }
        
        // ========== BARCODE SCANNER VARIABLES (IMEI SCANNER) ==========
        let codeReader = null;
        let videoElement = null;
        let stream = null;
        let isScanning = false;
        let lastScannedCode = '';
        let targetField = 'imei'; // Default target field for IMEI scanner
        
        // ========== SECTION NAVIGATION - FIXED: Now properly scrolls to section ==========
        function showSection(sectionId, scrollToTop = true) {
            // Hide all sections
            document.querySelectorAll('.section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Show selected section
            const section = document.getElementById(sectionId);
            if (section) {
                section.style.display = 'block';
            }
            
            // Update active nav link
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            
            // Find and activate corresponding nav link
            const navLinks = {
                'dashboard': document.querySelector('a[onclick*="dashboard"]'),
                'addPhone': document.querySelector('a[onclick*="addPhone"]'),
                'addAccessory': document.querySelector('a[onclick*="addAccessory"]'),
                'sellItem': document.querySelector('a[onclick*="sellItem"]'),
                'transferItem': document.querySelector('a[onclick*="transferItem"]'),
                'viewStock': document.querySelector('a[onclick*="viewStock"]'),
                'manageUsers': document.querySelector('a[onclick*="manageUsers"]'),
                'stockAnalysis': document.querySelector('a[onclick*="stockAnalysis"]'),
                'salesAnalysis': document.querySelector('a[onclick*="salesAnalysis"]'),
                'myActivity': document.querySelector('a[onclick*="myActivity"]'),
                'reports': document.querySelector('a[onclick*="reports"]')
            };
            
            if (navLinks[sectionId]) {
                navLinks[sectionId].classList.add('active');
            }
            
            // FIXED: Scroll to actual section position instead of top of page
            if (scrollToTop && section) {
                // Get section's position
                const sectionRect = section.getBoundingClientRect();
                const offsetTop = window.pageYOffset + sectionRect.top;
                
                // Calculate scroll position with offset for navbar (70px for mobile, 0 for desktop)
                const navbarOffset = window.innerWidth <= 992 ? 70 : 0;
                
                // Scroll to section
                window.scrollTo({
                    top: offsetTop - navbarOffset - 20,
                    behavior: 'smooth'
                });
            }
            
            // Initialize Bootstrap tabs if showing sellItem section
            if (sectionId === 'sellItem') {
                const sellTab = new bootstrap.Tab(document.getElementById('sell-phone-tab'));
                sellTab.show();
            }
            
            // Close sidebar on mobile after navigation
            if (window.innerWidth <= 992) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.querySelector('.sidebar-overlay');
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            }
        }
        
        // ========== PAGE INITIALIZATION ==========
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const hasSearch = urlParams.has('search');
            
            if (hasSearch) {
                showSection('viewStock', false);
            } else {
                showSection('dashboard');
            }
            
            // Clear success messages after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
            
            // Scanner modal events
            const scannerModal = document.getElementById('barcodeScannerModal');
            if (scannerModal) {
                scannerModal.addEventListener('shown.bs.modal', function() {
                    setTimeout(startBarcodeScanner, 500);
                });
                
                scannerModal.addEventListener('hidden.bs.modal', function() {
                    resetBarcodeScanner();
                });
            }
            
            // Initialize transfer form
            toggleTransferFields();
            
            // Initialize accessory price calculation
            calculateTotalAccessoryPrice();
        });
        
        // ========== FORM VALIDATION FUNCTIONS ==========
        function validatePhoneForm() {
            const imei = document.getElementById('imei').value;
            const brand = document.getElementById('brand').value;
            const model = document.getElementById('model').value;
            
            if (!brand || !model || !imei) {
                alert('Please fill in all required fields (Brand, Model, IMEI)');
                return false;
            }
            
            if (imei.length !== 15 || !/^\d+$/.test(imei)) {
                alert('IMEI must be exactly 15 digits');
                return false;
            }
            
            return true;
        }
        
        function validateAccessoryForm() {
            const name = document.getElementById('accessory_name').value;
            const category = document.getElementById('category').value;
            const quantity = document.getElementById('quantity').value;
            
            if (!name || !category || !quantity) {
                alert('Please fill in all required fields');
                return false;
            }
            
            if (quantity < 1) {
                alert('Quantity must be at least 1');
                return false;
            }
            
            return true;
        }
        
        function validateSellPhoneForm() {
            const phoneId = document.getElementById('sell_phone_id').value;
            const salePrice = document.getElementById('sale_price').value;
            
            if (!phoneId) {
                alert('Please select a phone to sell');
                return false;
            }
            
            if (!salePrice || salePrice <= 0) {
                alert('Please enter a valid sale price');
                return false;
            }
            
            return confirm('Are you sure you want to complete this sale? This action cannot be undone.');
        }
        
        function validateSellAccessoryForm() {
            const accessoryId = document.getElementById('sell_accessory_id').value;
            const quantity = document.getElementById('sale_quantity').value;
            const salePrice = document.getElementById('accessory_sale_price').value;
            const selectedOption = document.getElementById('sell_accessory_id').selectedOptions[0];
            const maxQuantity = selectedOption ? parseInt(selectedOption.getAttribute('data-quantity')) : 0;
            
            if (!accessoryId) {
                alert('Please select an accessory to sell');
                return false;
            }
            
            if (!quantity || quantity < 1) {
                alert('Please enter a valid quantity');
                return false;
            }
            
            if (quantity > maxQuantity) {
                alert(`Not enough stock! Only ${maxQuantity} available.`);
                return false;
            }
            
            if (!salePrice || salePrice <= 0) {
                alert('Please enter a valid sale price per unit');
                return false;
            }
            
            const totalPrice = quantity * salePrice;
            return confirm(`Are you sure you want to sell ${quantity} item(s) for XAF ${totalPrice.toFixed(2)} total? This action cannot be undone.`);
        }
        
        function validateTransferForm() {
            const itemType = document.getElementById('item_type').value;
            let itemId, quantity;
            
            if (!itemType) {
                alert('Please select item type');
                return false;
            }
            
            if (itemType === 'phone') {
                itemId = document.getElementById('transfer_phone_id').value;
                if (!itemId) {
                    alert('Please select a phone to transfer');
                    return false;
                }
            } else if (itemType === 'accessory') {
                itemId = document.getElementById('transfer_accessory_id').value;
                quantity = document.getElementById('transfer_quantity').value;
                const selectedOption = document.getElementById('transfer_accessory_id').selectedOptions[0];
                const maxQuantity = selectedOption ? parseInt(selectedOption.getAttribute('data-quantity')) : 0;
                
                if (!itemId) {
                    alert('Please select an accessory to transfer');
                    return false;
                }
                
                if (!quantity || quantity < 1) {
                    alert('Please enter a valid quantity');
                    return false;
                }
                
                if (quantity > maxQuantity) {
                    alert(`Not enough stock! Only ${maxQuantity} available.`);
                    return false;
                }
            }
            
            const destinationShop = document.querySelector('select[name="destination_shop"]').value;
            if (!destinationShop) {
                alert('Please select a destination shop');
                return false;
            }
            
            return confirm('Are you sure you want to transfer this item? This action cannot be undone.');
        }
        
        // ========== TRANSFER FORM FUNCTIONS ==========
        function toggleTransferFields() {
            const itemType = document.getElementById('item_type').value;
            
            document.getElementById('phone_select_field').style.display = 'none';
            document.getElementById('accessory_select_field').style.display = 'none';
            document.getElementById('quantity_field').style.display = 'none';
            
            document.getElementById('transfer_phone_id').required = false;
            document.getElementById('transfer_accessory_id').required = false;
            document.getElementById('transfer_quantity').required = false;
            
            if (itemType === 'phone') {
                document.getElementById('phone_select_field').style.display = 'block';
                document.getElementById('transfer_phone_id').required = true;
            } else if (itemType === 'accessory') {
                document.getElementById('accessory_select_field').style.display = 'block';
                document.getElementById('quantity_field').style.display = 'block';
                document.getElementById('transfer_accessory_id').required = true;
                document.getElementById('transfer_quantity').required = true;
                updateTransferQuantity();
            }
        }
        
        function updateTransferQuantity() {
            const selectedOption = document.getElementById('transfer_accessory_id').selectedOptions[0];
            if (selectedOption) {
                const maxQuantity = parseInt(selectedOption.getAttribute('data-quantity'));
                document.getElementById('transfer_quantity').max = maxQuantity;
                document.getElementById('transfer_quantity').value = Math.min(1, maxQuantity);
                if (maxQuantity < 1) {
                    alert('Selected accessory has no stock available!');
                }
            }
        }
        
        function resetTransferForm() {
            document.getElementById('item_type').value = '';
            toggleTransferFields();
        }
        
        // ========== ACTIVITY FILTERING ==========
        function filterActivities() {
            const filterType = document.getElementById('activityFilter').value;
            const filterPeriod = document.getElementById('activityPeriod').value;
            const now = new Date();
            
            document.querySelectorAll('.activity-item').forEach(item => {
                const itemType = item.getAttribute('data-type');
                const itemDate = new Date(item.getAttribute('data-date'));
                
                let typeMatch = false;
                if (filterType === 'all') {
                    typeMatch = true;
                } else if (filterType === 'add' && itemType === 'add') {
                    typeMatch = true;
                } else if (filterType === 'sell' && itemType === 'sale') {
                    typeMatch = true;
                } else if (filterType === 'transfer' && itemType === 'transfer') {
                    typeMatch = true;
                } else if (filterType === 'system' && itemType === 'admin') {
                    typeMatch = true;
                }
                
                let periodMatch = false;
                if (filterPeriod === 'all') {
                    periodMatch = true;
                } else if (filterPeriod === 'today') {
                    periodMatch = itemDate.toDateString() === now.toDateString();
                } else if (filterPeriod === 'week') {
                    const oneWeekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
                    periodMatch = itemDate >= oneWeekAgo;
                } else if (filterPeriod === 'month') {
                    const oneMonthAgo = new Date(now.getFullYear(), now.getMonth() - 1, now.getDate());
                    periodMatch = itemDate >= oneMonthAgo;
                }
                
                if (typeMatch && periodMatch) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }
        
        // ========== SALE FORM FUNCTIONS ==========
        function updatePhoneSalePrice() {
            const select = document.getElementById('sell_phone_id');
            const selectedOption = select.selectedOptions[0];
            if (selectedOption && selectedOption.getAttribute('data-price')) {
                document.getElementById('sale_price').value = selectedOption.getAttribute('data-price');
            }
        }
        
        function updateAccessorySalePrice() {
            const select = document.getElementById('sell_accessory_id');
            const selectedOption = select.selectedOptions[0];
            if (selectedOption && selectedOption.getAttribute('data-price')) {
                document.getElementById('accessory_sale_price').value = selectedOption.getAttribute('data-price');
                calculateTotalAccessoryPrice();
            }
        }
        
        function calculateTotalAccessoryPrice() {
            const quantity = document.getElementById('sale_quantity').value;
            const pricePerUnit = document.getElementById('accessory_sale_price').value;
            
            if (quantity && pricePerUnit) {
                const totalPrice = parseFloat(quantity) * parseFloat(pricePerUnit);
                document.getElementById('total_accessory_price').textContent = totalPrice.toFixed(2);
            } else {
                document.getElementById('total_accessory_price').textContent = '0.00';
            }
        }
        
        // ========== BARCODE SCANNER FUNCTIONS (IMEI) - FIXED: Proper initialization ==========
        function showBarcodeScanner(field = 'imei') {
            targetField = field;
            const modal = new bootstrap.Modal(document.getElementById('barcodeScannerModal'));
            modal.show();
            resetBarcodeScanner();
        }
        
        async function startBarcodeScanner() {
            if (isScanning) return;
            
            // Check for HTTPS
            if (location.protocol === 'http:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                alert('Warning: Camera access requires HTTPS or localhost. Please use HTTPS to access this site on mobile.');
            }
            
            videoElement = document.getElementById('barcode-video');
            const resultText = document.getElementById('result-text');
            const resultDiv = document.getElementById('result');
            const startBtn = document.getElementById('start-scan-btn');
            const stopBtn = document.getElementById('stop-scan-btn');
            const useBtn = document.getElementById('use-barcode-btn');
            
            try {
                // Request camera access
                stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        facingMode: 'environment', // Use back camera on mobile
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    } 
                });
                
                // Set video stream
                videoElement.srcObject = stream;
                
                // IMPORTANT: Play the video explicitly
                await videoElement.play();
                
                // Wait for video to be ready before scanning
                await new Promise((resolve) => {
                    videoElement.onloadedmetadata = resolve;
                    setTimeout(() => {
                        if (videoElement.readyState >= 1) resolve();
                    }, 1000);
                });
                
                // Create ZXing reader
                codeReader = new ZXing.BrowserMultiFormatReader();
                
                // Set scan hints for better performance
                const hints = new Map();
                hints.set(ZXing.DecodeHintType.POSSIBLE_FORMATS, [
                    ZXing.BarcodeFormat.EAN_13,
                    ZXing.BarcodeFormat.EAN_8,
                    ZXing.BarcodeFormat.UPC_A,
                    ZXing.BarcodeFormat.CODE_128,
                    ZXing.BarcodeFormat.CODE_39,
                    ZXing.BarcodeFormat.ITF,
                    ZXing.BarcodeFormat.QR_CODE,
                    ZXing.BarcodeFormat.DATA_MATRIX,
                    ZXing.BarcodeFormat.AZTEC,
                    ZXing.BarcodeFormat.CODABAR,
                    ZXing.BarcodeFormat.PDF_417
                ]);
                hints.set(ZXing.DecodeHintType.TRY_HARDER, true);
                
                codeReader.decodeFromVideoElement(videoElement, (result, err) => {
                    if (result) {
                        const code = result.text;
                        console.log('Scanned:', code); // Debug
                        
                        // Extract only digits from scanned result
                        const digitsOnly = code.replace(/\D/g, '');
                        
                        // Validate IMEI (14-17 digits to cover IMEI+SV)
                        if (digitsOnly.length >= 14 && digitsOnly.length <= 17) {
                            lastScannedCode = digitsOnly;
                            resultText.textContent = `✅ IMEI Scanned: ${lastScannedCode}`;
                            resultDiv.className = 'alert alert-success';
                            
                            // Haptic feedback
                            if (navigator.vibrate) {
                                navigator.vibrate(200);
                            }
                            
                            // Stop scanner
                            stopBarcodeScanner();
                            
                            // Show use button
                            useBtn.style.display = 'block';
                            
                            // Auto-use after 1.0 second
                            setTimeout(() => {
                                if (lastScannedCode) {
                                    useScannedBarcode();
                                }
                            }, 1000);
                        } else {
                            // Feedback for invalid length
                            resultText.textContent = `⚠️ Scanned: ${code} (Invalid IMEI length)`;
                            resultDiv.className = 'alert alert-warning';
                            // Don't stop scanning, let them try again
                        }
                    }
                    
                    // Only log real errors
                    if (err && !(err instanceof ZXing.NotFoundException)) {
                        console.error('Scan error:', err);
                    }
                });
                
                isScanning = true;
                startBtn.style.display = 'none';
                stopBtn.style.display = 'block';
                useBtn.style.display = 'none';
                resultText.textContent = 'Scanning... Point camera at IMEI barcode';
                resultDiv.className = 'alert alert-warning';
                
            } catch (err) {
                console.error('Camera access error:', err);
                
                let errorMsg = 'Camera access error: ';
                
                if (err.name === 'NotAllowedError') {
                    errorMsg += 'Camera permission denied. Please allow camera access in your browser settings.';
                } else if (err.name === 'NotFoundError') {
                    errorMsg += 'No camera found on this device.';
                } else if (err.name === 'NotReadableError') {
                    errorMsg += 'Camera is already in use by another application.';
                } else {
                    errorMsg += err.message || 'Unknown error occurred.';
                }
                
                resultText.textContent = errorMsg;
                resultDiv.className = 'alert alert-danger';
                isScanning = false;
                startBtn.style.display = 'block';
                stopBtn.style.display = 'none';
                
                // Focus on manual input as fallback
                document.getElementById('manual-barcode').focus();
            }
        }
        
        function stopBarcodeScanner() {
            // Stop ZXing reader
            if (codeReader) {
                try {
                    codeReader.reset();
                    codeReader = null;
                } catch (e) {
                    console.error('Error stopping ZXing reader:', e);
                }
            }
            
            // Stop video stream
            if (stream) {
                stream.getTracks().forEach(track => {
                    try {
                        track.stop();
                    } catch (e) {
                        console.error('Error stopping track:', e);
                    }
                });
                stream = null;
            }
            
            // Clear video source
            if (videoElement) {
                videoElement.srcObject = null;
            }
            
            isScanning = false;
            document.getElementById('start-scan-btn').style.display = 'block';
            document.getElementById('stop-scan-btn').style.display = 'none';
        }
        
        function resetBarcodeScanner() {
            stopBarcodeScanner();
            lastScannedCode = '';
            document.getElementById('result-text').textContent = 'Point camera at a barcode/IMEI to scan...';
            document.getElementById('result').className = 'alert alert-info';
            document.getElementById('use-barcode-btn').style.display = 'none';
            document.getElementById('manual-barcode').value = '';
        }
        
        function useScannedBarcode() {
            if (lastScannedCode) {
                console.log('Using scanned code:', lastScannedCode, 'for field:', targetField);
                
                if (targetField === 'imei') {
                    const imeiField = document.getElementById('imei');
                    console.log('IMEI field found:', imeiField);
                    
                    if (imeiField) {
                        imeiField.value = lastScannedCode;
                        console.log('IMEI value set to:', imeiField.value);
                        
                        // Trigger multiple events for better compatibility
                        imeiField.dispatchEvent(new Event('input', { bubbles: true }));
                        imeiField.dispatchEvent(new Event('change', { bubbles: true }));
                        
                        // Visual feedback
                        imeiField.style.backgroundColor = '#d1fae5';
                        setTimeout(() => {
                            imeiField.style.backgroundColor = '';
                        }, 1000);
                        
                        // Auto-focus next field after modal closes
                        setTimeout(() => {
                            const colorField = document.getElementById('color');
                            if (colorField) {
                                colorField.focus();
                            }
                        }, 500);
                    } else {
                        console.error('IMEI field not found!');
                    }
                }
                
                const modalElement = document.getElementById('barcodeScannerModal');
                if (modalElement) {
                    const modalInstance = bootstrap.Modal.getInstance(modalElement);
                    if (modalInstance) {
                        modalInstance.hide();
                    }
                }
                resetBarcodeScanner();
            }
        }
        
        function useManualBarcode() {
            const manualCode = document.getElementById('manual-barcode').value.trim();
            if (manualCode) {
                lastScannedCode = manualCode;
                useScannedBarcode();
            } else {
                alert('Please enter an IMEI code');
            }
        }
        
        // ========== EDIT USER FUNCTION (placeholder) ==========
        function editUser(userId) {
            alert('Edit user functionality coming soon for user ID: ' + userId);
        }

        function showAddUserModal() {
            const form = document.getElementById('addUserForm');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                // Scroll to form
                form.scrollIntoView({behavior: 'smooth'});
            } else {
                form.style.display = 'none';
            }
        }
        
        // ========== AUTO-REFRESH STATS ==========
        setInterval(() => {
            if (document.getElementById('dashboard').style.display !== 'none') {
                location.reload();
            }
        }, 60000);
        
        // ========== KEYBOARD SHORTCUTS ==========
        document.addEventListener('keydown', function(e) {
            // Ctrl+1: Dashboard
            if (e.ctrlKey && e.key === '1') {
                e.preventDefault();
                showSection('dashboard');
            }
            // Ctrl+2: Add Phone
            if (e.ctrlKey && e.key === '2') {
                e.preventDefault();
                showSection('addPhone');
            }
            // Ctrl+3: Add Accessory
            if (e.ctrlKey && e.key === '3') {
                e.preventDefault();
                showSection('addAccessory');
            }
            // Ctrl+S: Sell Item
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                showSection('sellItem');
            }
            // Ctrl+T: Transfer Item
            if (e.ctrlKey && e.key === 't') {
                e.preventDefault();
                showSection('transferItem');
            }
            // Ctrl+R: Reports
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                showSection('reports');
            }
            // Ctrl+B: Barcode Scanner
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                showBarcodeScanner();
            }
            // Escape: Close modals
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal.show');
                if (modals.length > 0) {
                    bootstrap.Modal.getInstance(modals[0]).hide();
                }
            }
        });
    </script>
</body>
</html>
