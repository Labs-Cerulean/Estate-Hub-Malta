<?php
// Railway MySQL (Replace with your vars)
define('DB_HOST', $_ENV['MYSQLHOST'] ?? 'localhost');
define('DB_USER', $_ENV['MYSQLUSER'] ?? 'root');
define('DB_PASS', $_ENV['MYSQLPASSWORD'] ?? '');
define('DB_NAME', $_ENV['MYSQLDATABASE'] ?? 'estatehub');

// Connect
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
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
    }
    return $pdo;
}
?>
