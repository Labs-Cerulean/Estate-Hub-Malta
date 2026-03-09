<?php
require_once 'init.php';

// 1. Security Check
$secretKey = 'estatehub_backup_envy'; // CHANGE THIS TO YOUR OWN SECRET KEY

// Check if user is an Admin OR if the correct secret key is in the URL
$isAuthorized = (isset($_SESSION['user_id']) && isAdmin()) || (isset($_GET['key']) && $_GET['key'] === $secretKey);

if (!$isAuthorized) {
    die("Unauthorized access.");
}

// 2. Set Headers to force download a .sql file
$dbName = getenv('DB_NAME') ?: 'estate_hub';
$date = date('Y-m-d_H-i-s');
$filename = "backup_{$dbName}_{$date}.sql";

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 3. Generate the SQL Dump via Pure PHP
try {
    echo "-- Database Backup\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    // Get all tables
    $tables = [];
    $query = $pdo->query('SHOW TABLES');
    while ($row = $query->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    foreach ($tables as $table) {
        // Get Table Structure
        $query = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $query->fetch(PDO::FETCH_NUM);
        echo "-- Table Structure for `$table`\n";
        echo "DROP TABLE IF EXISTS `$table`;\n";
        echo $row[1] . ";\n\n";

        // Get Table Data
        $query = $pdo->query("SELECT * FROM `$table`");
        $rowCount = $query->rowCount();
        
        if ($rowCount > 0) {
            echo "-- Data for `$table`\n";
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $keys = array_keys($row);
                $values = array_values($row);
                
                $out = "INSERT INTO `$table` (`" . implode('`, `', $keys) . "`) VALUES (";
                
                $valOut = [];
                foreach ($values as $val) {
                    if ($val === null) {
                        $valOut[] = "NULL";
                    } else {
                        // Safely escape values
                        $valOut[] = $pdo->quote($val);
                    }
                }
                
                $out .= implode(', ', $valOut) . ");\n";
                echo $out;
            }
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    echo "-- End of Backup\n";

} catch (Exception $e) {
    echo "-- Backup Error: " . $e->getMessage();
}
exit;
