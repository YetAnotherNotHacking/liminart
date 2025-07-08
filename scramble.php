<?php
require_once 'Database.php';
require_once 'Controller.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $config = require('.env.php');
    $controller = new Controller($config);
    
    // Get board info to determine size
    $tileInfo = $controller->getTileInfo();
    
    // Execute scramble on the board
    $result = $controller->scrambleCanvas();
    
    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
} 