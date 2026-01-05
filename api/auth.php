<?php
session_start();

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] == 1) {
  session_destroy();
  header("Location: index.php");
  exit;
}

// If not logged in, redirect to login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  header("Location: index.php");
  exit;
}

// Default fallback
header("Location: dashboard.php");
exit;
?>
