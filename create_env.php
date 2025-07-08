<?php
// Simple script to create a .env.php file from the debug interface

// Basic security: Only allow local requests or from the same server
$allowedIPs = ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR']];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied';
    exit;
}

// Check if this is a POST request with JSON data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the raw POST data
    $input = file_get_contents('php://input');
    
    // Parse as JSON
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Invalid JSON data';
        exit;
    }
    
    // Check if content is provided
    if (!isset($data['content']) || empty($data['content'])) {
        header('HTTP/1.1 400 Bad Request');
        echo 'No content provided';
        exit;
    }
    
    // Validate content - basic check that it contains PHP code and doesn't have harmful content
    if (!preg_match('/^<\?php/i', $data['content']) || 
        strpos($data['content'], 'system(') !== false || 
        strpos($data['content'], 'exec(') !== false ||
        strpos($data['content'], 'shell_exec') !== false) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Invalid content format or potentially unsafe code';
        exit;
    }
    
    // Try to create the .env.php file
    try {
        $result = file_put_contents('.env.php', $data['content']);
        
        if ($result === false) {
            throw new Exception('Could not write to file');
        }
        
        // Set proper permissions
        chmod('.env.php', 0644);
        
        echo "Successfully created .env.php file";
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Error creating file: ' . $e->getMessage();
    }
} else {
    header('HTTP/1.1 405 Method Not Allowed');
    echo 'Method not allowed';
} 