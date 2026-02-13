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
    
    // Get report period from query string (default: current month)
    $period = $_GET['period'] ?? 'month';
    $startDate = $_GET['start'] ?? date('Y-m-01');
    $endDate = $_GET['end'] ?? date('Y-m-t');
    
    // Get statistics
    // Total revenue
    $query = "SELECT COALESCE(SUM(selling_price), 0) as total_revenue 
              FROM phones 
              WHERE status = 'sold' AND DATE(created_at) BETWEEN :start_date AND :end_date";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':start_date', $startDate);
    $stmt->bindParam(':end_date', $endDate);
    $stmt->execute();
    $revenue = $stmt->fetch()['total_revenue'];
    
    // Total items sold
    $query = "SELECT COUNT(*) as total_sold 
              FROM phones 
              WHERE status = 'sold' AND DATE(created_at) BETWEEN :start_date AND :end_date";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':start_date', $startDate);
    $stmt->bindParam(':end_date', $endDate);
    $stmt->execute();
    $totalSold = $stmt->fetch()['total_sold'];
    
    // Top selling brands
    $query = "SELECT brand, COUNT(*) as count 
              FROM phones 
              WHERE status = 'sold' AND DATE(created_at) BETWEEN :start_date AND :end_date
              GROUP BY brand 
              ORDER BY count DESC 
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':start_date', $startDate);
    $stmt->bindParam(':end_date', $endDate);
    $stmt->execute();
    $topBrands = $stmt->fetchAll();
    
    // Employee activity
    $query = "SELECT u.full_name, COUNT(al.log_id) as activity_count
              FROM activity_log al
              JOIN users u ON al.user_id = u.user_id
              WHERE DATE(al.created_at) BETWEEN :start_date AND :end_date
              GROUP BY u.user_id
              ORDER BY activity_count DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':start_date', $startDate);
    $stmt->bindParam(':end_date', $endDate);
    $stmt->execute();
    $employeeActivity = $stmt->fetchAll();
    
} catch(Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report - PhoneStock Pro</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { color: #333; margin-bottom: 5px; }
        .header .period { color: #666; }
        .stats { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .stat-box { background: #f5f5f5; padding: 15px; border-radius: 5px; text-align: center; flex: 1; margin: 0 10px; }
        .stat-value { font-size: 24px; font-weight: bold; color: #e74c3c; }
        .section { margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th { background: #2c3e50; color: white; padding: 10px; text-align: left; }
        td { padding: 8px 10px; border-bottom: 1px solid #ddd; }
        tr:nth-child(even) { background: #f9f9f9; }
        .footer { text-align: center; margin-top: 50px; color: #666; font-size: 12px; }
        @media print {
            .no-print { display: none; }
            body { margin: 0; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>PhoneStock Pro - Sales Report</h1>
        <div class="period">Period: <?php echo date('F d, Y', strtotime($startDate)); ?> to <?php echo date('F d, Y', strtotime($endDate)); ?></div>
        <div class="period">Generated on: <?php echo date('F d, Y H:i:s'); ?></div>
    </div>
    
    <div class="stats">
        <div class="stat-box">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value"><?php echo number_format($revenue, 0); ?> XAF</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Items Sold</div>
            <div class="stat-value"><?php echo $totalSold; ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Average Price</div>
            <div class="stat-value"><?php echo $totalSold > 0 ? number_format($revenue / $totalSold, 0) : 0; ?> XAF</div>
        </div>
    </div>
    
    <div class="section">
        <h2>Top Selling Brands</h2>
        <table>
            <thead>
                <tr>
                    <th>Brand</th>
                    <th>Units Sold</th>
                    <th>Market Share</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($topBrands as $brand): ?>
                <tr>
                    <td><?php echo htmlspecialchars($brand['brand']); ?></td>
                    <td><?php echo $brand['count']; ?></td>
                    <td><?php echo $totalSold > 0 ? round(($brand['count'] / $totalSold) * 100, 1) : 0; ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="section">
        <h2>Employee Activity</h2>
        <table>
            <thead>
                <tr>
                    <th>Employee Name</th>
                    <th>Activity Count</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($employeeActivity as $employee): ?>
                <tr>
                    <td><?php echo htmlspecialchars($employee['full_name']); ?></td>
                    <td><?php echo $employee['activity_count']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="footer">
        <p>PhoneStock Pro - Inventory Management System</p>
        <p>Report generated automatically by the system</p>
    </div>
    
    <div class="no-print" style="margin-top: 30px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer;">
            Print Report
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; background: #95a5a6; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">
            Close
        </button>
    </div>
</body>
</html>