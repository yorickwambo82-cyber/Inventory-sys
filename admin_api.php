<?php
require_once 'includes/session.php';
require_once 'db_config.php';

// Check if user is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_stats':
        getStats();
        break;
    
    case 'get_inventory':
        getInventory();
        break;
    
    case 'get_employees':
        getEmployees();
        break;
    
    case 'get_recent_activity':
        getRecentActivity();
        break;
    
    case 'get_low_stock':
        getLowStock();
        break;
    
    case 'get_sales_data':
        getSalesData();
        break;
    
    case 'add_employee':
        addEmployee();
        break;
    
    case 'update_employee':
        updateEmployee();
        break;
    
    case 'delete_employee':
        deleteEmployee();
        break;
    
    case 'add_item':
        addItem();
        break;
    
    case 'update_item':
        updateItem();
        break;
    
    case 'delete_item':
        deleteItem();
        break;
    
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}

function getStats() {
    global $conn;
    
    // Total Revenue (sum of all sold phones and accessories sold)
    $revenue_query = "SELECT 
        COALESCE(SUM(selling_price), 0) as phone_revenue 
        FROM phones WHERE status = 'sold'";
    $revenue_result = $conn->query($revenue_query);
    $phone_revenue = $revenue_result->fetch_assoc()['phone_revenue'];
    
    // For accessories, we need to track sales separately
    // For now, we'll estimate based on quantity changes
    $acc_revenue = 0; // You can add a sales table later
    
    $total_revenue = $phone_revenue + $acc_revenue;
    
    // Total Stock
    $stock_query = "SELECT 
        (SELECT COUNT(*) FROM phones WHERE status = 'in_stock') as phones,
        (SELECT COALESCE(SUM(quantity), 0) FROM accessories WHERE status IN ('in_stock', 'low_stock')) as accessories";
    $stock_result = $conn->query($stock_query);
    $stock = $stock_result->fetch_assoc();
    $total_stock = $stock['phones'] + $stock['accessories'];
    
    // Total Profit (selling price - buying price for sold items)
    $profit_query = "SELECT 
        COALESCE(SUM(selling_price - buying_price), 0) as profit 
        FROM phones WHERE status = 'sold'";
    $profit_result = $conn->query($profit_query);
    $total_profit = $profit_result->fetch_assoc()['profit'];
    
    // Active Users
    $users_query = "SELECT COUNT(*) as total FROM users WHERE is_active = 1";
    $users_result = $conn->query($users_query);
    $active_users = $users_result->fetch_assoc()['total'];
    
    // Calculate profit margin
    $profit_margin = $total_revenue > 0 ? ($total_profit / $total_revenue) * 100 : 0;
    
    echo json_encode([
        'revenue' => $total_revenue,
        'stock' => $total_stock,
        'phones' => $stock['phones'],
        'accessories' => $stock['accessories'],
        'profit' => $total_profit,
        'profit_margin' => round($profit_margin, 1),
        'users' => $active_users
    ]);
}

function getInventory() {
    global $conn;
    
    $filter = $_GET['filter'] ?? '';
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    
    // Get phones
    $phone_query = "SELECT 
        CONCAT('P', LPAD(phone_id, 3, '0')) as id,
        CONCAT(brand, ' ', model) as name,
        'Phone' as category,
        iemi_number as code,
        buying_price,
        selling_price,
        1 as stock,
        status,
        arrival_date as created_at
        FROM phones WHERE 1=1";
    
    if ($status) {
        $status = clean_input($status);
        $phone_query .= " AND status = '$status'";
    }
    
    if ($search) {
        $search = clean_input($search);
        $phone_query .= " AND (brand LIKE '%$search%' OR model LIKE '%$search%' OR iemi_number LIKE '%$search%')";
    }
    
    // Get accessories
    $acc_query = "SELECT 
        CONCAT('A', LPAD(accessory_id, 3, '0')) as id,
        accessory_name as name,
        'Accessory' as category,
        CONCAT(category, '-', accessory_id) as code,
        buying_price,
        selling_price,
        quantity as stock,
        status,
        created_at
        FROM accessories WHERE 1=1";
    
    if ($search) {
        $acc_query .= " AND (accessory_name LIKE '%$search%' OR brand LIKE '%$search%')";
    }
    
    // Apply filter
    if ($filter === 'Phones') {
        $query = $phone_query;
    } elseif ($filter === 'Accessories') {
        $query = $acc_query;
    } elseif ($filter === 'Low Stock') {
        $query = "$acc_query AND (quantity < 10 OR status = 'low_stock')";
    } else {
        $query = "($phone_query) UNION ALL ($acc_query)";
    }
    
    $query .= " ORDER BY created_at DESC";
    
    $result = $conn->query($query);
    $items = [];
    
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    
    echo json_encode($items);
}

