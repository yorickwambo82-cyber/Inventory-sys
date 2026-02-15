<?php
require_once 'includes/session.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

if (!isset($_GET['id'])) {
    header('Location: admin.php');
    exit();
}

$accessory_id = $_GET['id'];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT a.*, u.full_name as registered_by_name 
              FROM accessories a 
              LEFT JOIN users u ON a.registered_by = u.user_id 
              WHERE a.accessory_id = :accessory_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':accessory_id', $accessory_id);
    $stmt->execute();
    $accessory = $stmt->fetch();
    
    if (!$accessory) {
        header('Location: admin.php');
        exit();
    }
    
} catch(Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accessory Details - PhoneStock Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Accessory Details</h1>
            <a href="admin.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><?php echo htmlspecialchars($accessory['accessory_name']); ?></h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-bordered">
                            <tr>
                                <th width="40%">Accessory Name:</th>
                                <td><?php echo htmlspecialchars($accessory['accessory_name']); ?></td>
                            </tr>
                            <tr>
                                <th>Category:</th>
                                <td><?php echo htmlspecialchars($accessory['category']); ?></td>
                            </tr>
                            <tr>
                                <th>Brand:</th>
                                <td><?php echo htmlspecialchars($accessory['brand']); ?></td>
                            </tr>
                            <tr>
                                <th>Quantity:</th>
                                <td>
                                    <span class="badge bg-<?php echo $accessory['quantity'] < 5 ? 'warning' : 'primary'; ?>">
                                        <?php echo $accessory['quantity']; ?>
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-bordered">
                            <tr>
                                <th width="40%">Buying Price:</th>
                                <td><?php echo number_format($accessory['buying_price'], 0); ?> XAF</td>
                            </tr>
                            <tr>
                                <th>Selling Price:</th>
                                <td><?php echo number_format($accessory['selling_price'], 0); ?> XAF</td>
                            </tr>
                            <tr>
                                <th>Total Value:</th>
                                <td><?php echo number_format($accessory['buying_price'] * $accessory['quantity'], 0); ?> XAF</td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $accessory['status'] == 'in_stock' ? 'success' : 
                                             ($accessory['status'] == 'sold' ? 'warning' : 'danger'); 
                                    ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $accessory['status'])); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Registered By:</th>
                                <td><?php echo htmlspecialchars($accessory['registered_by_name']); ?></td>
                            </tr>
                            <tr>
                                <th>Added On:</th>
                                <td><?php echo date('F d, Y H:i:s', strtotime($accessory['created_at'])); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <?php if (!empty($accessory['notes'])): ?>
                <div class="mt-3">
                    <h5>Notes</h5>
                    <div class="border p-3 rounded">
                        <?php echo nl2br(htmlspecialchars($accessory['notes'])); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="edit_accessory.php?id=<?php echo $accessory_id; ?>" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Edit
                </a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>