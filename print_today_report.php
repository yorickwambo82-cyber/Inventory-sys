<?php
require_once 'includes/session.php';

// Check if user is logged in as employee
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'employee') {
    header("Location: login.php");
    exit();
}

// Include database connection
require_once 'config/database.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];

// Get today's date
$today = date('Y-m-d');
$today_formatted = date('F j, Y');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get phones added today by this employee
    $query = "SELECT p.*, u.full_name as registered_by_name 
              FROM phones p 
              LEFT JOIN users u ON p.registered_by = u.user_id 
              WHERE DATE(p.created_at) = :today AND p.registered_by = :user_id 
              ORDER BY p.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':today', $today);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $today_phones = $stmt->fetchAll();
    
    // Get accessories added today by this employee
    $query = "SELECT a.*, u.full_name as registered_by_name 
              FROM accessories a 
              LEFT JOIN users u ON a.registered_by = u.user_id 
              WHERE DATE(a.created_at) = :today AND a.registered_by = :user_id 
              ORDER BY a.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':today', $today);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $today_accessories = $stmt->fetchAll();
    
    // Get statistics
    $query = "SELECT COUNT(*) as phone_count, 
                     SUM(buying_price) as total_buying, 
                     SUM(selling_price) as total_selling 
              FROM phones 
              WHERE DATE(created_at) = :today AND registered_by = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':today', $today);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $phone_stats = $stmt->fetch();
    
    $query = "SELECT COUNT(*) as accessory_count, 
                     SUM(quantity) as total_quantity,
                     SUM(buying_price * quantity) as total_buying, 
                     SUM(selling_price * quantity) as total_selling 
              FROM accessories 
              WHERE DATE(created_at) = :today AND registered_by = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':today', $today);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $accessory_stats = $stmt->fetch();
    
    // Calculate totals
    $total_items = ($phone_stats['phone_count'] ?? 0) + ($accessory_stats['accessory_count'] ?? 0);
    $total_buying = ($phone_stats['total_buying'] ?? 0) + ($accessory_stats['total_buying'] ?? 0);
    $total_selling = ($phone_stats['total_selling'] ?? 0) + ($accessory_stats['total_selling'] ?? 0);
    $total_quantity = ($accessory_stats['total_quantity'] ?? 0);
    
} catch(Exception $e) {
    $today_phones = [];
    $today_accessories = [];
    $phone_stats = [];
    $accessory_stats = [];
    $total_items = 0;
    $total_buying = 0;
    $total_selling = 0;
    $total_quantity = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Today's Report - PhoneStock Pro</title>
    <style>
        @media print {
            body {
                margin: 0;
                padding: 20px;
                font-family: 'Arial', sans-serif;
                font-size: 12px;
                color: #000;
                background: #fff;
            }
            
            .no-print {
                display: none !important;
            }
            
            .page-break {
                page-break-before: always;
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            
            th, td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }
            
            th {
                background-color: #f5f5f5;
                font-weight: bold;
            }
            
            .header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #333;
                padding-bottom: 15px;
            }
            
            .header h1 {
                margin: 0;
                font-size: 24px;
                color: #2c3e50;
            }
            
            .header h2 {
                margin: 5px 0;
                font-size: 18px;
                color: #7f8c8d;
            }
            
            .header .date {
                font-size: 14px;
                color: #95a5a6;
                margin-top: 5px;
            }
            
            .summary {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
            }
            
            .summary-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 15px;
                margin-top: 15px;
            }
            
            .summary-item {
                background: white;
                padding: 15px;
                border-radius: 5px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                text-align: center;
            }
            
            .summary-number {
                font-size: 24px;
                font-weight: bold;
                color: #2c3e50;
                margin: 10px 0;
            }
            
            .summary-label {
                font-size: 12px;
                color: #7f8c8d;
                text-transform: uppercase;
            }
            
            .section-title {
                background: #3498db;
                color: white;
                padding: 10px 15px;
                margin: 20px 0 10px 0;
                border-radius: 3px;
                font-size: 16px;
            }
            
            .total-row {
                background: #f1f8e9;
                font-weight: bold;
            }
            
            .text-right {
                text-align: right;
            }
            
            .text-center {
                text-align: center;
            }
            
            .logo {
                max-width: 150px;
                margin-bottom: 10px;
            }
            
            .footer {
                text-align: center;
                margin-top: 30px;
                padding-top: 10px;
                border-top: 1px solid #ddd;
                font-size: 10px;
                color: #95a5a6;
            }
            
            .signature {
                margin-top: 50px;
                padding-top: 20px;
                border-top: 1px solid #333;
                width: 300px;
            }
            
            .signature-line {
                margin-top: 30px;
            }
            
            .company-info {
                margin-bottom: 20px;
                color: #7f8c8d;
            }
        }
        
        /* Screen styles */
        @media screen {
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                max-width: 1000px;
                margin: 0 auto;
                padding: 20px;
                background: #f5f5f5;
            }
            
            .print-container {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            }
            
            .print-actions {
                margin-bottom: 20px;
                text-align: right;
            }
            
            .print-actions button {
                padding: 10px 20px;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-size: 14px;
                margin-left: 10px;
            }
            
            .btn-print {
                background: #3498db;
                color: white;
            }
            
            .btn-back {
                background: #95a5a6;
                color: white;
            }
            
            .btn-print:hover {
                background: #2980b9;
            }
            
            .btn-back:hover {
                background: #7f8c8d;
            }
        }
        
        /* Common styles for both print and screen */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
        }
        
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #2c3e50;
        }
        
        .header h2 {
            margin: 5px 0;
            font-size: 18px;
            color: #7f8c8d;
        }
        
        .header .date {
            font-size: 14px;
            color: #95a5a6;
            margin-top: 5px;
        }
        
        .summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-top: 15px;
        }
        
        .summary-item {
            background: white;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .summary-number {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin: 10px 0;
        }
        
        .summary-label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
        }
        
        .section-title {
            background: #3498db;
            color: white;
            padding: 10px 15px;
            margin: 20px 0 10px 0;
            border-radius: 3px;
            font-size: 16px;
        }
        
        .total-row {
            background: #f1f8e9;
            font-weight: bold;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            font-size: 10px;
            color: #95a5a6;
        }
        
        .signature {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #333;
            width: 300px;
        }
        
        .signature-line {
            margin-top: 30px;
        }
        
        .company-info {
            margin-bottom: 20px;
            color: #7f8c8d;
        }
        
        .no-data {
            text-align: center;
            padding: 20px;
            color: #95a5a6;
            font-style: italic;
        }
        
        .profit {
            color: #27ae60;
            font-weight: bold;
        }
        
        .loss {
            color: #e74c3c;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="print-container">
    <!-- Print Actions (only on screen) -->
    <div class="print-actions no-print">
        <button class="btn-back" onclick="window.location.href='employee.php'">
            <i class="fas fa-arrow-left"></i> Back
        </button>
        <button class="btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> Print Report
        </button>
    </div>
</div>

        
        <!-- Report Header -->
        <div class="header">
            <h1>PhoneStock Pro</h1>
            <h2>Daily Activity Report</h2>
            <div class="date">Report Date: <?php echo $today_formatted; ?></div>
            <div class="company-info">
                Generated by: <?php echo htmlspecialchars($user_name); ?> (Employee ID: <?php echo $user_id; ?>)<br>
                Generated on: <?php echo date('F j, Y, g:i a'); ?>
            </div>
        </div>
        
        <!-- Summary Section -->
        <div class="summary">
            <h3>Today's Summary</h3>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-number"><?php echo $total_items; ?></div>
                    <div class="summary-label">Total Items Added</div>
                </div>
                <div class="summary-item">
                    <div class="summary-number"><?php echo $phone_stats['phone_count'] ?? 0; ?></div>
                    <div class="summary-label">Phones Added</div>
                </div>
                <div class="summary-item">
                    <div class="summary-number"><?php echo $accessory_stats['accessory_count'] ?? 0; ?></div>
                    <div class="summary-label">Accessory Types</div>
                </div>
                <div class="summary-item">
                    <div class="summary-number"><?php echo $total_quantity; ?></div>
                    <div class="summary-label">Total Accessory Qty</div>
                </div>
            </div>
            
            <div class="summary-grid" style="margin-top: 20px;">
                <div class="summary-item">
                    <div class="summary-number"><?php echo number_format($total_buying, 2); ?> XAF</div>
                    <div class="summary-label">Total Buying Price</div>
                </div>
                <div class="summary-item">
                    <div class="summary-number"><?php echo number_format($total_selling, 2); ?> XAF</div>
                    <div class="summary-label">Total Selling Price</div>
                </div>
                <div class="summary-item">
                    <?php 
                    $total_profit = $total_selling - $total_buying;
                    $profit_class = $total_profit >= 0 ? 'profit' : 'loss';
                    ?>
                    <div class="summary-number <?php echo $profit_class; ?>">
                        <?php echo number_format($total_profit, 2); ?> XAF
                    </div>
                    <div class="summary-label">Total Potential Profit</div>
                </div>
                <div class="summary-item">
                    <?php 
                    $profit_percentage = $total_buying > 0 ? ($total_profit / $total_buying) * 100 : 0;
                    $percentage_class = $profit_percentage >= 0 ? 'profit' : 'loss';
                    ?>
                    <div class="summary-number <?php echo $percentage_class; ?>">
                        <?php echo number_format($profit_percentage, 2); ?>%
                    </div>
                    <div class="summary-label">Profit Margin</div>
                </div>
            </div>
        </div>
        
        <!-- Phones Section -->
        <div class="section-title">
            <i class="fas fa-mobile-alt"></i> Phones Added Today
        </div>
        
        <?php if (count($today_phones) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Brand</th>
                        <th>Model</th>
                        <th>IMEI</th>
                        <th>Color</th>
                        <th>Memory</th>
                        <th class="text-right">Buying Price</th>
                        <th class="text-right">Selling Price</th>
                        <th class="text-right">Profit</th>
                        <th>Time Added</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    $phone_total_buying = 0;
                    $phone_total_selling = 0;
                    foreach($today_phones as $phone): 
                        $profit = $phone['selling_price'] - $phone['buying_price'];
                        $phone_total_buying += $phone['buying_price'];
                        $phone_total_selling += $phone['selling_price'];
                    ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo htmlspecialchars($phone['brand']); ?></td>
                        <td><?php echo htmlspecialchars($phone['model']); ?></td>
                        <td><code><?php echo substr($phone['iemi_number'], 0, 8) . '...'; ?></code></td>
                        <td><?php echo htmlspecialchars($phone['color']); ?></td>
                        <td><?php echo htmlspecialchars($phone['memory']); ?></td>
                        <td class="text-right"><?php echo number_format($phone['buying_price'], 2); ?> XAF</td>
                        <td class="text-right"><?php echo number_format($phone['selling_price'], 2); ?> XAF</td>
                        <td class="text-right <?php echo $profit >= 0 ? 'profit' : 'loss'; ?>">
                            <?php echo number_format($profit, 2); ?> XAF
                        </td>
                        <td><?php echo date('H:i', strtotime($phone['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="6" class="text-right"><strong>Totals:</strong></td>
                        <td class="text-right"><strong><?php echo number_format($phone_total_buying, 2); ?> XAF</strong></td>
                        <td class="text-right"><strong><?php echo number_format($phone_total_selling, 2); ?> XAF</strong></td>
                        <td class="text-right <?php echo ($phone_total_selling - $phone_total_buying) >= 0 ? 'profit' : 'loss'; ?>">
                            <strong><?php echo number_format($phone_total_selling - $phone_total_buying, 2); ?> XAF</strong>
                        </td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-data">
                <p>No phones were added today.</p>
            </div>
        <?php endif; ?>
        
        <!-- Accessories Section -->
        <div class="section-title">
            <i class="fas fa-box"></i> Accessories Added Today
        </div>
        
        <?php if (count($today_accessories) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Brand</th>
                        <th class="text-center">Quantity</th>
                        <th class="text-right">Buying Price</th>
                        <th class="text-right">Selling Price</th>
                        <th class="text-right">Total Buying</th>
                        <th class="text-right">Total Selling</th>
                        <th class="text-right">Total Profit</th>
                        <th>Time Added</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    $accessory_total_buying = 0;
                    $accessory_total_selling = 0;
                    foreach($today_accessories as $accessory): 
                        $total_item_buying = $accessory['buying_price'] * $accessory['quantity'];
                        $total_item_selling = $accessory['selling_price'] * $accessory['quantity'];
                        $total_item_profit = $total_item_selling - $total_item_buying;
                        
                        $accessory_total_buying += $total_item_buying;
                        $accessory_total_selling += $total_item_selling;
                    ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo htmlspecialchars($accessory['accessory_name']); ?></td>
                        <td><?php echo htmlspecialchars($accessory['category']); ?></td>
                        <td><?php echo htmlspecialchars($accessory['brand']); ?></td>
                        <td class="text-center"><?php echo $accessory['quantity']; ?></td>
                        <td class="text-right"><?php echo number_format($accessory['buying_price'], 2); ?> XAF</td>
                        <td class="text-right"><?php echo number_format($accessory['selling_price'], 2); ?> XAF</td>
                        <td class="text-right"><?php echo number_format($total_item_buying, 2); ?> XAF</td>
                        <td class="text-right"><?php echo number_format($total_item_selling, 2); ?> XAF</td>
                        <td class="text-right <?php echo $total_item_profit >= 0 ? 'profit' : 'loss'; ?>">
                            <?php echo number_format($total_item_profit, 2); ?> XAF
                        </td>
                        <td><?php echo date('H:i', strtotime($accessory['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="7" class="text-right"><strong>Totals:</strong></td>
                        <td class="text-right"><strong><?php echo number_format($accessory_total_buying, 2); ?> XAF</strong></td>
                        <td class="text-right"><strong><?php echo number_format($accessory_total_selling, 2); ?> XAF</strong></td>
                        <td class="text-right <?php echo ($accessory_total_selling - $accessory_total_buying) >= 0 ? 'profit' : 'loss'; ?>">
                            <strong><?php echo number_format($accessory_total_selling - $accessory_total_buying, 2); ?> XAF</strong>
                        </td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-data">
                <p>No accessories were added today.</p>
            </div>
        <?php endif; ?>
        
        <!-- Signature Section -->
        <div class="signature no-print">
            <div class="signature-line">
                <p>Prepared by:</p>
                <p><strong><?php echo htmlspecialchars($user_name); ?></strong></p>
                <p>Employee ID: <?php echo $user_id; ?></p>
            </div>
            <div class="signature-line">
                <p>Date: <?php echo date('F j, Y'); ?></p>
                <p>Signature: _________________________</p>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>PhoneStock Pro - Employee Daily Report | <?php echo date('Y'); ?> | Generated on <?php echo date('Y-m-d H:i:s'); ?></p>
            <p>This is a system-generated report. No signature required for digital copies.</p>
        </div>
    </div>
    
    <script>
        // Auto-print option (only on screen)
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('autoprint')) {
                window.print();
            }
        });
        
        // Add Font Awesome for icons (only on screen)
        if (!window.matchMedia('print').matches) {
            const faLink = document.createElement('link');
            faLink.rel = 'stylesheet';
            faLink.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
            document.head.appendChild(faLink);
        }
    </script>
</body>
</html>