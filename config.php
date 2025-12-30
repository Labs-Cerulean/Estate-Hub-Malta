<?php
define('DB_HOST', getenv('MYSQLHOST') ?: 'mysql.railway.internal');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: 'uZGDNAHVOBaMNxJflkNXtHJVHxtZmgDQ');
define('DB_NAME', getenv('MYSQL_DATABASE') ?: 'railway');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // CLIENTS TABLE
        $pdo->exec("CREATE TABLE IF NOT EXISTS clients (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            city VARCHAR(100),
            contact VARCHAR(255),
            type ENUM('in-house','3rd-party') DEFAULT '3rd-party',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_name (name)
        )");
        
        // PROJECTS TABLE
        $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
            id INT PRIMARY KEY AUTO_INCREMENT,
            client_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            city VARCHAR(100) NOT NULL,
            pa_number VARCHAR(50),
            bca_status VARCHAR(50),
            status ENUM('Pending','In Process','Mobilised') DEFAULT 'Pending',
            type ENUM('in-house','3rd-party') NOT NULL,
            finish_level ENUM('Common Parts Only','Semi Finished','Finished') NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        )");
    }
    return $pdo;
}
?>
