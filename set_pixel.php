<?php
// Set headers
header('Content-Type: application/json');

// Disable direct error output
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start error capture
ob_start();

// Define error response function
function returnError($message, $httpCode = 400) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'error' => $message
    ]);
    exit;
}

// Function to calculate a tile's checksum - should match the one in get_state.php
function calculateTileChecksum($tileX, $tileY, $tileSize, $pdo) {
    $startX = $tileX * $tileSize;
    $startY = $tileY * $tileSize;
    $endX = $startX + $tileSize - 1;
    $endY = $startY + $tileSize - 1;
    
    // Get all pixels for this tile
    $stmt = $pdo->prepare("SELECT x, y, r, g, b FROM pixels 
                          WHERE x >= ? AND x <= ? AND y >= ? AND y <= ? 
                          ORDER BY y, x");
    
    $stmt->execute([$startX, $endX, $startY, $endY]);
    $pixels = $stmt->fetchAll();
    
    // Create a string representation of all pixel data
    $pixelData = "";
    foreach ($pixels as $pixel) {
        $pixelData .= "{$pixel['x']},{$pixel['y']},{$pixel['r']},{$pixel['g']},{$pixel['b']};";
    }
    
    // Return MD5 hash of the pixel data
    return md5($pixelData);
}

try {
    // Load configuration from .env.php
    $config = [];
    
    if (file_exists('.env.php')) {
        // Include the configuration file
        $include_result = include('.env.php');
        
        if (is_array($include_result)) {
            // Array return format
            $config = $include_result;
        } else if (defined('DB_HOST')) {
            // Constants format
            $config = [
                'db_host' => DB_HOST,
                'db_name' => DB_NAME,
                'db_user' => DB_USER,
                'db_pass' => DB_PASS,
                'canvas_width' => defined('CANVAS_WIDTH') ? CANVAS_WIDTH : 1000,
                'canvas_height' => defined('CANVAS_HEIGHT') ? CANVAS_HEIGHT : 1000,
                'tile_size' => defined('TILE_SIZE') ? TILE_SIZE : 32,
                'rate_limit_seconds' => defined('RATE_LIMIT_SECONDS') ? RATE_LIMIT_SECONDS : 0
            ];
        }
    }
    
    // Default values if configuration couldn't be loaded
    $dbHost = $config['db_host'] ?? 'localhost';
    $dbName = $config['db_name'] ?? 'pxllat';
    $dbUser = $config['db_user'] ?? 'root';
    $dbPass = $config['db_pass'] ?? '';
    
    // Define canvas constants
    define('CANVAS_WIDTH', $config['canvas_width'] ?? 1000);
    define('CANVAS_HEIGHT', $config['canvas_height'] ?? 1000);
    define('TILE_SIZE', $config['tile_size'] ?? 32);
    define('RATE_LIMIT_SECONDS', $config['rate_limit_seconds'] ?? 0);
    define('ENABLE_RATE_LIMIT', RATE_LIMIT_SECONDS > 0);
    
    // Check for proper method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        returnError('Method not allowed', 405);
    }
    
    // Get and validate JSON input
    $json = file_get_contents('php://input');
    if (!$json) {
        returnError('No data provided');
    }
    
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        returnError('Invalid JSON: ' . json_last_error_msg());
    }
    
    // Validate required fields
    if (!isset($data['x']) || !isset($data['y']) || 
        !isset($data['r']) || !isset($data['g']) || !isset($data['b'])) {
        returnError('Missing required fields (x, y, r, g, b)');
    }
    
    // Validate pixel coordinates and colors
    $x = (int) $data['x'];
    $y = (int) $data['y'];
    $r = (int) $data['r'];
    $g = (int) $data['g'];
    $b = (int) $data['b'];
    
    $maxX = CANVAS_WIDTH - 1;
    $maxY = CANVAS_HEIGHT - 1;
    
    if ($x < 0 || $x > $maxX || $y < 0 || $y > $maxY) {
        returnError("Coordinates out of bounds (0-$maxX, 0-$maxY)");
    }
    
    if ($r < 0 || $r > 255 || $g < 0 || $g > 255 || $b < 0 || $b > 255) {
        returnError('Color values must be between 0-255');
    }
    
    // Get client-provided checksum if available
    $checksum = isset($data['checksum']) ? $data['checksum'] : null;
    
    // Connect to database
    try {
        $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    } catch (PDOException $e) {
        // Try creating the database if it doesn't exist
        try {
            $rootDsn = "mysql:host=$dbHost;charset=utf8mb4";
            $rootPdo = new PDO($rootDsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            // Create database if it doesn't exist
            $rootPdo->exec("CREATE DATABASE IF NOT EXISTS $dbName");
            
            // Reconnect with the database name
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            
            // Create the pixels table if it doesn't exist
            $pdo->exec("CREATE TABLE IF NOT EXISTS pixels (
                x INT NOT NULL,
                y INT NOT NULL,
                r TINYINT UNSIGNED NOT NULL,
                g TINYINT UNSIGNED NOT NULL,
                b TINYINT UNSIGNED NOT NULL,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                ip VARCHAR(45) DEFAULT NULL,
                PRIMARY KEY (x, y)
            )");
        } catch (Exception $createError) {
            returnError("Database connection and setup failed: " . $createError->getMessage(), 500);
        }
    }
    
    // If a checksum was provided, verify it before updating
    if ($checksum !== null) {
        // Calculate which tile this pixel belongs to
        $tileX = floor($x / TILE_SIZE);
        $tileY = floor($y / TILE_SIZE);
        
        // Calculate current checksum
        $currentChecksum = calculateTileChecksum($tileX, $tileY, TILE_SIZE, $pdo);
        
        // If checksums don't match, return error with current checksum
        if ($currentChecksum !== $checksum) {
            echo json_encode([
                'success' => false,
                'error' => 'checksum_mismatch',
                'message' => 'The tile has been modified since your last update',
                'checksum' => $currentChecksum
            ]);
            exit;
        }
    }
    
    // Check if rate limiting is enabled
    if (ENABLE_RATE_LIMIT && RATE_LIMIT_SECONDS > 0) {
        // Get client IP
        $clientIP = $_SERVER['REMOTE_ADDR'];
        
        // Check recent activity
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM pixels 
                                 WHERE UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(last_updated) < ? 
                                 AND ip = ?");
            $stmt->execute([RATE_LIMIT_SECONDS, $clientIP]);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                returnError("Rate limited. Please wait " . RATE_LIMIT_SECONDS . " seconds between placing pixels.", 429);
            }
        } catch (Exception $e) {
            // If error checking rate limit, log and continue
            error_log("Rate limit check error: " . $e->getMessage());
        }
    }
    
    // Make sure no transaction is active
    try {
        // Check for active transaction
        $stmt = $pdo->query("SELECT @@autocommit");
        $autocommit = $stmt->fetch(PDO::FETCH_NUM);
        
        if ($autocommit[0] == 0) {
            // Roll back active transaction
            $pdo->exec("ROLLBACK");
            $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
        }
    } catch (Exception $e) {
        // Try blind rollback on error
        try {
            $pdo->exec("ROLLBACK");
            $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
        } catch (Exception $innerEx) {
            // Ignore rollback errors
        }
    }
    
    // Verify table exists
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'pixels'");
        if ($stmt->rowCount() == 0) {
            // Create the pixels table
            $pdo->exec("CREATE TABLE IF NOT EXISTS pixels (
                x INT NOT NULL,
                y INT NOT NULL,
                r TINYINT UNSIGNED NOT NULL,
                g TINYINT UNSIGNED NOT NULL,
                b TINYINT UNSIGNED NOT NULL,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                ip VARCHAR(45) DEFAULT NULL,
                PRIMARY KEY (x, y)
            )");
        }
    } catch (Exception $e) {
        returnError("Error verifying/creating table: " . $e->getMessage(), 500);
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Get client IP
        $clientIP = $_SERVER['REMOTE_ADDR'];
        
        // Check if pixel exists
        $stmt = $pdo->prepare("SELECT 1 FROM pixels WHERE x = ? AND y = ?");
        $stmt->execute([$x, $y]);
        $existingPixel = $stmt->fetch();
        
        // Use simple, direct pixel update or insert
        if ($existingPixel) {
            $stmt = $pdo->prepare("UPDATE pixels SET r = ?, g = ?, b = ?, ip_address = ?, last_updated = NOW() WHERE x = ? AND y = ?");
            $stmt->execute([$r, $g, $b, $clientIP, $x, $y]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO pixels (x, y, r, g, b, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$x, $y, $r, $g, $b, $clientIP]);
        }
        
        // Commit transaction
        $pdo->commit();
        
        // Ensure autocommit is enabled
        $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
        
        // Get basic stats
        $totalPixels = 0;
        $userPixels = 0;
        $percentage = 0;
        
        try {
            // Get total pixel count
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM pixels");
            $totalPixels = $stmt->fetch()['count'];
            
            // Get user pixel count
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM pixels WHERE ip_address = ?");
            $stmt->execute([$clientIP]);
            $userPixels = $stmt->fetch()['count'];
            
            // Calculate percentage
            if ($totalPixels > 0) {
                $percentage = round(($userPixels / $totalPixels) * 100, 2);
            }
        } catch (Exception $e) {
            // Ignore stats errors
            error_log("Error getting stats: " . $e->getMessage());
        }
        
        // Calculate the updated checksum for this tile
        $tileX = floor($x / TILE_SIZE);
        $tileY = floor($y / TILE_SIZE);
        $newChecksum = calculateTileChecksum($tileX, $tileY, TILE_SIZE, $pdo);
        
        // Get current timestamp
        try {
            $stmt = $pdo->query("SELECT UNIX_TIMESTAMP() as ts");
            $timestamp = $stmt->fetch()['ts'];
        } catch (Exception $e) {
            $timestamp = time(); // Fallback if query fails
        }
        
        // Success response with stats
        echo json_encode([
            'success' => true,
            'x' => $x,
            'y' => $y,
            'stats' => [
                'user_pixels' => $userPixels,
                'total_pixels' => $totalPixels,
                'percentage' => $percentage
            ],
            'checksum' => $newChecksum,
            'timestamp' => $timestamp
        ]);
        
    } catch (Exception $e) {
        // Rollback on error
        $pdo->rollBack();
        
        // End error capture
        $phpErrors = ob_get_clean();
        if (!empty($phpErrors)) {
            error_log("PHP Warnings in set_pixel.php: " . $phpErrors);
        }
        
        returnError("Error placing pixel: " . $e->getMessage(), 500);
    }
    
    // End error capture
    $phpErrors = ob_get_clean();
    if (!empty($phpErrors)) {
        error_log("PHP Warnings in set_pixel.php: " . $phpErrors);
    }
    
} catch (Exception $e) {
    // End error capture
    $phpErrors = ob_get_clean();
    if (!empty($phpErrors)) {
        error_log("PHP Warnings in set_pixel.php: " . $phpErrors);
    }
    
    returnError("Unexpected error: " . $e->getMessage(), 500);
}