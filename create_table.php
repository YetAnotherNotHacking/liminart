<?php
// Script to create the pixels table in the database

// Basic security: Only allow local requests or from the same server
$allowedIPs = ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR']];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied';
    exit;
}

// Make sure we display errors for troubleshooting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Try to load the env file
if (!file_exists('.env.php')) {
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Environment file (.env.php) does not exist. Please create it first.';
    exit;
}

// Include the environment file
require_once '.env.php';

// Check if the required constants are defined
if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Database configuration is missing from .env.php';
    exit;
}

try {
    // Connect to the database
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    // Define the SQL to create the pixels table
    $sql = "CREATE TABLE IF NOT EXISTS `pixels` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `x` int(11) NOT NULL,
        `y` int(11) NOT NULL,
        `r` tinyint(4) NOT NULL,
        `g` tinyint(4) NOT NULL,
        `b` tinyint(4) NOT NULL,
        `ip` varchar(45) DEFAULT NULL,
        `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `x_y` (`x`,`y`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    // Execute the SQL
    $pdo->exec($sql);
    
    echo "Successfully created the pixels table!";
    
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Database error: ' . $e->getMessage();
} 