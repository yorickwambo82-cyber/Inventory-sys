<?php
/*
 * Database Session Handler for Vercel/Serverless Environments
 * Stores session data in MySQL database to ensure persistence across lambda requests.
 */

// Prevent multiple inclusions
if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

// Ensure database connection is available
require_once __DIR__ . '/../config/database.php';

class DbSessionHandler implements SessionHandlerInterface {
    private $db;
    private $table = 'sessions';

    public function __construct($db) {
        $this->db = $db;
    }

    public function open($savePath, $sessionName): bool {
        // Database connection is already established
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read($id): string|false {
        try {
            $stmt = $this->db->prepare("SELECT data FROM {$this->table} WHERE id = :id AND access > :expiry");
            // Session expires after 2 hours (7200 seconds)
            $expiry = time() - 7200; 
            $stmt->execute([':id' => $id, ':expiry' => $expiry]);
            
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                return $row['data'];
            }
            return '';
        } catch (PDOException $e) {
            error_log("Session Read Error: " . $e->getMessage());
            return '';
        }
    }

    public function write($id, $data): bool {
        try {
            $access = time();
            $stmt = $this->db->prepare("REPLACE INTO {$this->table} (id, access, data) VALUES (:id, :access, :data)");
            return $stmt->execute([
                ':id' => $id, 
                ':access' => $access, 
                ':data' => $data
            ]);
        } catch (PDOException $e) {
            error_log("Session Write Error: " . $e->getMessage());
            return false;
        }
    }

    public function destroy($id): bool {
        try {
            $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function gc($maxlifetime): int|false {
        try {
            $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE access < :expiry");
            $old = time() - $maxlifetime;
            $stmt->execute([':expiry' => $old]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            return false;
        }
    }
    
    // Helper to create table
    public function initTable() {
        try {
            $query = "CREATE TABLE IF NOT EXISTS {$this->table} (
                id VARCHAR(128) NOT NULL PRIMARY KEY,
                access INT(10) UNSIGNED NOT NULL,
                data TEXT
            ) ENGINE=InnoDB";
            $this->db->exec($query);
        } catch (PDOException $e) {
            error_log("Session Table Creation Error: " . $e->getMessage());
        }
    }
}

// Initialize the handler
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $handler = new DbSessionHandler($db);
    
    // Create connection table if it doesn't exist (Checked manually or once)
    // $handler->initTable();
    
    // Set the custom handler
    session_set_save_handler($handler, true);
    
    // Start the session
    session_start();
    
} catch (Exception $e) {
    // Fallback to default file handling if DB fails
    error_log("Session Init Error: " . $e->getMessage());
    session_start();
}
?>
