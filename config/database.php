<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        $this->host = getenv('DB_HOST') ?: "localhost";
        $this->db_name = getenv('DB_NAME') ?: "phonestore_db";
        $this->username = getenv('DB_USER') ?: "root";
        $this->password = getenv('DB_PASS') ?: "";
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $port = getenv('DB_PORT') ?: 3306;
            
            // Azure/Aiven require SSL. We enable it by default for remote connections.
            $options = array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                PDO::ATTR_EMULATE_PREPARES => false,
            );

            // Add SSL if DB_SSL_CA is set or just generally for cloud DBs
            if (getenv('DB_HOST') !== 'localhost') {
                $options[PDO::MYSQL_ATTR_SSL_CA] = "/etc/ssl/certs/ca-certificates.crt"; // Default location on Vercel/Linux
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false; // Sometimes needed if cert path is tricky
            }

            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";port=" . $port . ";dbname=" . $this->db_name,
                $this->username,
                $this->password,
                $options
            );

            // Create activity_log table if it doesn't exist
            try {
                $createTableQuery = "CREATE TABLE IF NOT EXISTS activity_log (
                    log_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    action_type VARCHAR(50) NOT NULL,
                    description TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_created_at (created_at),
                    INDEX idx_action_type (action_type)
                )";
                $this->conn->exec($createTableQuery);
            } catch(Exception $e) {
                error_log("Activity log table creation error: " . $e->getMessage());
            }

        } catch(PDOException $exception) {
            // Log error to file in production
            error_log("Database Connection Error: " . $exception->getMessage());
            
            // User-friendly error message
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                die("<div style='padding:20px;margin:20px;border:1px solid #f00;background:#fee;border-radius:5px;'>
                    <h3>Database Connection Error</h3>
                    <p>Please check your database configuration in <code>config/database.php</code></p>
                    <p>Make sure:</p>
                    <ol>
                        <li>XAMPP MySQL service is running</li>
                        <li>Database 'phonestore_db' exists</li>
                        <li>Tables are created from the SQL script</li>
                    </ol>
                    <p><strong>Error Details:</strong> " . $exception->getMessage() . "</p>
                    </div>");
            } else {
                // For AJAX requests
                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Database connection failed: ' . $exception->getMessage()
                ]);
                exit();
            }
        }
        return $this->conn;
    }
    
    // Test database connection
    public function testConnection() {
        try {
            $this->getConnection();
            return [
                'status' => 'success',
                'message' => 'Database connected successfully!'
            ];
        } catch(Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
function verifyAdminLogin($username, $password) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT * FROM users WHERE username = :username AND role = 'admin' AND is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        $user = $stmt->fetch();
        
        if ($user) {
            // Check if password_hash is actually a plain text password (for testing)
            if ($user['password_hash'] === $password) {
                return $user;
            }
            
            // If using hashed passwords (for production):
            // if (password_verify($password, $user['password_hash'])) {
            //     return $user;
            // }
        }
        
        return null;
    } catch(Exception $e) {
        return null;
    }
}
?>