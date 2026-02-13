<?php
// includes/reports.php

require_once 'config/database.php';

class Reports {
    private $db;
    private $user_id;
    
    public function __construct($user_id) {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->user_id = $user_id;
    }
    
    /**
     * Get weekly report data
     */
    public function getWeeklyReport($week_start = null) {
        if (!$week_start) {
            $week_start = date('Y-m-d', strtotime('monday this week'));
        }
        
        $week_end = date('Y-m-d', strtotime('sunday this week'));
        
        $data = [
            'period' => date('M d', strtotime($week_start)) . ' - ' . date('M d, Y', strtotime($week_end)),
            'dates' => [],
            'sales' => [],
            'items' => [],
            'summary' => []
        ];
        
        // Get sales data for the week
        $query = "
            SELECT 
                DATE(sale_date) as sale_day,
                COUNT(*) as total_sales,
                SUM(sale_price) as total_revenue,
                SUM(CASE WHEN item_type = 'phone' THEN 1 ELSE 0 END) as phone_sales,
                SUM(CASE WHEN item_type = 'accessory' THEN 1 ELSE 0 END) as accessory_sales
            FROM sales 
            WHERE sold_by = :user_id 
                AND sale_date BETWEEN :week_start AND :week_end
            GROUP BY DATE(sale_date)
            ORDER BY sale_day ASC
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':week_start', $week_start);
        $stmt->bindParam(':week_end', $week_end);
        $stmt->execute();
        
        $sales_data = $stmt->fetchAll();
        
        // Generate dates for the week
        $current = strtotime($week_start);
        $end = strtotime($week_end);
        
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $day_name = date('D', $current);
            
            $data['dates'][] = [
                'date' => $date,
                'day' => $day_name,
                'display' => date('M d', $current)
            ];
            
            // Initialize sales data for each day
            $found = false;
            foreach ($sales_data as $sale) {
                if ($sale['sale_day'] == $date) {
                    $data['sales'][$date] = [
                        'count' => $sale['total_sales'],
                        'revenue' => $sale['total_revenue'] ?? 0,
                        'phones' => $sale['phone_sales'] ?? 0,
                        'accessories' => $sale['accessory_sales'] ?? 0
                    ];
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $data['sales'][$date] = [
                    'count' => 0,
                    'revenue' => 0,
                    'phones' => 0,
                    'accessories' => 0
                ];
            }
            
            $current = strtotime('+1 day', $current);
        }
        
