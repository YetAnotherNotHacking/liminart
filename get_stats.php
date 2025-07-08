<?php
header('Content-Type: application/json');

require_once 'Database.php';
require_once 'Controller.php';

$config = require('.env.php');
$controller = new Controller($config);

try {
    $stats = [
        'user_stats' => $controller->getUserStats($_SERVER['REMOTE_ADDR']),
        'active_users' => $controller->getActiveUsers(),
        'top_contributor' => $controller->getTopContributor()
    ];
    echo json_encode($stats);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} 