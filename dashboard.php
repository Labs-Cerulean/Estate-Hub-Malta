<?php 
session_start(); 
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) { 
    header('Location: index.php'); exit; 
}
require_once 'config.php'; 
$pdo = getDB(); 
$stats = [
    'total' => $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn(),
    'clients' => $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn()
];
$is_admin = $_SESSION['user'] === 'admin';
?>
<!DOCTYPE