function getEmployees() {
    global $conn;
    
    $query = "SELECT 
        user_id,
        username,
        full_name,
        role,
        is_active,
        last_login,
        created_at
        FROM users 
        WHERE role = 'employee'
        ORDER BY created_at DESC";
    
    $result = $conn->query($query);
    $employees = [];
    
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    
    echo json_encode($employees);
}

function getRecentActivity() {
    global $conn;
    
    $query = "SELECT 
        al.action_type,
        al.description,
        al.created_at,
        u.full_name
        FROM activity_log al
        JOIN users u ON al.user_id = u.user_id
        ORDER BY al.created_at DESC
        LIMIT 10";
    
    $result = $conn->query($query);
    $activities = [];
    
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
    
    echo json_encode($activities);
}

function getLowStock() {
    global $conn;
    
    $query = "SELECT 
        accessory_name as name,
        'Accessory' as type,
        quantity as stock,
        accessory_id as id
        FROM accessories 
        WHERE quantity < 10 OR status = 'low_stock'
        ORDER BY quantity ASC
        LIMIT 5";
    
    $result = $conn->query($query);
    $items = [];
    
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    
    echo json_encode($items);
}

function getSalesData() {
    global $conn;
    
    // Weekly sales for line chart
    $weekly_query = "SELECT 
        DAYNAME(sold_date) as day,
        SUM(selling_price) as total
        FROM phones 
        WHERE sold_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND status = 'sold'
        GROUP BY day, DAYOFWEEK(sold_date)
        ORDER BY DAYOFWEEK(sold_date)";
    
    $weekly_result = $conn->query($weekly_query);
    $weekly_sales = [];
    
    while ($row = $weekly_result->fetch_assoc()) {
        $weekly_sales[] = $row;
    }
    
    // Brand distribution for pie chart
    $brand_query = "SELECT 
        brand,
        COUNT(*) as count
        FROM phones 
        WHERE status = 'sold'
        GROUP BY brand
        ORDER BY count DESC
        LIMIT 5";
    
    $brand_result = $conn->query($brand_query);
    $brands = [];
    
    while ($row = $brand_result->fetch_assoc()) {
        $brands[] = $row;
    }
    
    echo json_encode([
        'weekly' => $weekly_sales,
        'brands' => $brands
    ]);
}

function addEmployee() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $full_name = clean_input($data['full_name']);
    $username = clean_input($data['username']);
    $role = clean_input($data['role'] ?? 'employee');
    
    $query = "INSERT INTO users (username, full_name, role) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sss", $username, $full_name, $role);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'id' => $conn->insert_id]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to add employee']);
    }
}

function updateEmployee() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $user_id = (int)$data['user_id'];
    $full_name = clean_input($data['full_name']);
    $is_active = (int)$data['is_active'];
    
    $query = "UPDATE users SET full_name = ?, is_active = ? WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sii", $full_name, $is_active, $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update employee']);
    }
}

function deleteEmployee() {
    global $conn;
    
    $user_id = (int)$_GET['id'];
    
    $query = "DELETE FROM users WHERE user_id = ? AND role != 'admin'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete employee']);
    }
}

function addItem() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $type = clean_input($data['type']);
    
    if ($type === 'phone') {
        $query = "INSERT INTO phones (iemi_number, brand, model, color, memory, buying_price, selling_price, registered_by) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sssssddi", 
            $data['iemi_number'],
            $data['brand'],
            $data['model'],
            $data['color'],
            $data['memory'],
            $data['buying_price'],
            $data['selling_price'],
            $_SESSION['user_id']
        );
    } else {
        $query = "INSERT INTO accessories (accessory_name, category, brand, buying_price, selling_price, quantity, registered_by) 
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sssddii",
            $data['name'],
            $data['category'],
            $data['brand'],
            $data['buying_price'],
            $data['selling_price'],
            $data['quantity'],
            $_SESSION['user_id']
        );
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'id' => $conn->insert_id]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to add item']);
    }
}

function updateItem() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Implementation for updating items
    echo json_encode(['success' => true]);
}

function deleteItem() {
    global $conn;
    
    $id = $_GET['id'];
    $type = $_GET['type'];
    
    if ($type === 'phone') {
        $query = "DELETE FROM phones WHERE phone_id = ?";
    } else {
        $query = "DELETE FROM accessories WHERE accessory_id = ?";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete item']);
    }
}
?>