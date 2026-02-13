<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get all inventory items
    $query = "SELECT 
                CASE WHEN p.phone_id IS NOT NULL THEN 'Phone' ELSE 'Accessory' END as type,
                COALESCE(CONCAT(p.brand, ' ', p.model), a.accessory_name) as name,
                COALESCE(p.category, a.category) as category,
                COALESCE(p.iemi_number, 'N/A') as code,
                COALESCE(p.buying_price, a.buying_price) as buying_price,
                COALESCE(p.selling_price, a.selling_price) as selling_price,
                COALESCE('1', a.quantity) as stock,
                COALESCE(p.status, a.status) as status,
                COALESCE(p.created_at, a.created_at) as created_at
              FROM phones p
              LEFT JOIN accessories a ON FALSE
              WHERE p.status IS NOT NULL 
              
              UNION ALL
              
              SELECT 
                'Accessory' as type,
                a.accessory_name as name,
                a.category as category,
                'N/A' as code,
                a.buying_price as buying_price,
                a.selling_price as selling_price,
                a.quantity as stock,
                a.status as status,
                a.created_at as created_at
              FROM accessories a
              WHERE a.status IS NOT NULL
              
              ORDER BY created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $inventory = $stmt->fetchAll();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=inventory_' . date('Y-m-d') . '.csv');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));
    
    // Add headers
    fputcsv($output, ['Type', 'Name', 'Category', 'IMEI/Code', 'Buying Price (XAF)', 'Selling Price (XAF)', 'Stock', 'Status', 'Added Date']);
    
    // Add data
    foreach ($inventory as $item) {
        fputcsv($output, [
            $item['type'],
            $item['name'],
            $item['category'],
            $item['code'],
            number_format($item['buying_price'], 2),
            number_format($item['selling_price'], 2),
            $item['stock'],
            ucfirst(str_replace('_', ' ', $item['status'])),
            date('Y-m-d', strtotime($item['created_at']))
        ]);
    }
    
    fclose($output);
    
} catch(Exception $e) {
    echo "Error exporting inventory: " . $e->getMessage();
}
?>