        // Get items added this week
        $query = "
            SELECT 
                COUNT(*) as total_added,
                SUM(CASE WHEN status = 'in_stock' THEN 1 ELSE 0 END) as still_in_stock,
                SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold
            FROM phones 
            WHERE registered_by = :user_id 
                AND created_at BETWEEN :week_start AND :week_end
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':week_start', $week_start);
        $stmt->bindParam(':week_end', $week_end);
        $stmt->execute();
        $phones_data = $stmt->fetch();
        
        $query = "
            SELECT 
                SUM(quantity) as total_added,
                SUM(CASE WHEN status = 'in_stock' THEN quantity ELSE 0 END) as still_in_stock,
                SUM(CASE WHEN status = 'out_of_stock' THEN 1 ELSE 0 END) as out_of_stock
            FROM accessories 
            WHERE registered_by = :user_id 
                AND created_at BETWEEN :week_start AND :week_end
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':week_start', $week_start);
        $stmt->bindParam(':week_end', $week_end);
        $stmt->execute();
        $accessories_data = $stmt->fetch();
        
        // Get transfers for the week
        $query = "
            SELECT COUNT(*) as total_transfers
            FROM transfers 
            WHERE transferred_by = :user_id 
                AND transfer_date BETWEEN :week_start AND :week_end
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':week_start', $week_start);
        $stmt->bindParam(':week_end', $week_end);
        $stmt->execute();
        $transfers_data = $stmt->fetch();
        
        // Summary data
        $data['summary'] = [
            'phones_added' => $phones_data['total_added'] ?? 0,
            'phones_in_stock' => $phones_data['still_in_stock'] ?? 0,
            'phones_sold' => $phones_data['sold'] ?? 0,
            'accessories_added' => $accessories_data['total_added'] ?? 0,
            'accessories_in_stock' => $accessories_data['still_in_stock'] ?? 0,
            'accessories_out_of_stock' => $accessories_data['out_of_stock'] ?? 0,
            'transfers' => $transfers_data['total_transfers'] ?? 0,
            'total_sales' => array_sum(array_column($data['sales'], 'count')),
            'total_revenue' => array_sum(array_column($data['sales'], 'revenue')),
            'phone_sales' => array_sum(array_column($data['sales'], 'phones')),
            'accessory_sales' => array_sum(array_column($data['sales'], 'accessories'))
        ];
        
        // Get top selling items
        $data['top_items'] = $this->getTopSellingItems($week_start, $week_end);
        
        // Get payment method breakdown
        $data['payment_methods'] = $this->getPaymentMethodBreakdown($week_start, $week_end);
        
        return $data;
    }
    
    /**
     * Get monthly report data
     */
    public function getMonthlyReport($month = null) {
        if (!$month) {
            $month = date('Y-m');
        }
        
        $month_start = date('Y-m-01', strtotime($month));
        $month_end = date('Y-m-t', strtotime($month));
        
        $data = [
            'period' => date('F Y', strtotime($month)),
            'month' => $month,
            'days' => [],
            'sales' => [],
            'summary' => []
        ];
        
        // Get number of days in month
        $days_in_month = date('t', strtotime($month_start));
        
        // Get sales data for the month
        $query = "
            SELECT 
                DAY(sale_date) as sale_day,
                COUNT(*) as total_sales,
                SUM(sale_price) as total_revenue,
                SUM(CASE WHEN item_type = 'phone' THEN 1 ELSE 0 END) as phone_sales,
                SUM(CASE WHEN item_type = 'accessory' THEN quantity ELSE 0 END) as accessory_units,
                COUNT(DISTINCT CASE WHEN item_type = 'accessory' THEN item_id END) as accessory_sales
            FROM sales 
            WHERE sold_by = :user_id 
                AND sale_date BETWEEN :month_start AND :month_end
            GROUP BY DAY(sale_date)
            ORDER BY sale_day ASC
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':month_start', $month_start);
        $stmt->bindParam(':month_end', $month_end);
        $stmt->execute();
        
        $sales_data = $stmt->fetchAll();
        
        // Initialize data for all days
        for ($day = 1; $day <= $days_in_month; $day++) {
            $date = date('Y-m-d', strtotime($month . '-' . str_pad($day, 2, '0', STR_PAD_LEFT)));
            $data['days'][$day] = [
                'date' => $date,
                'day_name' => date('D', strtotime($date)),
                'display' => date('M j', strtotime($date))
            ];
            
            // Find sales for this day
            $found = false;
            foreach ($sales_data as $sale) {
                if ($sale['sale_day'] == $day) {
                    $data['sales'][$day] = [
                        'count' => $sale['total_sales'],
                        'revenue' => $sale['total_revenue'] ?? 0,
                        'phones' => $sale['phone_sales'] ?? 0,
                        'accessory_units' => $sale['accessory_units'] ?? 0,
                        'accessory_sales' => $sale['accessory_sales'] ?? 0
                    ];
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $data['sales'][$day] = [
                    'count' => 0,
                    'revenue' => 0,
                    'phones' => 0,
                    'accessory_units' => 0,
                    'accessory_sales' => 0
                ];
            }
        }
        
        // Get items added this month
        $query = "
            SELECT 
                COUNT(*) as total_added,
                SUM(CASE WHEN status = 'in_stock' THEN 1 ELSE 0 END) as in_stock,
                SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold,
                SUM(CASE WHEN status = 'transferred' THEN 1 ELSE 0 END) as transferred,
                SUM(CASE WHEN status = 'unavailable' THEN 1 ELSE 0 END) as unavailable
            FROM phones 
            WHERE registered_by = :user_id 
                AND created_at BETWEEN :month_start AND :month_end
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':month_start', $month_start);
        $stmt->bindParam(':month_end', $month_end);
        $stmt->execute();
        $phones_data = $stmt->fetch();
        
        $query = "
            SELECT 
                SUM(quantity) as total_added,
                SUM(CASE WHEN status = 'in_stock' THEN quantity ELSE 0 END) as in_stock,
                SUM(CASE WHEN status = 'out_of_stock' THEN quantity ELSE 0 END) as out_of_stock,
                COUNT(CASE WHEN status = 'unavailable' THEN 1 END) as unavailable
            FROM accessories 
            WHERE registered_by = :user_id 
                AND created_at BETWEEN :month_start AND :month_end
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':month_start', $month_start);
        $stmt->bindParam(':month_end', $month_end);
        $stmt->execute();
        $accessories_data = $stmt->fetch();
        
        // Get transfers for the month
        $query = "
            SELECT 
                COUNT(*) as total_transfers,
                SUM(CASE WHEN item_type = 'phone' THEN 1 ELSE 0 END) as phone_transfers,
                SUM(CASE WHEN item_type = 'accessory' THEN quantity ELSE 0 END) as accessory_transfers
            FROM transfers 
            WHERE transferred_by = :user_id 
                AND transfer_date BETWEEN :month_start AND :month_end
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':month_start', $month_start);
        $stmt->bindParam(':month_end', $month_end);
        $stmt->execute();
        $transfers_data = $stmt->fetch();
        
        // Summary data
        $data['summary'] = [
            'phones_added' => $phones_data['total_added'] ?? 0,
            'phones_in_stock' => $phones_data['in_stock'] ?? 0,
            'phones_sold' => $phones_data['sold'] ?? 0,
            'phones_transferred' => $phones_data['transferred'] ?? 0,
            'phones_unavailable' => $phones_data['unavailable'] ?? 0,
            'accessories_added' => $accessories_data['total_added'] ?? 0,
            'accessories_in_stock' => $accessories_data['in_stock'] ?? 0,
            'accessories_out_of_stock' => $accessories_data['out_of_stock'] ?? 0,
            'accessories_unavailable' => $accessories_data['unavailable'] ?? 0,
            'transfers_total' => $transfers_data['total_transfers'] ?? 0,
            'phone_transfers' => $transfers_data['phone_transfers'] ?? 0,
            'accessory_transfers' => $transfers_data['accessory_transfers'] ?? 0,
            'total_sales' => array_sum(array_column($data['sales'], 'count')),
            'total_revenue' => array_sum(array_column($data['sales'], 'revenue')),
            'phone_sales' => array_sum(array_column($data['sales'], 'phones')),
            'accessory_sales' => array_sum(array_column($data['sales'], 'accessory_sales')),
            'accessory_units_sold' => array_sum(array_column($data['sales'], 'accessory_units')),
            'days_with_sales' => count(array_filter($data['sales'], function($sale) {
                return $sale['count'] > 0;
            }))
        ];
        
        // Get daily averages
        $days_with_sales = $data['summary']['days_with_sales'];
        $data['summary']['avg_daily_sales'] = $days_with_sales > 0 ? 
            round($data['summary']['total_sales'] / $days_with_sales, 1) : 0;
        $data['summary']['avg_daily_revenue'] = $days_with_sales > 0 ? 
            round($data['summary']['total_revenue'] / $days_with_sales, 0) : 0;
        
        // Get top selling items
        $data['top_items'] = $this->getTopSellingItems($month_start, $month_end, 10);
        
        // Get payment method breakdown
        $data['payment_methods'] = $this->getPaymentMethodBreakdown($month_start, $month_end);
        
        // Get best performing days
        $data['best_days'] = $this->getBestPerformingDays($data);
        
        return $data;
    }
    
    /**
     * Get top selling items for a period
     */
    private function getTopSellingItems($start_date, $end_date, $limit = 5) {
        $query = "
            (SELECT 
                'phone' as type,
                p.phone_id as id,
                CONCAT(p.brand, ' ', p.model) as name,
                COUNT(s.sale_id) as sales_count,
                SUM(s.sale_price) as total_revenue,
                AVG(s.sale_price) as avg_price,
                MAX(s.sale_date) as last_sale_date
            FROM sales s
            JOIN phones p ON p.phone_id = s.item_id
            WHERE s.sold_by = :user_id 
                AND s.item_type = 'phone'
                AND s.sale_date BETWEEN :start_date AND :end_date
            GROUP BY p.phone_id
            ORDER BY sales_count DESC
            LIMIT :limit)
            
            UNION ALL
            
            (SELECT 
                'accessory' as type,
                a.accessory_id as id,
                a.accessory_name as name,
                COUNT(s.sale_id) as sales_count,
                SUM(s.sale_price) as total_revenue,
                AVG(s.sale_price / s.quantity) as avg_price,
                MAX(s.sale_date) as last_sale_date
            FROM sales s
            JOIN accessories a ON a.accessory_id = s.item_id
            WHERE s.sold_by = :user_id2 
                AND s.item_type = 'accessory'
                AND s.sale_date BETWEEN :start_date2 AND :end_date2
            GROUP BY a.accessory_id
            ORDER BY sales_count DESC
            LIMIT :limit2)
            
            ORDER BY sales_count DESC
            LIMIT :final_limit
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':start_date', $start_date);
        $stmt->bindParam(':end_date', $end_date);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        
        $stmt->bindParam(':user_id2', $this->user_id);
        $stmt->bindParam(':start_date2', $start_date);
        $stmt->bindParam(':end_date2', $end_date);
        $stmt->bindParam(':limit2', $limit, PDO::PARAM_INT);
        
        $stmt->bindParam(':final_limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get payment method breakdown
     */
    private function getPaymentMethodBreakdown($start_date, $end_date) {
        $query = "
            SELECT 
                payment_method,
                COUNT(*) as transaction_count,
                SUM(sale_price) as total_amount,
                ROUND(AVG(sale_price), 2) as avg_amount
            FROM sales 
            WHERE sold_by = :user_id 
                AND sale_date BETWEEN :start_date AND :end_date
            GROUP BY payment_method
            ORDER BY total_amount DESC
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':start_date', $start_date);
        $stmt->bindParam(':end_date', $end_date);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get best performing days
     */
    private function getBestPerformingDays($month_data) {
        $best_days = [];
        
        foreach ($month_data['sales'] as $day => $sales) {
            if ($sales['revenue'] > 0) {
                $best_days[$day] = [
                    'day' => $day,
                    'date' => $month_data['days'][$day]['date'],
                    'display' => $month_data['days'][$day]['display'],
                    'revenue' => $sales['revenue'],
                    'sales_count' => $sales['count']
                ];
            }
        }
        
        // Sort by revenue descending
        usort($best_days, function($a, $b) {
            return $b['revenue'] <=> $a['revenue'];
        });
        
        return array_slice($best_days, 0, 5); // Top 5 days
    }
    
    /**
     * Generate HTML report
     */
    public function generateHTMLReport($type, $period = null) {
        if ($type === 'weekly') {
            $data = $this->getWeeklyReport($period);
            return $this->generateWeeklyHTML($data);
        } elseif ($type === 'monthly') {
            $data = $this->getMonthlyReport($period);
            return $this->generateMonthlyHTML($data);
        }
        
        return "Invalid report type";
    }
    
    /**
     * Generate weekly HTML report
     */
    private function generateWeeklyHTML($data) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Weekly Sales Report - <?php echo $data['period']; ?></title>
            <style>
                body {
                    font-family: 'Arial', sans-serif;
                    margin: 0;
                    padding: 20px;
                    background: #f5f5f5;
                }
                .report-container {
                    max-width: 1000px;
                    margin: 0 auto;
                    background: white;
                    padding: 30px;
                    border-radius: 10px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    text-align: center;
                    border-bottom: 3px solid #3498db;
                    padding-bottom: 20px;
                    margin-bottom: 30px;
                }
                .header h1 {
                    color: #2c3e50;
                    margin: 0;
                }
                .header .period {
                    color: #7f8c8d;
                    font-size: 18px;
                    margin-top: 5px;
                }
                .summary-cards {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }
                .card {
                    background: #fff;
                    border-radius: 8px;
                    padding: 20px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                    border-left: 4px solid #3498db;
                }
                .card.revenue { border-left-color: #2ecc71; }
                .card.sales { border-left-color: #e74c3c; }
                .card.items { border-left-color: #f39c12; }
                .card-value {
                    font-size: 28px;
                    font-weight: bold;
                    color: #2c3e50;
                }
                .card-label {
                    font-size: 14px;
                    color: #7f8c8d;
                    margin-top: 5px;
                }
                .section {
                    margin-bottom: 30px;
                }
                .section-title {
                    color: #2c3e50;
                    border-bottom: 2px solid #ecf0f1;
                    padding-bottom: 10px;
                    margin-bottom: 20px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                th {
                    background: #3498db;
                    color: white;
                    padding: 12px;
                    text-align: left;
                }
                td {
                    padding: 12px;
                    border-bottom: 1px solid #ecf0f1;
                }
                tr:hover {
                    background: #f8f9fa;
                }
                .chart-container {
                    height: 300px;
                    margin: 20px 0;
                }
                .footer {
                    text-align: center;
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 1px solid #ecf0f1;
                    color: #7f8c8d;
                    font-size: 14px;
                }
                .no-data {
                    text-align: center;
                    padding: 40px;
                    color: #7f8c8d;
                    font-style: italic;
                }
                .badge {
                    display: inline-block;
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-size: 12px;
                    font-weight: bold;
                }
                .badge.phone { background: #3498db; color: white; }
                .badge.accessory { background: #9b59b6; color: white; }
                @media print {
                    body { background: white; }
                    .report-container { box-shadow: none; }
                    .no-print { display: none; }
                }
            </style>
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        </head>
        <body>
            <div class="report-container">
                <div class="header">
                    <h1>Weekly Sales Report</h1>
                    <div class="period"><?php echo $data['period']; ?></div>
                    <div style="margin-top: 10px; color: #7f8c8d;">
                        Generated on: <?php echo date('F j, Y, h:i A'); ?>
                    </div>
                </div>
                
                <!-- Summary Cards -->
                <div class="summary-cards">
                    <div class="card revenue">
                        <div class="card-value">XAF <?php echo number_format($data['summary']['total_revenue'], 2); ?></div>
                        <div class="card-label">Total Revenue</div>
                    </div>
                    <div class="card sales">
                        <div class="card-value"><?php echo $data['summary']['total_sales']; ?></div>
                        <div class="card-label">Total Sales</div>
                    </div>
                    <div class="card items">
                        <div class="card-value"><?php echo $data['summary']['phone_sales']; ?></div>
                        <div class="card-label">Phones Sold</div>
                    </div>
                    <div class="card">
                        <div class="card-value"><?php echo $data['summary']['accessory_sales']; ?></div>
                        <div class="card-label">Accessory Sales</div>
                    </div>
                </div>
                
                <!-- Daily Sales Breakdown -->
                <div class="section">
                    <h3 class="section-title">Daily Sales Breakdown</h3>
                    <?php if ($data['summary']['total_sales'] > 0): ?>
                        <div class="chart-container">
                            <canvas id="dailySalesChart"></canvas>
                        </div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Day</th>
                                    <th>Sales Count</th>
                                    <th>Revenue</th>
                                    <th>Phones</th>
                                    <th>Accessories</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data['dates'] as $date_info): 
                                    $date = $date_info['date'];
                                    $sales = $data['sales'][$date];
                                ?>
                                <tr>
                                    <td><?php echo $date_info['display']; ?></td>
                                    <td><?php echo $date_info['day']; ?></td>
                                    <td><?php echo $sales['count']; ?></td>
                                    <td>XAF <?php echo number_format($sales['revenue'], 2); ?></td>
                                    <td><?php echo $sales['phones']; ?></td>
                                    <td><?php echo $sales['accessories']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr style="font-weight: bold; background: #f8f9fa;">
                                    <td colspan="2">TOTAL</td>
                                    <td><?php echo $data['summary']['total_sales']; ?></td>
                                    <td>XAF <?php echo number_format($data['summary']['total_revenue'], 2); ?></td>
                                    <td><?php echo $data['summary']['phone_sales']; ?></td>
                                    <td><?php echo $data['summary']['accessory_sales']; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">No sales data for this week</div>
                    <?php endif; ?>
                </div>
                
                <!-- Top Selling Items -->
                <?php if (!empty($data['top_items'])): ?>
                <div class="section">
                    <h3 class="section-title">Top Selling Items</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Type</th>
                                <th>Sales Count</th>
                                <th>Total Revenue</th>
                                <th>Avg. Price</th>
                                <th>Last Sale</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['top_items'] as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['name']); ?></td>
                                <td>
                                    <span class="badge <?php echo $item['type']; ?>">
                                        <?php echo ucfirst($item['type']); ?>
                                    </span>
                                </td>
                                <td><?php echo $item['sales_count']; ?></td>
                                <td>XAF <?php echo number_format($item['total_revenue'], 2); ?></td>
                                <td>XAF <?php echo number_format($item['avg_price'], 2); ?></td>
                                <td><?php echo date('M d', strtotime($item['last_sale_date'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Payment Methods -->
                <?php if (!empty($data['payment_methods'])): ?>
                <div class="section">
                    <h3 class="section-title">Payment Method Breakdown</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Payment Method</th>
                                <th>Transactions</th>
                                <th>Total Amount</th>
                                <th>Avg. Transaction</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['payment_methods'] as $method): ?>
                            <tr>
                                <td><?php echo ucfirst(str_replace('_', ' ', $method['payment_method'])); ?></td>
                                <td><?php echo $method['transaction_count']; ?></td>
                                <td>XAF <?php echo number_format($method['total_amount'], 2); ?></td>
                                <td>XAF <?php echo number_format($method['avg_amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Activity Summary -->
                <div class="section">
                    <h3 class="section-title">Activity Summary</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                        <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <div style="font-size: 24px; font-weight: bold; color: #3498db;">
                                <?php echo $data['summary']['phones_added']; ?>
                            </div>
                            <div style="font-size: 14px; color: #7f8c8d;">Phones Added</div>
                        </div>
                        <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <div style="font-size: 24px; font-weight: bold; color: #9b59b6;">
                                <?php echo $data['summary']['accessories_added']; ?>
                            </div>
                            <div style="font-size: 14px; color: #7f8c8d;">Accessories Added</div>
                        </div>
                        <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <div style="font-size: 24px; font-weight: bold; color: #e74c3c;">
                                <?php echo $data['summary']['transfers']; ?>
                            </div>
                            <div style="font-size: 14px; color: #7f8c8d;">Transfers</div>
                        </div>
                    </div>
                </div>
                
                <div class="footer">
                    <p>PhoneStock Pro - Employee Sales Report</p>
                    <p>This report is generated automatically. For questions, contact your supervisor.</p>
                    <div class="no-print" style="margin-top: 20px;">
                        <button onclick="window.print()" style="padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer;">
                            Print Report
                        </button>
                    </div>
                </div>
            </div>
            
            <script>
                <?php if ($data['summary']['total_sales'] > 0): ?>
                // Daily Sales Chart
                const dailyLabels = <?php echo json_encode(array_column($data['dates'], 'display')); ?>;
                const dailyRevenue = <?php echo json_encode(array_column($data['sales'], 'revenue')); ?>;
                const dailySales = <?php echo json_encode(array_column($data['sales'], 'count')); ?>;
                
                const ctx = document.getElementById('dailySalesChart').getContext('2d');
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: dailyLabels,
                        datasets: [{
                            label: 'Revenue (XAF)',
                            data: dailyRevenue,
                            backgroundColor: 'rgba(52, 152, 219, 0.7)',
                            borderColor: 'rgba(52, 152, 219, 1)',
                            borderWidth: 1,
                            yAxisID: 'y'
                        }, {
                            label: 'Sales Count',
                            data: dailySales,
                            backgroundColor: 'rgba(231, 76, 60, 0.7)',
                            borderColor: 'rgba(231, 76, 60, 1)',
                            borderWidth: 1,
                            type: 'line',
                            yAxisID: 'y1'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Revenue (XAF)'
                                },
                                beginAtZero: true
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Sales Count'
                                },
                                beginAtZero: true,
                                grid: {
                                    drawOnChartArea: false
                                }
                            }
                        }
                    }
                });
                <?php endif; ?>
            </script>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate monthly HTML report
     */
    private function generateMonthlyHTML($data) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Monthly Sales Report - <?php echo $data['period']; ?></title>
            <style>
                body {
                    font-family: 'Arial', sans-serif;
                    margin: 0;
                    padding: 20px;
                    background: #f5f5f5;
                }
                .report-container {
                    max-width: 1200px;
                    margin: 0 auto;
                    background: white;
                    padding: 30px;
                    border-radius: 10px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    text-align: center;
                    border-bottom: 3px solid #9b59b6;
                    padding-bottom: 20px;
                    margin-bottom: 30px;
                }
                .header h1 {
                    color: #2c3e50;
                    margin: 0;
                }
                .header .period {
                    color: #7f8c8d;
                    font-size: 18px;
                    margin-top: 5px;
                }
                .summary-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                    gap: 15px;
                    margin-bottom: 30px;
                }
                .summary-item {
                    background: #fff;
                    border-radius: 8px;
                    padding: 15px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                    text-align: center;
                }
                .summary-item .value {
                    font-size: 24px;
                    font-weight: bold;
                    color: #2c3e50;
                    margin-bottom: 5px;
                }
                .summary-item .label {
                    font-size: 12px;
                    color: #7f8c8d;
                }
                .section {
                    margin-bottom: 30px;
                }
                .section-title {
                    color: #2c3e50;
                    border-bottom: 2px solid #ecf0f1;
                    padding-bottom: 10px;
                    margin-bottom: 20px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                th {
                    background: #9b59b6;
                    color: white;
                    padding: 12px;
                    text-align: left;
                }
                td {
                    padding: 10px;
                    border-bottom: 1px solid #ecf0f1;
                    font-size: 14px;
                }
                tr:hover {
                    background: #f8f9fa;
                }
                .month-calendar {
                    display: grid;
                    grid-template-columns: repeat(7, 1fr);
                    gap: 5px;
                    margin: 20px 0;
                }
                .calendar-day {
                    border: 1px solid #ddd;
                    padding: 10px;
                    min-height: 80px;
                    position: relative;
                }
                .calendar-day.empty {
                    background: #f8f9fa;
                    border: none;
                }
                .calendar-day .day-number {
                    font-weight: bold;
                    color: #2c3e50;
                }
                .calendar-day .sales-info {
                    font-size: 12px;
                    margin-top: 5px;
                }
                .calendar-day .revenue {
                    color: #2ecc71;
                    font-weight: bold;
                }
                .calendar-day.has-sales {
                    background: #e8f6f3;
                    border-color: #2ecc71;
                }
                .chart-container {
                    height: 300px;
                    margin: 20px 0;
                }
                .footer {
                    text-align: center;
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 1px solid #ecf0f1;
                    color: #7f8c8d;
                    font-size: 14px;
                }
                .no-data {
                    text-align: center;
                    padding: 40px;
                    color: #7f8c8d;
                    font-style: italic;
                }
                .badge {
                    display: inline-block;
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-size: 12px;
                    font-weight: bold;
                }
                .badge.phone { background: #3498db; color: white; }
                .badge.accessory { background: #9b59b6; color: white; }
                .highlight {
                    background: #fff9e6;
                    padding: 15px;
                    border-radius: 8px;
                    border-left: 4px solid #f39c12;
                    margin: 15px 0;
                }
                @media print {
                    body { background: white; }
                    .report-container { box-shadow: none; }
                    .no-print { display: none; }
                }
            </style>
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        </head>
        <body>
            <div class="report-container">
                <div class="header">
                    <h1>Monthly Sales Report</h1>
                    <div class="period"><?php echo $data['period']; ?></div>
                    <div style="margin-top: 10px; color: #7f8c8d;">
                        Generated on: <?php echo date('F j, Y, h:i A'); ?>
                    </div>
                </div>
                
                <!-- Key Metrics -->
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="value">XAF <?php echo number_format($data['summary']['total_revenue'], 0); ?></div>
                        <div class="label">Total Revenue</div>
                    </div>
                    <div class="summary-item">
                        <div class="value"><?php echo $data['summary']['total_sales']; ?></div>
                        <div class="label">Total Sales</div>
                    </div>
                    <div class="summary-item">
                        <div class="value"><?php echo $data['summary']['phone_sales']; ?></div>
                        <div class="label">Phones Sold</div>
                    </div>
                    <div class="summary-item">
                        <div class="value"><?php echo $data['summary']['accessory_sales']; ?></div>
                        <div class="label">Accessory Sales</div>
                    </div>
                    <div class="summary-item">
                        <div class="value"><?php echo $data['summary']['accessory_units_sold']; ?></div>
                        <div class="label">Accessory Units</div>
                    </div>
                    <div class="summary-item">
                        <div class="value"><?php echo $data['summary']['days_with_sales']; ?></div>
                        <div class="label">Active Days</div>
                    </div>
                    <div class="summary-item">
                        <div class="value"><?php echo $data['summary']['avg_daily_sales']; ?></div>
                        <div class="label">Avg. Daily Sales</div>
                    </div>
                    <div class="summary-item">
                        <div class="value">XAF <?php echo number_format($data['summary']['avg_daily_revenue'], 0); ?></div>
                        <div class="label">Avg. Daily Revenue</div>
                    </div>
                </div>
                
                <!-- Performance Highlights -->
                <div class="highlight">
                    <h4 style="margin-top: 0; color: #f39c12;">Performance Highlights</h4>
                    <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                        <div>
                            <strong>Best Revenue Day:</strong>
                            <?php if (!empty($data['best_days'])): ?>
                            <?php $best_day = $data['best_days'][0]; ?>
                            <?php echo $best_day['display']; ?> - XAF <?php echo number_format($best_day['revenue'], 2); ?>
                            (<?php echo $best_day['sales_count']; ?> sales)
                            <?php else: ?>
                            No sales data
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong>Conversion Rate:</strong>
                            <?php 
                            $phones_added = $data['summary']['phones_added'];
                            $phones_sold = $data['summary']['phones_sold'];
                            $conversion = $phones_added > 0 ? round(($phones_sold / $phones_added) * 100, 1) : 0;
                            ?>
                            <?php echo $conversion; ?>%
                        </div>
                    </div>
                </div>
                
                <!-- Monthly Calendar View -->
                <div class="section">
                    <h3 class="section-title">Monthly Overview</h3>
                    <?php if ($data['summary']['total_sales'] > 0): ?>
                        <div class="chart-container">
                            <canvas id="monthlyTrendChart"></canvas>
                        </div>
                        
                        <div class="month-calendar">
                            <?php 
                            // Find first day of month
                            $first_day = date('N', strtotime($data['days'][1]['date']));
                            
                            // Empty cells for days before month starts
                            for ($i = 1; $i < $first_day; $i++) {
                                echo '<div class="calendar-day empty"></div>';
                            }
                            
                            // Calendar days
                            foreach ($data['days'] as $day => $day_info) {
                                $sales = $data['sales'][$day];
                                $has_sales = $sales['revenue'] > 0;
                                $class = $has_sales ? 'has-sales' : '';
                                ?>
                                <div class="calendar-day <?php echo $class; ?>">
                                    <div class="day-number"><?php echo $day; ?></div>
                                    <div class="day-name"><?php echo $day_info['day_name']; ?></div>
                                    <?php if ($has_sales): ?>
                                    <div class="sales-info">
                                        <div>Sales: <?php echo $sales['count']; ?></div>
                                        <div class="revenue">XAF <?php echo number_format($sales['revenue'], 0); ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                        
                        <!-- Daily Sales Table -->
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Day</th>
                                    <th>Sales</th>
                                    <th>Revenue</th>
                                    <th>Phones</th>
                                    <th>Accessories</th>
                                    <th>Accessory Units</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data['days'] as $day => $day_info): 
                                    $sales = $data['sales'][$day];
                                ?>
                                <tr>
                                    <td><?php echo $day_info['display']; ?></td>
                                    <td><?php echo $day_info['day_name']; ?></td>
                                    <td><?php echo $sales['count']; ?></td>
                                    <td>XAF <?php echo number_format($sales['revenue'], 2); ?></td>
                                    <td><?php echo $sales['phones']; ?></td>
                                    <td><?php echo $sales['accessory_sales']; ?></td>
                                    <td><?php echo $sales['accessory_units']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr style="font-weight: bold; background: #f8f9fa;">
                                    <td colspan="2">MONTHLY TOTAL</td>
                                    <td><?php echo $data['summary']['total_sales']; ?></td>
                                    <td>XAF <?php echo number_format($data['summary']['total_revenue'], 2); ?></td>
                                    <td><?php echo $data['summary']['phone_sales']; ?></td>
                                    <td><?php echo $data['summary']['accessory_sales']; ?></td>
                                    <td><?php echo $data['summary']['accessory_units_sold']; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">No sales data for this month</div>
                    <?php endif; ?>
                </div>
                
                <!-- Inventory Summary -->
                <div class="section">
                    <h3 class="section-title">Inventory Summary</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <div style="padding: 20px; background: #e8f4fd; border-radius: 8px;">
                            <h4 style="margin-top: 0; color: #3498db;">Phones</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div>
                                    <div style="font-size: 18px; font-weight: bold;"><?php echo $data['summary']['phones_added']; ?></div>
                                    <div style="font-size: 12px; color: #7f8c8d;">Added</div>
                                </div>
                                <div>
                                    <div style="font-size: 18px; font-weight: bold; color: #2ecc71;"><?php echo $data['summary']['phones_sold']; ?></div>
                                    <div style="font-size: 12px; color: #7f8c8d;">Sold</div>
                                </div>
                                <div>
                                    <div style="font-size: 18px; font-weight: bold; color: #f39c12;"><?php echo $data['summary']['phones_in_stock']; ?></div>
                                    <div style="font-size: 12px; color: #7f8c8d;">In Stock</div>
                                </div>
                                <div>
                                    <div style="font-size: 18px; font-weight: bold; color: #e74c3c;"><?php echo $data['summary']['phones_transferred']; ?></div>
                                    <div style="font-size: 12px; color: #7f8c8d;">Transferred</div>
                                </div>
                            </div>
                        </div>
                        
                        <div style="padding: 20px; background: #f5e8fd; border-radius: 8px;">
                            <h4 style="margin-top: 0; color: #9b59b6;">Accessories</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div>
                                    <div style="font-size: 18px; font-weight: bold;"><?php echo $data['summary']['accessories_added']; ?></div>
                                    <div style="font-size: 12px; color: #7f8c8d;">Added</div>
                                </div>
                                <div>
                                    <div style="font-size: 18px; font-weight: bold; color: #2ecc71;"><?php echo $data['summary']['accessories_in_stock']; ?></div>
                                    <div style="font-size: 12px; color: #7f8c8d;">In Stock</div>
                                </div>
                                <div>
                                    <div style="font-size: 18px; font-weight: bold; color: #e74c3c;"><?php echo $data['summary']['accessories_out_of_stock']; ?></div>
                                    <div style="font-size: 12px; color: #7f8c8d;">Out of Stock</div>
                                </div>
                                <div>
                                    <div style="font-size: 18px; font-weight: bold; color: #95a5a6;"><?php echo $data['summary']['accessories_unavailable']; ?></div>
                                    <div style="font-size: 12px; color: #7f8c8d;">Unavailable</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Top Selling Items -->
                <?php if (!empty($data['top_items'])): ?>
                <div class="section">
                    <h3 class="section-title">Top Selling Items</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Item</th>
                                <th>Type</th>
                                <th>Sales Count</th>
                                <th>Total Revenue</th>
                                <th>Avg. Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; ?>
                            <?php foreach ($data['top_items'] as $item): ?>
                            <tr>
                                <td>#<?php echo $rank++; ?></td>
                                <td><?php echo htmlspecialchars($item['name']); ?></td>
                                <td>
                                    <span class="badge <?php echo $item['type']; ?>">
                                        <?php echo ucfirst($item['type']); ?>
                                    </span>
                                </td>
                                <td><?php echo $item['sales_count']; ?></td>
                                <td>XAF <?php echo number_format($item['total_revenue'], 2); ?></td>
                                <td>XAF <?php echo number_format($item['avg_price'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Transfer Activity -->
                <div class="section">
                    <h3 class="section-title">Transfer Activity</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                        <div style="text-align: center; padding: 15px; background: #fef5e7; border-radius: 8px;">
                            <div style="font-size: 24px; font-weight: bold; color: #f39c12;">
                                <?php echo $data['summary']['transfers_total']; ?>
                            </div>
                            <div style="font-size: 14px; color: #7f8c8d;">Total Transfers</div>
                        </div>
                        <div style="text-align: center; padding: 15px; background: #e8f6f3; border-radius: 8px;">
                            <div style="font-size: 24px; font-weight: bold; color: #1abc9c;">
                                <?php echo $data['summary']['phone_transfers']; ?>
                            </div>
                            <div style="font-size: 14px; color: #7f8c8d;">Phone Transfers</div>
                        </div>
                        <div style="text-align: center; padding: 15px; background: #f4ecf7; border-radius: 8px;">
                            <div style="font-size: 24px; font-weight: bold; color: #9b59b6;">
                                <?php echo $data['summary']['accessory_transfers']; ?>
                            </div>
                            <div style="font-size: 14px; color: #7f8c8d;">Accessory Units</div>
                        </div>
                    </div>
                </div>
                
                <div class="footer">
                    <p>PhoneStock Pro - Monthly Performance Report</p>
                    <p>Employee: <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'N/A'); ?> | 
                       Employee ID: <?php echo $_SESSION['user_id'] ?? 'N/A'; ?></p>
                    <div class="no-print" style="margin-top: 20px;">
                        <button onclick="window.print()" style="padding: 10px 20px; background: #9b59b6; color: white; border: none; border-radius: 5px; cursor: pointer;">
                            Print Report
                        </button>
                        <button onclick="downloadPDF()" style="padding: 10px 20px; background: #2ecc71; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">
                            Download as PDF
                        </button>
                    </div>
                </div>
            </div>
            
            <script>
                <?php if ($data['summary']['total_sales'] > 0): ?>
                // Monthly Trend Chart
                const monthlyLabels = <?php echo json_encode(array_column($data['days'], 'display')); ?>;
                const monthlyRevenue = <?php echo json_encode(array_column($data['sales'], 'revenue')); ?>;
                
                const monthlyCtx = document.getElementById('monthlyTrendChart').getContext('2d');
                new Chart(monthlyCtx, {
                    type: 'line',
                    data: {
                        labels: monthlyLabels,
                        datasets: [{
                            label: 'Daily Revenue (XAF)',
                            data: monthlyRevenue,
                            borderColor: 'rgba(155, 89, 182, 1)',
                            backgroundColor: 'rgba(155, 89, 182, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Revenue (XAF)'
                                }
                            }
                        }
                    }
                });
                
                function downloadPDF() {
                    // This would require a PDF generation library
                    alert('PDF download feature requires server-side implementation.');
                    // For production, you would use a library like TCPDF, Dompdf, or mpdf
                    // window.location.href = 'generate_pdf.php?type=monthly&month=<?php echo $data['month']; ?>';
                }
                <?php endif; ?>
            </script>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}
?>