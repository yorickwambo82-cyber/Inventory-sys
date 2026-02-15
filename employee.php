<?php
// Strong cache control
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// Check maintenance mode
require_once 'includes/maintenance.php';

// Initialize session with database handler
require_once 'includes/session.php';

// Force session regeneration - Disabled for Vercel compatibility
// session_regenerate_id(true);

// Include database connection
require_once 'config/database.php';
require_once 'includes/logActivity.php';

// Check if user is logged in as employee
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'employee') {
    // Log logout activity if user_id exists
    if (isset($_SESSION['user_id'])) {
        try {
            logActivity($_SESSION['user_id'], 'logout', 'Session timeout or invalid access');
        } catch (Exception $e) {
            // Silently fail if logging doesn't work
            error_log('Logout logging failed: ' . $e->getMessage());
        }
    }
    session_destroy();
    header("Location: login.php?logout_success=1&nocache=" . time());
    exit();
}

// Handle logout - FIXED: Log activity BEFORE destroying session
if (isset($_GET['logout'])) {
    // Save user_id before destroying session
    $user_id_for_logout = $_SESSION['user_id'] ?? null;
    
    // Log activity FIRST before destroying session
    if ($user_id_for_logout) {
        try {
            logActivity($user_id_for_logout, 'logout', 'Employee logged out manually');
        } catch (Exception $e) {
            // Silently fail if logging doesn't work
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

// Get all shops for transfer dropdown
$all_shops = [];
try {
    $database = new Database();
    $db = $database->getConnection();
    $query = "SELECT shop_id, shop_name FROM shops WHERE status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $all_shops = $stmt->fetchAll();
} catch(Exception $e) {
    // Create default shops if table doesn't exist
    $all_shops = [
        ['shop_id' => 2, 'shop_name' => 'Shop 2'],
        ['shop_id' => 3, 'shop_name' => 'Shop 3']
    ];
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
        $buying_price = 0; // Set to 0 for employees
        $selling_price = floatval($_POST['selling_price'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if (!empty($brand) && !empty($model) && !empty($imei)) {
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                // Check if IMEI already exists
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
                    
                    $phone_id = $db->lastInsertId();
                    
                    // Log activity
                    logActivity($user_id, 'add_phone', "Added phone: $brand $model ($imei)");
                    
                    $success = "Phone added successfully!";
                    
                    header("Location: employee.php?success=phone_added&nocache=" . time());
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
        $buying_price = 0; // Set to 0 for employees
        $selling_price = floatval($_POST['accessory_selling_price'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 1);
        
        if (!empty($accessory_name) && !empty($category)) {
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                // Check if accessory already exists in stock
                $checkQuery = "SELECT accessory_id FROM accessories WHERE accessory_name = :name AND category = :category AND status = 'in_stock'";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->bindParam(':name', $accessory_name);
                $checkStmt->bindParam(':category', $category);
                $checkStmt->execute();
                
                $existing = $checkStmt->fetch();
                if ($existing) {
                    // Update quantity if accessory exists
                    $updateQuery = "UPDATE accessories SET quantity = quantity + :quantity WHERE accessory_id = :id";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':quantity', $quantity);
                    $updateStmt->bindParam(':id', $existing['accessory_id']);
                    $updateStmt->execute();
                    
                    logActivity($user_id, 'update_accessory', "Updated quantity for accessory: $accessory_name (+$quantity)");
                } else {
                    // Insert new accessory
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
                
                header("Location: employee.php?success=accessory_added&nocache=" . time());
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
                
                // Get phone details before selling
                $query = "SELECT * FROM phones WHERE phone_id = :phone_id AND status = 'in_stock'";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':phone_id', $phone_id);
                $stmt->execute();
                $phone = $stmt->fetch();
                
                if ($phone) {
                    // Start transaction
                    $db->beginTransaction();
                    
                    // Insert sale record
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
                    
                    // Update phone status to sold
                    $query = "UPDATE phones SET status = 'sold', sold_price = :sale_price, sold_at = NOW() WHERE phone_id = :phone_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':phone_id', $phone_id);
                    $stmt->bindParam(':sale_price', $sale_price);
                    $stmt->execute();
                    
                    // Commit transaction
                    $db->commit();
                    
                    // Log activity
                    logActivity($user_id, 'sell_phone', "Sold phone: {$phone['brand']} {$phone['model']} for XAF " . number_format($sale_price, 2));
                    
                    $success = "Phone sold successfully!";
                    header("Location: employee.php?success=phone_sold&nocache=" . time());
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
    
    // Sell accessory - FIXED: Calculate total price based on quantity
    if (isset($_POST['sell_accessory'])) {
        $accessory_id = intval($_POST['accessory_id'] ?? 0);
        $sale_quantity = intval($_POST['sale_quantity'] ?? 1);
        $sale_price_per_unit = floatval($_POST['sale_price'] ?? 0); // Price per unit
        $customer_name = trim($_POST['customer_name'] ?? '');
        $customer_phone = trim($_POST['customer_phone'] ?? '');
        $payment_method = trim($_POST['payment_method'] ?? 'cash');
        $notes = trim($_POST['sale_notes'] ?? '');
        
        if (!empty($accessory_id) && $sale_quantity > 0 && $sale_price_per_unit > 0) {
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                // Get accessory details before selling
                $query = "SELECT * FROM accessories WHERE accessory_id = :accessory_id AND status = 'in_stock'";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':accessory_id', $accessory_id);
                $stmt->execute();
                $accessory = $stmt->fetch();
                
                if ($accessory && $accessory['quantity'] >= $sale_quantity) {
                    // Calculate total price
                    $total_sale_price = $sale_price_per_unit * $sale_quantity;
                    
                    // Start transaction
                    $db->beginTransaction();
                    
                    // Insert sale record - store total price
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
                    
                    // Update accessory quantity
                    $new_quantity = $accessory['quantity'] - $sale_quantity;
                    $new_status = ($new_quantity <= 0) ? 'out_of_stock' : 'in_stock';
                    
                    $query = "UPDATE accessories SET quantity = :quantity, status = :status WHERE accessory_id = :accessory_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':quantity', $new_quantity);
                    $stmt->bindParam(':status', $new_status);
                    $stmt->bindParam(':accessory_id', $accessory_id);
                    $stmt->execute();
                    
                    // Commit transaction
                    $db->commit();
                    
                    // Log activity - show total price
                    logActivity($user_id, 'sell_accessory', "Sold accessory: {$accessory['accessory_name']} x$sale_quantity for XAF " . number_format($total_sale_price, 2) . " (XAF " . number_format($sale_price_per_unit, 2) . " each)");
                    
                    $success = "Accessory sold successfully! Total: XAF " . number_format($total_sale_price, 2);
                    header("Location: employee.php?success=accessory_sold&nocache=" . time());
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
    
    // Transfer item between shops - FIXED: Use correct field names and toggle function
    if (isset($_POST['transfer_item'])) {
        $item_type = trim($_POST['item_type'] ?? '');
        
        // Get item_id based on item_type
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
                    // Get phone details
                    $query = "SELECT * FROM phones WHERE phone_id = :item_id AND status = 'in_stock'";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->execute();
                    $item = $stmt->fetch();
                    
                    if ($item) {
                        // Start transaction
                        $db->beginTransaction();
                        
                        // Insert transfer record
                        $query = "INSERT INTO transfers (item_id, item_type, quantity, source_shop_id, destination_shop_id, transferred_by, notes, transfer_date) 
                                  VALUES (:item_id, :item_type, 1, 1, :destination_shop, :transferred_by, :notes, NOW())";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->bindParam(':item_type', $item_type);
                        $stmt->bindParam(':destination_shop', $destination_shop);
                        $stmt->bindParam(':transferred_by', $user_id);
                        $stmt->bindParam(':notes', $notes);
                        $stmt->execute();
                        
                        // Update phone status to transferred
                        $query = "UPDATE phones SET status = 'transferred', current_shop_id = :destination_shop WHERE phone_id = :item_id";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->bindParam(':destination_shop', $destination_shop);
                        $stmt->execute();
                        
                        // Commit transaction
                        $db->commit();
                        
                        // Log activity
                        logActivity($user_id, 'transfer_phone', "Transferred phone: {$item['brand']} {$item['model']} to Shop $destination_shop");
                        
                        // SUCCESS - Redirect to show updated stats
                        header("Location: employee.php?success=phone_transferred&refresh_stats=1&nocache=" . time());
                        exit();
                    } else {
                        $error = "Phone not found or not in stock!";
                    }
                } else if ($item_type === 'accessory') {
                    // Get accessory details
                    $query = "SELECT * FROM accessories WHERE accessory_id = :item_id AND status = 'in_stock'";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->execute();
                    $item = $stmt->fetch();
                    
                    if ($item && $item['quantity'] >= $quantity) {
                        // Start transaction
                        $db->beginTransaction();
                        
                        // Insert transfer record
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
                        
                        // Update accessory quantity
                        $new_quantity = $item['quantity'] - $quantity;
                        $new_status = ($new_quantity <= 0) ? 'out_of_stock' : 'in_stock';
                        
                        $query = "UPDATE accessories SET quantity = :quantity, status = :status WHERE accessory_id = :item_id";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':quantity', $new_quantity);
                        $stmt->bindParam(':status', $new_status);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->execute();
                        
                        // Commit transaction
                        $db->commit();
                        
                        // Log activity
                        logActivity($user_id, 'transfer_accessory', "Transferred accessory: {$item['accessory_name']} x$quantity to Shop $destination_shop");
                        
                        // SUCCESS - Redirect to show updated stats
                        header("Location: employee.php?success=accessory_transferred&refresh_stats=1&nocache=" . time());
                        exit();
                    } else {
                        $error = "Accessory not found or insufficient stock!";
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
}

// Check for success messages from redirect
if (isset($_GET['success'])) {
    $success_messages = [
        'phone_added' => "Phone added successfully!",
        'accessory_added' => "Accessory added successfully!",
        'phone_sold' => "Phone sold successfully!",
        'accessory_sold' => "Accessory sold successfully!",
        'phone_transferred' => "Phone transferred successfully! Stock updated.",
        'accessory_transferred' => "Accessory transferred successfully! Stock updated.",
        'phone_unavailable' => "Phone marked as unavailable!",
        'accessory_unavailable' => "Accessory marked as unavailable!"
    ];
    
    if (isset($success_messages[$_GET['success']])) {
        $success = $success_messages[$_GET['success']];
    }
    
    // Force refresh of statistics if we just transferred an item
    if (isset($_GET['refresh_stats']) && ($_GET['success'] === 'phone_transferred' || $_GET['success'] === 'accessory_transferred')) {
        // Clear any cached data
        unset($total_phones, $total_accessories, $all_phones, $all_accessories);
    }
}

// Handle delete operations - MARK AS UNAVAILABLE instead of deleting
if (isset($_GET['delete_phone'])) {
    $phone_id = intval($_GET['delete_phone']);
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Get phone info before updating for logging
        $query = "SELECT * FROM phones WHERE phone_id = :phone_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':phone_id', $phone_id);
        $stmt->execute();
        $phone = $stmt->fetch();
        
        if ($phone) {
            // Mark as unavailable instead of deleting
            $updateQuery = "UPDATE phones SET status = 'unavailable' WHERE phone_id = :phone_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':phone_id', $phone_id);
            $updateStmt->execute();
            
            logActivity($user_id, 'mark_unavailable_phone', "Marked phone as unavailable: {$phone['brand']} {$phone['model']} ({$phone['iemi_number']})");
            
            $success = "Phone marked as unavailable!";
            header("Location: employee.php?success=phone_unavailable&nocache=" . time());
            exit();
        }
    } catch(Exception $e) {
        $error = "Error marking phone as unavailable: " . $e->getMessage();
    }
}

if (isset($_GET['delete_accessory'])) {
    $accessory_id = intval($_GET['delete_accessory']);
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
            $updateStmt->execute();
            
            logActivity($user_id, 'mark_unavailable_accessory', "Marked accessory as unavailable: {$accessory['accessory_name']}");
            
            $success = "Accessory marked as unavailable!";
            header("Location: employee.php?success=accessory_unavailable&nocache=" . time());
            exit();
        }
    } catch(Exception $e) {
        $error = "Error marking accessory as unavailable: " . $e->getMessage();
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
    
    // Count phones added today by this employee
    $query = "SELECT COUNT(*) as today_count FROM phones 
              WHERE DATE(created_at) = CURDATE() AND registered_by = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $today_stats = $stmt->fetch();
    $today_count = $today_stats['today_count'] ?? 0;
    
    // Count total phones in stock
    $query = "SELECT COUNT(*) as total_phones FROM phones WHERE status = 'in_stock'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $total_phones = $stmt->fetch()['total_phones'] ?? 0;
    
    // Count total accessories in stock
    $query = "SELECT SUM(quantity) as total_accessories FROM accessories WHERE status = 'in_stock'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $total_accessories = $stmt->fetch()['total_accessories'] ?? 0;
    
    // Count low stock accessories (less than 5)
    $query = "SELECT COUNT(*) as low_stock FROM accessories WHERE quantity < 5 AND status = 'in_stock'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $low_stock = $stmt->fetch()['low_stock'] ?? 0;
    
    // Get today's sales count
    $query = "SELECT COUNT(*) as today_sales FROM sales 
              WHERE DATE(sale_date) = CURDATE() AND sold_by = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $today_sales = $stmt->fetch()['today_sales'] ?? 0;
    
    // Get today's sales total
    $query = "SELECT COALESCE(SUM(sale_price), 0) as today_revenue FROM sales 
              WHERE DATE(sale_date) = CURDATE() AND sold_by = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $today_revenue = $stmt->fetch()['today_revenue'] ?? 0;
    
    // Get recent phones added by this employee
    $query = "SELECT * FROM phones WHERE registered_by = :user_id ORDER BY created_at DESC LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $recent_phones = $stmt->fetchAll();
    
    // Get all accessories
    $query = "SELECT * FROM accessories ORDER BY created_at DESC LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $recent_accessories = $stmt->fetchAll();
    
    // Get all phones for view stock section - only available ones
    // EXCLUDE transferred phones
    $query = "SELECT * FROM phones WHERE status = 'in_stock' ORDER BY created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $all_phones = $stmt->fetchAll();
    
    // Get all accessories for view stock section - only available ones
    // EXCLUDE transferred accessories (they have status changed or quantity reduced)
    $query = "SELECT * FROM accessories WHERE status = 'in_stock' AND quantity > 0 ORDER BY created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $all_accessories = $stmt->fetchAll();
    
    // Auto-mark accessories with 0 quantity as out_of_stock
    $query = "UPDATE accessories SET status = 'out_of_stock' WHERE quantity = 0 AND status = 'in_stock'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    
} catch(Exception $e) {
    // If database error, set defaults
    $today_count = 0;
    $total_phones = 0;
    $total_accessories = 0;
    $low_stock = 0;
    $today_sales = 0;
    $today_revenue = 0;
    $recent_phones = [];
    $recent_accessories = [];
    $all_phones = [];
    $all_accessories = [];
}

// Get recent activities including sales and transfers
$recent_activities = [];
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get combined recent activities (sales, transfers, activity log)
    $query = "
        (SELECT 'sale' as type, sale_date as date_time, 
                CONCAT('Sold ', 
                    CASE WHEN item_type = 'phone' THEN 
                        (SELECT CONCAT(brand, ' ', model) FROM phones WHERE phone_id = s.item_id)
                    ELSE 
                        (SELECT accessory_name FROM accessories WHERE accessory_id = s.item_id)
                    END,
                    ' - XAF ', FORMAT(sale_price, 2)
                ) as description,
                sold_by as user_id
         FROM sales s
         WHERE sold_by = :user_id1
         ORDER BY sale_date DESC
         LIMIT 10)
         
        UNION ALL
         
        (SELECT 'transfer' as type, transfer_date as date_time,
                CONCAT('Transferred ', 
                    CASE WHEN item_type = 'phone' THEN 
                        (SELECT CONCAT(brand, ' ', model) FROM phones WHERE phone_id = t.item_id)
                    ELSE 
                        (SELECT CONCAT(accessory_name, ' x', quantity) FROM accessories WHERE accessory_id = t.item_id)
                    END,
                    ' to Shop ', destination_shop_id
                ) as description,
                transferred_by as user_id
         FROM transfers t
         WHERE transferred_by = :user_id2
         ORDER BY transfer_date DESC
         LIMIT 10)
         
        UNION ALL
         
        (SELECT 'activity' as type, created_at as date_time, description, user_id
         FROM activity_log
         WHERE user_id = :user_id3
         ORDER BY created_at DESC
         LIMIT 10)
         
        ORDER BY date_time DESC
        LIMIT 30
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id1', $user_id);
    $stmt->bindParam(':user_id2', $user_id);
    $stmt->bindParam(':user_id3', $user_id);
    $stmt->execute();
    $recent_activities = $stmt->fetchAll();
    
} catch(Exception $e) {
    // If error, just continue without combined activities
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
    <title>Employee Dashboard - PhoneStock Pro</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- ZXing Barcode Scanner Library -->
    <script src="https://unpkg.com/@zxing/library@0.19.1"></script>
    
    <style>
        /* ============================================
           DESIGN TOKEN SYSTEM
           ============================================ */
        :root {
            /* Professional color palette - matching admin dashboard */
            --primary: #1e3a5f;
            --primary-hover: #2c5282;
            --primary-subtle: #e6f2ff;
            --secondary: #64748b;
            --success: #2f855a;
            --danger: #c53030;
            --warning: #b7791f;
            --info: #2b6cb0;
            
            --bg-primary: #f7fafc;
            --bg-secondary: #ffffff;
            --bg-tertiary: #f1f5f9;
            
            --text-primary: #1a202c;
            --text-secondary: #4a5568;
            --text-inverse: #ffffff;
            
            --border-primary: #e2e8f0;
            --border-secondary: #cbd5e1;
            
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
            --radius-full: 9999px;
        }
        
        /* ============================================
           BUTTON OVERRIDES - Professional Colors
           ============================================ */
        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
            color: white;
        }
        
        .btn-success {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }
        
        .btn-success:hover,
        .btn-success:focus,
        .btn-success:active {
            background: #276749;
            border-color: #276749;
            color: white;
        }
        
        .btn-danger {
            background: var(--danger);
            border-color: var(--danger);
            color: white;
        }
        
        .btn-danger:hover,
        .btn-danger:focus,
        .btn-danger:active {
            background: #9b2c2c;
            border-color: #9b2c2c;
            color: white;
        }
        
        .btn-warning {
            background: var(--warning);
            border-color: var(--warning);
            color: white;
        }
        
        .btn-warning:hover,
        .btn-warning:focus,
        .btn-warning:active {
            background: #975a16;
            border-color: #975a16;
            color: white;
        }
        
        .btn-info {
            background: var(--info);
            border-color: var(--info);
            color: white;
        }
        
        .btn-info:hover,
        .btn-info:focus,
        .btn-info:active {
            background: #2c5282;
            border-color: #2c5282;
            color: white;
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
            background-color: var(--primary) !important;
            color: white !important;
        }
        
        .badge.bg-success,
        .bg-success {
            background-color: var(--success) !important;
            color: white !important;
        }
        
        .badge.bg-danger,
        .bg-danger {
            background-color: var(--danger) !important;
            color: white !important;
        }
        
        .badge.bg-warning,
        .bg-warning {
            background-color: var(--warning) !important;
            color: white !important;
        }
        
        .badge.bg-info,
        .bg-info {
            background-color: var(--info) !important;
            color: white !important;
        }
        
        .badge.bg-secondary,
        .bg-secondary {
            background-color: var(--secondary) !important;
            color: white !important;
        }
        
        /* Text color utilities */
        .text-primary {
            color: var(--primary) !important;
        }
        
        .text-success {
            color: var(--success) !important;
        }
        
        .text-danger {
            color: var(--danger) !important;
        }
        
        .text-warning {
            color: var(--warning) !important;
        }
        
        .text-info {
            color: var(--info) !important;
        }
        
        /* ============================================
           MOBILE NAVBAR STYLES
           ============================================ */
        /* Mobile Navbar Styles */
        .mobile-navbar {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: var(--text-inverse);
            z-index: 1050;
            padding: 0.75rem 1rem;
            box-shadow: var(--shadow-lg);
        }
        
        .mobile-navbar .navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: var(--text-inverse);
        }
        
        .mobile-menu-btn {
            background: transparent;
            border: none;
            color: var(--text-inverse);
            font-size: 1.5rem;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-md);
        }
        
        .mobile-menu-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .mobile-sidebar {
            position: fixed;
            top: 0;
            left: -280px;
            width: 280px;
            height: 100vh;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: var(--text-inverse);
            z-index: 1050;
            transition: left 0.3s ease;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }
        
        .mobile-sidebar.open {
            left: 0;
        }
        
        .mobile-sidebar .sidebar-user {
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .mobile-sidebar .nav-link {
            color: rgba(255, 255, 255, 0.7);
            padding: 0.75rem 1.5rem;
            border-left: 3px solid transparent;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            text-decoration: none;
        }
        
        .mobile-sidebar .nav-link:hover,
        .mobile-sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-inverse);
            border-left-color: var(--primary);
        }
        
        .mobile-sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1049;
        }
        
        .mobile-sidebar-overlay.open {
            display: block;
        }
        
        /* Scanner Styles */
        .scanner-container {
            position: relative;
            width: 100%;
            height: 300px;
            background: #000;
            border-radius: var(--radius-md);
            overflow: hidden;
            margin-bottom: 1rem;
        }
        
        .scanner-video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .scanner-frame {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80%;
            height: 150px;
            border: 3px solid var(--success);
            border-radius: 8px;
            box-shadow: 0 0 0 1000px rgba(0, 0, 0, 0.5);
        }
        
        .scanner-laser {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--danger);
            animation: scan 2s infinite linear;
            box-shadow: 0 0 10px var(--danger);
        }
        
        @keyframes scan {
            0% { top: 0; }
            50% { top: 100%; }
            100% { top: 0; }
        }
        
        /* Mobile Responsive */
        @media (max-width: 992px) {
            .sidebar {
                display: none;
            }
            
            .main-content {
                margin-left: 0 !important;
                padding: 1rem !important;
                padding-top: 70px !important;
            }
            
            .mobile-navbar {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .stat-card {
                margin-bottom: 1rem;
            }
            
            .quick-action {
                padding: 1rem !important;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                margin-bottom: 1.5rem;
            }
        }
        
        @media (max-width: 768px) {
            .scanner-container {
                height: 250px;
            }
            
            .scanner-frame {
                height: 100px;
            }
            
            .stat-number {
                font-size: 1.875rem !important;
            }
        }
        
        @media (max-width: 576px) {
            .scanner-container {
                height: 200px;
            }
            
            .scanner-frame {
                height: 80px;
            }
            
            .mobile-sidebar {
                width: 100%;
                left: -100%;
            }
        }
        
        /* Common Styles */
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
        }
        
        .sidebar {
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: var(--text-inverse);
            min-height: 100vh;
            position: fixed;
            width: 260px;
            padding: 0;
            box-shadow: var(--shadow-lg);
            z-index: 1000;
        }
        
        .main-content {
            margin-left: 260px;
            padding: 1.5rem;
            min-height: 100vh;
        }
        
        .card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            transition: all 0.2s ease;
        }
        
        .card:hover {
            box-shadow: var(--shadow-md);
        }
        
        .stat-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            border: 1px solid var(--border-primary);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .stat-card.today::before { background: var(--success); }
        .stat-card.phones::before { background: var(--primary); }
        .stat-card.accessories::before { background: #8b5cf6; }
        .stat-card.sales::before { background: var(--danger); }
        .stat-card.revenue::before { background: var(--warning); }
        .stat-card.low-stock::before { background: var(--danger); }
        
        .stat-number {
            font-size: 2.25rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        
        .quick-action {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            height: 100%;
        }
        
        .quick-action:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--border-secondary);
        }
        
        .action-icon {
            font-size: 1.875rem;
            margin-bottom: 0.75rem;
            color: var(--primary);
        }
        
        .quick-action.add .action-icon { color: var(--success); }
        .quick-action.sell .action-icon { color: var(--danger); }
        .quick-action.transfer .action-icon { color: var(--warning); }
        
        .btn {
            padding: 0.5rem 1rem;
            font-weight: 600;
            border-radius: var(--radius-md);
            transition: all 0.2s ease;
        }
        
        .btn:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3);
        }
        
        .form-control, .form-select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            background: var(--bg-secondary);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        .alert {
            border: none;
            border-radius: var(--radius-md);
            padding: 1rem 1.25rem;
            border-left: 4px solid;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left-color: var(--success);
        }
        
        .alert-danger {
            background: #ffe4e6;
            color: #991b1b;
            border-left-color: var(--danger);
        }
        
        .table {
            font-size: 0.875rem;
        }
        
        .table thead th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            color: var(--text-secondary);
            background: var(--bg-tertiary);
            border-bottom: 2px solid var(--border-primary);
        }
        
        .badge {
            font-weight: 600;
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-full);
        }
        
        .bg-success { background: #d1fae5; color: #065f46; }
        .bg-danger { background: #ffe4e6; color: #991b1b; }
        .bg-warning { background: #fef3c7; color: #92400e; }
        .bg-primary { background: var(--primary-subtle); color: #4338ca; }
        
        .nav-link {
            color: rgba(255, 255, 255, 0.7);
            padding: 0.5rem 1.5rem;
            border-left: 3px solid transparent;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            text-decoration: none;
        }
        
        .nav-link:hover,
        .nav-link.active {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-inverse);
            border-left-color: var(--primary);
        }
    </style>
</head>
<body>
    <!-- Mobile Navbar -->
    <div class="mobile-navbar">
        <div class="d-flex align-items-center">
            <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </button>
            <a class="navbar-brand ms-2" href="#">
                <i class="fas fa-mobile-alt"></i>
                <span>PhoneStock</span>
            </a>
        </div>
        <div class="d-flex align-items-center">
            <span class="badge bg-success me-2">Employee</span>
            <button class="btn btn-sm btn-outline-light" onclick="showSection('addPhone')">
                <i class="fas fa-plus"></i>
            </button>
        </div>
    </div>
    
    <!-- Mobile Sidebar -->
    <div class="mobile-sidebar" id="mobileSidebar">
        <div class="sidebar-user">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5><?php echo htmlspecialchars($user_name); ?></h5>
                <button class="btn btn-sm btn-outline-light" onclick="toggleMobileMenu()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-0">
                <i class="fas fa-id-badge me-1"></i> Employee ID: <?php echo $user_id; ?>
            </p>
        </div>
        
        <nav class="nav flex-column py-3">
            <a class="nav-link active" href="#" onclick="navigateToSection('dashboard')">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a class="nav-link" href="#" onclick="navigateToSection('addPhone')">
                <i class="fas fa-plus-circle"></i> Add Phone
            </a>
            <a class="nav-link" href="#" onclick="navigateToSection('addAccessory')">
                <i class="fas fa-box"></i> Add Accessory
            </a>
            <a class="nav-link" href="#" onclick="navigateToSection('sellItem')">
                <i class="fas fa-shopping-cart"></i> Sell Item
            </a>
            <a class="nav-link" href="#" onclick="navigateToSection('transferItem')">
                <i class="fas fa-exchange-alt"></i> Transfer Item
            </a>
            <a class="nav-link" href="#" onclick="navigateToSection('viewStock')">
                <i class="fas fa-eye"></i> View Stock
            </a>
            <a class="nav-link" href="#" onclick="navigateToSection('myActivity')">
                <i class="fas fa-history"></i> My Activity
            </a>
            <a class="nav-link" href="#" onclick="navigateToSection('reports')">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <a class="nav-link text-danger" href="employee.php?logout=1&nocache=<?php echo time(); ?>">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
    </div>
    
    <!-- Mobile Overlay -->
    <div class="mobile-sidebar-overlay" id="mobileOverlay" onclick="toggleMobileMenu()"></div>
    
    <!-- Desktop Sidebar -->
    <div class="sidebar d-none d-lg-block">
        <div class="sidebar-brand" style="padding: 1.5rem;">
            <h4 style="margin: 0;">
                <i class="fas fa-mobile-alt"></i>
                PhoneStock Pro
            </h4>
            <p style="margin: 0.5rem 0 0 0; opacity: 0.8;">Employee Dashboard</p>
        </div>
        
        <div class="sidebar-user" style="padding: 0 1.5rem 1.5rem 1.5rem;">
            <p style="margin: 0 0 0.5rem 0;">Welcome,</p>
            <h5 style="margin: 0;"><?php echo htmlspecialchars($user_name); ?></h5>
            <p class="mb-0">
                <i class="fas fa-id-badge me-1"></i> Employee ID: <?php echo $user_id; ?>
            </p>
        </div>
        
        <nav class="nav flex-column py-3">
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
        
        <div style="padding: 0 1.5rem 1.5rem 1.5rem; position: absolute; bottom: 0; width: 100%; box-sizing: border-box;">
            <a href="employee.php?logout=1&nocache=<?php echo time(); ?>" 
               class="btn btn-outline-light w-100">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="page-header d-flex justify-content-between align-items-center mb-4">
            <h1>Employee Dashboard</h1>
            <div class="d-flex align-items-center gap-3">
                <span class="badge bg-light text-dark">
                    <i class="far fa-calendar-alt me-1"></i>
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
        
        <!-- Stats Cards -->
        <div class="row mb-5">
            <div class="col-lg-2 col-md-4 col-6 mb-4">
                <div class="stat-card today">
                    <i class="fas fa-plus-circle stat-icon" style="color: var(--success);"></i>
                    <div class="stat-number"><?php echo $today_count; ?></div>
                    <div class="stat-label">Added Today</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6 mb-4">
                <div class="stat-card phones">
                    <i class="fas fa-mobile-alt stat-icon" style="color: var(--primary);"></i>
                    <div class="stat-number"><?php echo $total_phones; ?></div>
                    <div class="stat-label">Phones in Stock</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6 mb-4">
                <div class="stat-card accessories">
                    <i class="fas fa-box stat-icon" style="color: #8b5cf6;"></i>
                    <div class="stat-number"><?php echo $total_accessories; ?></div>
                    <div class="stat-label">Accessories</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6 mb-4">
                <div class="stat-card sales">
                    <i class="fas fa-shopping-bag stat-icon" style="color: var(--danger);"></i>
                    <div class="stat-number"><?php echo $today_sales; ?></div>
                    <div class="stat-label">Today's Sales</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6 mb-4">
                <div class="stat-card revenue">
                    <i class="fas fa-coins stat-icon" style="color: var(--warning);"></i>
                    <div class="stat-number">XAF <?php echo number_format($today_revenue, 0); ?></div>
                    <div class="stat-label">Today's Revenue</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6 mb-4">
                <div class="stat-card low-stock">
                    <i class="fas fa-exclamation-triangle stat-icon" style="color: var(--danger);"></i>
                    <div class="stat-number"><?php echo $low_stock; ?></div>
                    <div class="stat-label">Low Stock Items</div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mb-5">
            <div class="col-12">
                <h4 class="mb-4" style="font-weight: 600; color: var(--text-primary);">Quick Actions</h4>
                <div class="row g-4">
                    <div class="col-lg-2 col-md-4 col-6">
                        <div class="quick-action add" onclick="showSection('addPhone')">
                            <div class="action-icon">
                                <i class="fas fa-mobile-alt"></i>
                            </div>
                            <h6>Add Phone</h6>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-6">
                        <div class="quick-action add" onclick="showSection('addAccessory')">
                            <div class="action-icon">
                                <i class="fas fa-headphones"></i>
                            </div>
                            <h6>Add Accessory</h6>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-6">
                        <div class="quick-action sell" onclick="showSection('sellItem')">
                            <div class="action-icon">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                            <h6>Sell Item</h6>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-6">
                        <div class="quick-action transfer" onclick="showSection('transferItem')">
                            <div class="action-icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <h6>Transfer Item</h6>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-6">
                        <div class="quick-action" onclick="showBarcodeScanner()">
                            <div class="action-icon">
                                <i class="fas fa-barcode"></i>
                            </div>
                            <h6>Scan IMEI</h6>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-6">
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
                    <div class="col-lg-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-mobile-alt me-2" style="color: var(--primary);"></i>Recently Added Phones</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($recent_phones) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table">
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
                                                        <span class="badge <?php echo $phone['status'] == 'in_stock' ? 'bg-success' : 'bg-secondary'; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $phone['status'])); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center mb-0">No phones added yet today.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-box me-2" style="color: #8b5cf6;"></i>Recent Accessories</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($recent_accessories) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table">
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
                                                        <span class="badge <?php echo $accessory['status'] == 'in_stock' ? 'bg-success' : 'bg-secondary'; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $accessory['status'])); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center mb-0">No accessories in database.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity Timeline -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-history me-2" style="color: var(--secondary);"></i>Recent Activities</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($recent_activities) > 0): ?>
                                    <div class="activity-timeline">
                                        <?php foreach($recent_activities as $activity): ?>
                                            <div class="activity-item mb-3">
                                                <div class="card p-3" style="border: 1px solid var(--border-primary);">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <p class="mb-1"><?php echo htmlspecialchars($activity['description']); ?></p>
                                                            <small class="text-muted">
                                                                <i class="far fa-clock me-1"></i>
                                                                <?php echo date('M d, Y H:i:s', strtotime($activity['date_time'])); ?>
                                                            </small>
                                                        </div>
                                                        <span class="badge <?php 
                                                            echo $activity['type'] == 'sale' ? 'bg-danger' : 
                                                                 ($activity['type'] == 'transfer' ? 'bg-warning' : 
                                                                 ($activity['type'] == 'activity' && strpos($activity['description'], 'Added') !== false ? 'bg-success' : 'bg-primary')); 
                                                        ?>">
                                                            <?php echo ucfirst($activity['type']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center mb-0">No recent activities.</p>
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
                        <h5 class="mb-0"><i class="fas fa-mobile-alt me-2" style="color: var(--primary);"></i>Add New Phone</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" onsubmit="return validatePhoneForm()">
                            <input type="hidden" name="add_phone" value="1">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-4">
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
                                    <div class="mb-4">
                                        <label class="form-label">Model *</label>
                                        <input type="text" class="form-control" name="model" id="model" placeholder="e.g., Galaxy S23" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label">IMEI Number *</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="imei" id="imei" placeholder="15-digit IMEI" required minlength="15" maxlength="15">
                                            <button type="button" class="btn btn-outline-secondary" onclick="showBarcodeScanner('imei')">
                                                <i class="fas fa-barcode"></i> Scan
                                            </button>
                                        </div>
                                        <small class="text-muted">Enter 15-digit IMEI number</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-4">
                                        <label class="form-label">Color</label>
                                        <input type="text" class="form-control" name="color" id="color" placeholder="e.g., Black, Blue, White">
                                    </div>
                                    <div class="mb-4">
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
                                    <div class="mb-4">
                                        <label class="form-label">Selling Price (XAF)</label>
                                        <input type="number" class="form-control" name="selling_price" id="selling_price" step="0.01" placeholder="0.00" min="0">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" id="notes" rows="3" placeholder="Any additional information..."></textarea>
                            </div>
                            
                            <div class="d-flex gap-3">
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
                        <h5 class="mb-0"><i class="fas fa-box me-2" style="color: #8b5cf6;"></i>Add New Accessory</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" onsubmit="return validateAccessoryForm()">
                            <input type="hidden" name="add_accessory" value="1">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-4">
                                        <label class="form-label">Accessory Name *</label>
                                        <input type="text" class="form-control" name="accessory_name" id="accessory_name" placeholder="e.g., iPhone 14 Case, Samsung Charger" required>
                                    </div>
                                    <div class="mb-4">
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
                                    <div class="mb-4">
                                        <label class="form-label">Brand</label>
                                        <input type="text" class="form-control" name="accessory_brand" id="accessory_brand" placeholder="e.g., Apple, Samsung, Generic">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-4">
                                        <label class="form-label">Quantity *</label>
                                        <input type="number" class="form-control" name="quantity" id="quantity" value="1" min="1" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label">Selling Price (XAF) each</label>
                                        <input type="number" class="form-control" name="accessory_selling_price" id="accessory_selling_price" step="0.01" placeholder="0.00" min="0">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-3">
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
                        <h5 class="mb-0"><i class="fas fa-shopping-cart me-2" style="color: var(--danger);"></i>Sell Item</h5>
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
                        
                        <div class="tab-content mt-4" id="sellTabContent">
                            <!-- Sell Phone Tab -->
                            <div class="tab-pane fade show active" id="sell-phone" role="tabpanel">
                                <form method="POST" action="" onsubmit="return validateSellPhoneForm()">
                                    <input type="hidden" name="sell_phone" value="1">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-4">
                                                <label class="form-label">Select Phone *</label>
                                                <select class="form-select" name="phone_id" id="sell_phone_id" required onchange="updatePhoneSalePrice()">
                                                    <option value="">Select a phone...</option>
                                                    <?php foreach($all_phones as $phone): ?>
                                                    <option value="<?php echo $phone['phone_id']; ?>" data-price="<?php echo $phone['selling_price']; ?>">
                                                        <?php echo htmlspecialchars($phone['brand'] . ' ' . $phone['model']); ?> - 
                                                        <?php echo htmlspecialchars($phone['iemi_number']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="mb-4">
                                                <label class="form-label">Sale Price (XAF) *</label>
                                                <input type="number" class="form-control" name="sale_price" id="sale_price" step="0.01" required min="0">
                                            </div>
                                            <div class="mb-4">
                                                <label class="form-label">Customer Name</label>
                                                <input type="text" class="form-control" name="customer_name" placeholder="Optional">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-4">
                                                <label class="form-label">Customer Phone</label>
                                                <input type="text" class="form-control" name="customer_phone" placeholder="Optional">
                                            </div>
                                            <div class="mb-4">
                                                <label class="form-label">Payment Method *</label>
                                                <select class="form-select" name="payment_method" required>
                                                    <option value="cash">Cash</option>
                                                    <option value="mobile_money">Mobile Money</option>
                                                    <option value="card">Card</option>
                                                    <option value="credit">Credit</option>
                                                </select>
                                            </div>
                                            <div class="mb-4">
                                                <label class="form-label">Notes</label>
                                                <textarea class="form-control" name="sale_notes" rows="3" placeholder="Sale notes..."></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex gap-3">
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
                                            <div class="mb-4">
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
                                                    <div class="mb-4">
                                                        <label class="form-label">Quantity *</label>
                                                        <input type="number" class="form-control" name="sale_quantity" id="sale_quantity" value="1" min="1" required onchange="calculateTotalAccessoryPrice()">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-4">
                                                        <label class="form-label">Price Each (XAF) *</label>
                                                        <input type="number" class="form-control" name="sale_price" id="accessory_sale_price" step="0.01" required min="0" onchange="calculateTotalAccessoryPrice()">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mb-4">
                                                <div class="alert alert-info">
                                                    <strong>Total Price:</strong> XAF <span id="total_accessory_price">0.00</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-4">
                                                <label class="form-label">Customer Name</label>
                                                <input type="text" class="form-control" name="customer_name" placeholder="Optional">
                                            </div>
                                            <div class="mb-4">
                                                <label class="form-label">Customer Phone</label>
                                                <input type="text" class="form-control" name="customer_phone" placeholder="Optional">
                                            </div>
                                            <div class="mb-4">
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
                                    
                                    <div class="mb-4">
                                        <label class="form-label">Notes</label>
                                        <textarea class="form-control" name="sale_notes" rows="2" placeholder="Sale notes..."></textarea>
                                    </div>
                                    
                                    <div class="d-flex gap-3">
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
                        <h5 class="mb-0"><i class="fas fa-exchange-alt me-2" style="color: var(--warning);"></i>Transfer Item to Another Shop</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" onsubmit="return validateTransferForm()">
                            <input type="hidden" name="transfer_item" value="1">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-4">
                                        <label class="form-label">Item Type *</label>
                                        <select class="form-select" name="item_type" id="item_type" required onchange="toggleTransferFields()">
                                            <option value="">Select Type</option>
                                            <option value="phone">Phone</option>
                                            <option value="accessory">Accessory</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Phone selection (hidden by default) -->
                                    <div class="mb-4" id="phone_select_field" style="display: none;">
                                        <label class="form-label">Select Phone *</label>
                                        <select class="form-select" name="phone_transfer_id" id="transfer_phone_id" required>
                                            <option value="">Select a phone...</option>
                                            <?php foreach($all_phones as $phone): ?>
                                            <option value="<?php echo $phone['phone_id']; ?>">
                                                <?php echo htmlspecialchars($phone['brand'] . ' ' . $phone['model']); ?> - 
                                                <?php echo htmlspecialchars($phone['iemi_number']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                            </select>
                                    </div>
                                    
                                    <!-- Accessory selection (hidden by default) -->
                                    <div class="mb-4" id="accessory_select_field" style="display: none;">
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
                                    
                                    <!-- Quantity field (only for accessories) -->
                                    <div class="mb-4" id="quantity_field" style="display: none;">
                                        <label class="form-label">Quantity *</label>
                                        <input type="number" class="form-control" name="transfer_quantity" id="transfer_quantity" value="1" min="1" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-4">
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
                                    
                                    <div class="mb-4">
                                        <label class="form-label">Transfer Notes</label>
                                        <textarea class="form-control" name="transfer_notes" rows="4" placeholder="Reason for transfer, special instructions..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Transferring items will automatically update stock quantities and track movement between shops.
                            </div>
                            
                            <div class="d-flex gap-3">
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
                        <h5 class="mb-0"><i class="fas fa-eye me-2" style="color: var(--primary);"></i>View All Stock</h5>
                    </div>
                    <div class="card-body">
                        <!-- Search Bar -->
                        <div class="row mb-4">
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
                                <a href="employee.php" class="float-end btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-times"></i> Clear Search
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Search Results -->
                        <?php if (isset($_GET['search']) && !empty($search_term)): ?>
                            <?php if (count($phone_results) > 0 || count($accessory_results) > 0): ?>
                                <!-- Phones Table -->
                                <?php if (count($phone_results) > 0): ?>
                                    <h5 class="mt-4 mb-3" style="font-weight: 600;">Phones (<?php echo count($phone_results); ?>)</h5>
                                    <div class="table-responsive mb-4">
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
                                                        <span class="badge <?php 
                                                            echo $phone['status'] == 'in_stock' ? 'bg-success' : 
                                                            ($phone['status'] == 'sold' ? 'bg-danger' : 
                                                            ($phone['status'] == 'transferred' ? 'bg-warning' : 'bg-secondary')); 
                                                        ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $phone['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M d, Y', strtotime($phone['created_at'])); ?></td>
                                                    <td>
                                                        <?php if ($phone['status'] == 'in_stock'): ?>
                                                        <button class="btn btn-sm btn-info" onclick="viewPhoneDetails(<?php echo $phone['phone_id']; ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <a href="employee.php?delete_phone=<?php echo $phone['phone_id']; ?>" 
                                                           class="btn btn-sm btn-danger" 
                                                           onclick="return confirm('Mark this phone as unavailable?')">
                                                            <i class="fas fa-times"></i>
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
                                    <h5 class="mt-4 mb-3" style="font-weight: 600;">Accessories (<?php echo count($accessory_results); ?>)</h5>
                                    <div class="table-responsive mb-4">
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
                                                        <span class="badge <?php 
                                                            echo $accessory['status'] == 'in_stock' ? 'bg-success' : 
                                                            ($accessory['status'] == 'out_of_stock' ? 'bg-danger' : 
                                                            ($accessory['status'] == 'transferred' ? 'bg-warning' : 'bg-secondary')); 
                                                        ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $accessory['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M d, Y', strtotime($accessory['created_at'])); ?></td>
                                                    <td>
                                                        <?php if ($accessory['status'] == 'in_stock'): ?>
                                                        <button class="btn btn-sm btn-info" onclick="viewAccessoryDetails(<?php echo $accessory['accessory_id']; ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <a href="employee.php?delete_accessory=<?php echo $accessory['accessory_id']; ?>" 
                                                           class="btn btn-sm btn-danger" 
                                                           onclick="return confirm('Mark this accessory as unavailable?')">
                                                            <i class="fas fa-times"></i>
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
                            <h5 class="mt-4 mb-3" style="font-weight: 600;">All Phones in Stock (<?php echo count($all_phones); ?>)</h5>
                            <div class="table-responsive mb-4">
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
                                                    <span class="badge <?php echo $phone['status'] == 'in_stock' ? 'bg-success' : 'bg-secondary'; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $phone['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($phone['created_at'])); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-info" onclick="viewPhoneDetails(<?php echo $phone['phone_id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <a href="employee.php?delete_phone=<?php echo $phone['phone_id']; ?>" 
                                                       class="btn btn-sm btn-danger" 
                                                       onclick="return confirm('Mark this phone as unavailable?')">
                                                        <i class="fas fa-times"></i>
                                                    </a>
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
                            <h5 class="mt-4 mb-3" style="font-weight: 600;">All Accessories in Stock (<?php echo count($all_accessories); ?>)</h5>
                            <div class="table-responsive mb-4">
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
                                                    <span class="badge <?php echo $accessory['status'] == 'in_stock' ? 'bg-success' : 'bg-secondary'; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $accessory['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($accessory['created_at'])); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-info" onclick="viewAccessoryDetails(<?php echo $accessory['accessory_id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <a href="employee.php?delete_accessory=<?php echo $accessory['accessory_id']; ?>" 
                                                       class="btn btn-sm btn-danger" 
                                                       onclick="return confirm('Mark this accessory as unavailable?')">
                                                        <i class="fas fa-times"></i>
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
            
            <!-- My Activity Section -->
            <div id="myActivity" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history me-2" style="color: var(--secondary);"></i>My Activity Log</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        // Get detailed activity log for this employee
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
                                            $badge_color = 'bg-danger';
                                        } elseif ($activity['source'] == 'transfer') {
                                            $activity_class = 'transfer';
                                            $badge_color = 'bg-warning';
                                        } elseif (strpos($activity_type, 'add_') === 0) {
                                            $activity_class = 'add';
                                            $badge_color = 'bg-success';
                                        } else {
                                            $activity_class = 'system';
                                            $badge_color = 'bg-primary';
                                        }
                                    ?>
                                        <div class="activity-item mb-3">
                                            <div class="card p-3" style="border: 1px solid var(--border-primary);">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <p class="mb-1"><?php echo htmlspecialchars($activity['description']); ?></p>
                                                        <small class="text-muted">
                                                            <i class="far fa-clock me-1"></i>
                                                            <?php echo date('M d, Y H:i:s', strtotime($activity['date_time'])); ?>
                                                        </small>
                                                    </div>
                                                    <span class="badge <?php echo $badge_color; ?>">
                                                        <?php 
                                                        if ($activity['source'] == 'sale') echo 'Sale';
                                                        elseif ($activity['source'] == 'transfer') echo 'Transfer';
                                                        else echo ucfirst(str_replace('_', ' ', $activity_type));
                                                        ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center mb-0">No activity recorded yet.</p>
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
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2" style="color: var(--secondary);"></i>Reports & Analytics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-5">
                            <div class="col-md-4">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <h3>Daily Report</h3>
                                        <p class="text-muted">Today's sales and activities</p>
                                        <a href="report_daily.php" target="_blank" class="btn btn-primary">
                                            <i class="fas fa-file-pdf me-2"></i>Generate Daily Report
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <h3>Weekly Report</h3>
                                        <p class="text-muted">This week's performance</p>
                                        <a href="report_weekly.php" target="_blank" class="btn btn-success">
                                            <i class="fas fa-file-excel me-2"></i>Generate Weekly Report
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <h3>Monthly Report</h3>
                                        <p class="text-muted">This month's overview</p>
                                        <a href="report_monthly.php" target="_blank" class="btn btn-warning">
                                            <i class="fas fa-chart-line me-2"></i>Generate Monthly Report
                                        </a>
                                    </div>
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
                                                WHERE s.sold_by = :user_id
                                                ORDER BY s.sale_date DESC
                                                LIMIT 10
                                            ";
                                            
                                            $stmt = $db->prepare($query);
                                            $stmt->bindParam(':user_id', $user_id);
                                            $stmt->execute();
                                            $recent_sales = $stmt->fetchAll();
                                            
                                            if (count($recent_sales) > 0): ?>
                                                <div class="table-responsive">
                                                    <table class="table">
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
                                                <p class="text-muted text-center mb-0">No sales recorded yet.</p>
                                            <?php endif;
                                        } catch(Exception $e) {
                                            echo '<p class="text-muted text-center mb-0">Could not load sales data.</p>';
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
                                                WHERE t.transferred_by = :user_id
                                                ORDER BY t.transfer_date DESC
                                                LIMIT 10
                                            ";
                                            
                                            $stmt = $db->prepare($query);
                                            $stmt->bindParam(':user_id', $user_id);
                                            $stmt->execute();
                                            $recent_transfers = $stmt->fetchAll();
                                            
                                            if (count($recent_transfers) > 0): ?>
                                                <div class="table-responsive">
                                                    <table class="table">
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
                                                <p class="text-muted text-center mb-0">No transfers recorded yet.</p>
                                            <?php endif;
                                        } catch(Exception $e) {
                                            echo '<p class="text-muted text-center mb-0">Could not load transfer data.</p>';
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
    
    <!-- Barcode Scanner Modal -->
    <div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--success); color: var(--text-inverse);">
                    <h5 class="modal-title" id="barcodeScannerModalLabel">
                        <i class="fas fa-barcode me-2"></i>Scan IMEI/Barcode
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="scanner-container">
                        <video id="barcode-video" class="scanner-video" muted playsinline></video>
                        <div class="scanner-frame">
                            <div class="scanner-laser"></div>
                        </div>
                    </div>
                    
                    <div id="barcode-result" class="mt-3">
                        <div class="alert alert-info" id="result">
                            <i class="fas fa-info-circle me-2"></i>
                            <span id="result-text">Point camera at IMEI barcode to scan...</span>
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
                                <button class="btn btn-secondary" onclick="resetBarcodeScanner()">
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
                        <i class="fas fa-check me-2"></i>Use This IMEI
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Barcode Scanner Variables
        let codeReader = null;
        let videoElement = null;
        let stream = null;
        let isScanning = false;
        let lastScannedCode = '';
        let targetField = 'imei';
        let scanInterval = null;
        
        // Mobile Menu Functions
        function toggleMobileMenu() {
            const sidebar = document.getElementById('mobileSidebar');
            const overlay = document.getElementById('mobileOverlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
        }
        
        function navigateToSection(sectionId) {
            toggleMobileMenu();
            setTimeout(() => showSection(sectionId, true), 300);
        }
        
        // Section Navigation - FIXED: Now properly scrolls to section
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
            
            // Update active nav links
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            
            // Activate desktop nav link
            const desktopLinks = document.querySelectorAll('.sidebar .nav-link');
            desktopLinks.forEach(link => {
                if (link.getAttribute('onclick')?.includes(sectionId)) {
                    link.classList.add('active');
                }
            });
            
            // Activate mobile nav link
            const mobileLinks = document.querySelectorAll('.mobile-sidebar .nav-link');
            mobileLinks.forEach(link => {
                if (link.getAttribute('onclick')?.includes(sectionId)) {
                    link.classList.add('active');
                }
            });
            
            // Scroll to section instead of top of page
            if (scrollToTop && section) {
                // Get the section's position
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
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Check URL for search params
            const urlParams = new URLSearchParams(window.location.search);
            const hasSearch = urlParams.has('search');
            
            // Show appropriate section
            if (hasSearch) {
                showSection('viewStock', false);
            } else {
                showSection('dashboard');
            }
            
            // Auto-clear alerts after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    try {
                        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                        if (bsAlert) bsAlert.close();
                    } catch (e) {
                        // Ignore Bootstrap errors
                    }
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
            calculateTotalAccessoryPrice();
            
            // Check if mobile
            if (window.innerWidth <= 992) {
                const mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.paddingTop = '70px';
            }
        });
        
        // Form Validation Functions
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
        
        // Transfer Form Functions
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
        
        // Sale Form Functions
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
        
        // Calculate total accessory price
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
        
        // Barcode Scanner Functions - FIXED: Properly initialized scanner
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
                
                // Wait for video to be ready
                await new Promise((resolve, reject) => {
                    videoElement.onloadedmetadata = resolve;
                    videoElement.onerror = reject;
                    setTimeout(() => {
                        if (videoElement.readyState >= 1) resolve();
                    }, 1000);
                });
                
                // IMPORTANT: Play the video explicitly
                await videoElement.play();
                
                // Wait a bit for video to actually be playing
                await new Promise(resolve => setTimeout(resolve, 500));
                
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
                
                // Start decoding from video with the element itself
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
                            // Don't stop scanning
                        }
                    }
                    
                    // Only log real errors, not NotFoundException
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
            
            // Clear interval if exists
            if (scanInterval) {
                clearInterval(scanInterval);
                scanInterval = null;
            }
            
            isScanning = false;
            document.getElementById('start-scan-btn').style.display = 'block';
            document.getElementById('stop-scan-btn').style.display = 'none';
        }
        
        function resetBarcodeScanner() {
            stopBarcodeScanner();
            lastScannedCode = '';
            const resultText = document.getElementById('result-text');
            const resultDiv = document.getElementById('result');
            
            if (resultText && resultDiv) {
                resultText.textContent = 'Point camera at IMEI barcode to scan...';
                resultDiv.className = 'alert alert-info';
            }
            
            const useBtn = document.getElementById('use-barcode-btn');
            if (useBtn) {
                useBtn.style.display = 'none';
            }
            
            const manualInput = document.getElementById('manual-barcode');
            if (manualInput) {
                manualInput.value = '';
            }
        }
        
        function useScannedBarcode() {
            if (lastScannedCode) {
                console.log('Using scanned code:', lastScannedCode, 'for field:', targetField);
                
                // Auto-fill IMEI field
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
                
                // Close modal
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
                // Extract only digits
                const digitsOnly = manualCode.replace(/\D/g, '');
                
                if (digitsOnly.length === 15) {
                    lastScannedCode = digitsOnly;
                    useScannedBarcode();
                } else if (digitsOnly.length >= 13 && digitsOnly.length <= 15) {
                    // Allow partial IMEI (common with some barcode formats)
                    lastScannedCode = digitsOnly.padEnd(15, '0').substring(0, 15);
                    useScannedBarcode();
                } else {
                    alert('Please enter a valid IMEI number (13-15 digits)');
                }
            } else {
                alert('Please enter an IMEI number');
            }
        }
        
        // View Details Functions
        function viewPhoneDetails(phoneId) {
            fetch('get_phone_details.php?id=' + phoneId)
                .then(response => response.text())
                .then(html => {
                    const modal = new bootstrap.Modal(document.createElement('div'));
                    modal._element.innerHTML = `
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header" style="background: var(--primary); color: var(--text-inverse);">
                                    <h5 class="modal-title">Phone Details</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    ${html}
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(modal._element);
                    modal.show();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading phone details');
                });
        }
        
        function viewAccessoryDetails(accessoryId) {
            fetch('get_accessory_details.php?id=' + accessoryId)
                .then(response => response.text())
                .then(html => {
                    const modal = new bootstrap.Modal(document.createElement('div'));
                    modal._element.innerHTML = `
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header" style="background: #8b5cf6; color: var(--text-inverse);">
                                    <h5 class="modal-title">Accessory Details</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    ${html}
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(modal._element);
                    modal.show();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading accessory details');
                });
        }
        
        // Handle window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth <= 992) {
                const mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.paddingTop = '70px';
            } else {
                const mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.paddingTop = '';
            }
        });
    </script>
</body>
</html>
