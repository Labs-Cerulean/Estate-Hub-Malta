<?php
// Railway MySQL - Get from Railway → Variables tab
define('DB_HOST', getenv('MYSQLHOST') ?: 'localhost');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'railway');

// Test connection + create table
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            // Create table if not exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                client VARCHAR(255) NOT NULL,
                city VARCHAR(100) NOT NULL,
                pa_number VARCHAR(50),
                bca_status VARCHAR(50),
                status ENUM('Pending','In Process','Mobilised') DEFAULT 'Pending',
                type ENUM('in-house','3rd-party') NOT NULL,
                finish_level ENUM('Common Parts Only','Semi Finished','Finished') NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            
        } catch (PDOException $e) {
            die("❌ Database Error: " . $e->getMessage() . "<br>
                 Check Railway Variables: MYSQLHOST, MYSQLUSER, MYSQLPASSWORD, MYSQLDATABASE");
        }
    }
    return $pdo;
}
?>
