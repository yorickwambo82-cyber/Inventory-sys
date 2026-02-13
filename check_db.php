<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<h3>Database Diagnostic Tool</h3>";
echo "<p>Checking phonestore_db structure...</p>";

try {
    // 1. List all tables
    echo "<h4>1. All Tables in Database:</h4>";
    $query = "SHOW TABLES";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";
    
    // 2. Check phones table structure
    echo "<h4>2. Phones Table Structure:</h4>";
    if (in_array('phones', $tables)) {
        $query = "DESCRIBE phones";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        foreach ($structure as $column) {
            echo "<tr>";
            echo "<td>" . $column['Field'] . "</td>";
            echo "<td>" . $column['Type'] . "</td>";
            echo "<td>" . $column['Null'] . "</td>";
            echo "<td>" . $column['Key'] . "</td>";
            echo "<td>" . $column['Default'] . "</td>";
            echo "<td>" . $column['Extra'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Show some sample data
        echo "<h4>3. Sample Data from Phones Table:</h4>";
        $query = "SELECT * FROM phones LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($samples) > 0) {
            echo "<table border='1' cellpadding='5'>";
            echo "<tr>";
            foreach (array_keys($samples[0]) as $key) {
                echo "<th>$key</th>";
            }
            echo "</tr>";
            
            foreach ($samples as $row) {
                echo "<tr>";
                foreach ($row as $value) {
                    echo "<td>" . htmlspecialchars($value) . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>No data in phones table</p>";
        }
    } else {
        echo "<p>Phones table does not exist!</p>";
    }
    
    // 3. Check accessories table
    echo "<h4>4. Accessories Table Structure:</h4>";
    if (in_array('accessories', $tables)) {
        $query = "DESCRIBE accessories";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        foreach ($structure as $column) {
            echo "<tr>";
            echo "<td>" . $column['Field'] . "</td>";
            echo "<td>" . $column['Type'] . "</td>";
            echo "<td>" . $column['Null'] . "</td>";
            echo "<td>" . $column['Key'] . "</td>";
            echo "<td>" . $column['Default'] . "</td>";
            echo "<td>" . $column['Extra'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>Accessories table does not exist!</p>";
    }
    
} catch(Exception $e) {
    echo "<div style='color: red; padding: 10px; border: 1px solid red;'>";
    echo "Error: " . $e->getMessage();
    echo "</div>";
}
?>