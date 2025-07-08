<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'Database.php';
require_once 'Controller.php';

try {
    $config = require('.env.php');
    $db = new Database($config);
    
    // Test database connection
    echo "Database connection successful\n";
    
    // Test getting pixels
    $pixels = $db->getAllPixels();
    echo "Got " . count($pixels) . " pixels\n";
    
    // Insert a test pixel
    $db->setPixel(0, 0, 255, 0, 0, '127.0.0.1');
    echo "Inserted test pixel\n";
    
    // Get pixels again
    $pixels = $db->getAllPixels();
    echo "Got " . count($pixels) . " pixels after insert\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} 