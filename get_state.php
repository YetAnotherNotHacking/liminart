<?php
// Set headers
header('Content-Type: application/json');

// Disable direct error output to browser
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start error capture
ob_start();

// Define basic error response function
function returnError($message, $httpCode = 400) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'error' => $message
    ]);
    exit;
}

// Function to calculate a tile's checksum
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
                'tile_size' => defined('TILE_SIZE') ? TILE_SIZE : 32
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
    
    // Parse request parameters
    $info = isset($_GET['info']) ? (bool)$_GET['info'] : false;
    $tileX = isset($_GET['tile_x']) ? (int)$_GET['tile_x'] : null;
    $tileY = isset($_GET['tile_y']) ? (int)$_GET['tile_y'] : null;
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
    $verifyChecksums = isset($_GET['verify_checksums']) ? (bool)$_GET['verify_checksums'] : false;
    
    // Get request body for checksum data
    $requestBody = file_get_contents('php://input');
    $requestData = null;
    
    if (!empty($requestBody)) {
        $requestData = json_decode($requestBody, true);
        // If JSON parsing failed, ignore the body
        if (json_last_error() !== JSON_ERROR_NONE) {
            $requestData = null;
        }
    }
    
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
    
    // Handle checksum verification endpoint
    if ($verifyChecksums) {
        // Check if we have checksum data in the request
        if (!$requestData || !isset($requestData['checksums']) || !is_array($requestData['checksums'])) {
            returnError("No checksum data provided for verification");
        }
        
        $clientChecksums = $requestData['checksums'];
        $outdatedTiles = [];
        
        // Verify each checksum
        foreach ($clientChecksums as $tileKey => $clientChecksum) {
            // Parse the tile key (format: "x,y")
            list($checkTileX, $checkTileY) = explode(',', $tileKey);
            $checkTileX = (int)$checkTileX;
            $checkTileY = (int)$checkTileY;
            
            // Check if tile coordinates are valid
            $maxTileX = floor((CANVAS_WIDTH - 1) / TILE_SIZE);
            $maxTileY = floor((CANVAS_HEIGHT - 1) / TILE_SIZE);
            
            if ($checkTileX < 0 || $checkTileX > $maxTileX || $checkTileY < 0 || $checkTileY > $maxTileY) {
                continue; // Skip invalid tile coordinates
            }
            
            // Calculate checksum on the server
            $serverChecksum = calculateTileChecksum($checkTileX, $checkTileY, TILE_SIZE, $pdo);
            
            // If checksums don't match, add to outdated tiles
            if ($serverChecksum !== $clientChecksum) {
                $outdatedTiles[$tileKey] = true;
            }
        }
        
        // Get current timestamp
        try {
            $stmt = $pdo->query("SELECT UNIX_TIMESTAMP() as ts");
            $timestamp = $stmt->fetch()['ts'];
        } catch (Exception $e) {
            $timestamp = time(); // Fallback if query fails
        }
        
        // Return the result
        echo json_encode([
            'success' => true,
            'outdatedTiles' => $outdatedTiles,
            'timestamp' => $timestamp
        ]);
        exit;
    }
    
    // Return board info
    if ($info) {
        $boardInfo = [
            'min_x' => 0,
            'max_x' => CANVAS_WIDTH - 1,
            'min_y' => 0,
            'max_y' => CANVAS_HEIGHT - 1,
            'tile_width' => TILE_SIZE,
            'tile_height' => TILE_SIZE,
            'max_tile_x' => floor((CANVAS_WIDTH - 1) / TILE_SIZE),
            'max_tile_y' => floor((CANVAS_HEIGHT - 1) / TILE_SIZE)
        ];
        
        try {
            // Get current timestamp
            $stmt = $pdo->query("SELECT UNIX_TIMESTAMP() as ts");
            $timestamp = $stmt->fetch()['ts'];
        } catch (Exception $e) {
            $timestamp = time(); // Fallback if query fails
        }
        
        // End error capture
        $phpErrors = ob_get_clean();
        if (!empty($phpErrors)) {
            error_log("PHP Warnings in get_state.php (info): " . $phpErrors);
        }
        
        echo json_encode([
            'success' => true,
            'info' => $boardInfo,
            'timestamp' => $timestamp
        ]);
        exit;
    }
    
    // Return tile data
    if ($tileX !== null && $tileY !== null) {
        $tileSize = TILE_SIZE;
        $startX = $tileX * $tileSize;
        $startY = $tileY * $tileSize;
        $endX = $startX + $tileSize - 1;
        $endY = $startY + $tileSize - 1;
        
        // Check for valid tile coordinates
        $maxTileX = floor((CANVAS_WIDTH - 1) / $tileSize);
        $maxTileY = floor((CANVAS_HEIGHT - 1) / $tileSize);
        
        if ($tileX < 0 || $tileX > $maxTileX || $tileY < 0 || $tileY > $maxTileY) {
            returnError("Invalid tile coordinates: ($tileX, $tileY). Valid range: (0-$maxTileX, 0-$maxTileY)");
        }
        
        try {
            // Check if table exists first
            $tableExists = false;
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'pixels'");
                $tableExists = $stmt->rowCount() > 0;
            } catch (Exception $tableError) {
                // Table likely doesn't exist
            }
            
            $pixels = [];
            if ($tableExists) {
                $stmt = $pdo->prepare("SELECT x, y, r, g, b FROM pixels WHERE x >= ? AND x <= ? AND y >= ? AND y <= ?");
                $stmt->execute([$startX, $endX, $startY, $endY]);
                $pixels = $stmt->fetchAll();
            }
            
            // Calculate checksum for this tile
            $checksum = calculateTileChecksum($tileX, $tileY, TILE_SIZE, $pdo);
            
            // Get current timestamp
            try {
                $stmt = $pdo->query("SELECT UNIX_TIMESTAMP() as ts");
                $timestamp = $stmt->fetch()['ts'];
            } catch (Exception $e) {
                $timestamp = time(); // Fallback if query fails
            }
            
            // End error capture
            $phpErrors = ob_get_clean();
            if (!empty($phpErrors)) {
                error_log("PHP Warnings in get_state.php (tile): " . $phpErrors);
            }
            
            echo json_encode([
                'success' => true,
                'pixels' => $pixels,
                'checksum' => $checksum,
                'timestamp' => $timestamp
            ]);
            exit;
        } catch (Exception $e) {
            returnError("Error fetching tile data: " . $e->getMessage(), 500);
        }
    }
    
    // Return updates since a timestamp
    if ($since > 0) {
        // Check for client checksums in the request body
        $clientChecksums = [];
        if ($requestData && isset($requestData['checksums']) && is_array($requestData['checksums'])) {
            $clientChecksums = $requestData['checksums'];
        }
        
        try {
            // Check if table exists first
            $tableExists = false;
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'pixels'");
                $tableExists = $stmt->rowCount() > 0;
            } catch (Exception $tableError) {
                // Table likely doesn't exist
            }
            
            $pixels = [];
            $changedTiles = [];
            $tileChecksums = [];
            
            if ($tableExists) {
                // Get updated pixels
                $stmt = $pdo->prepare("SELECT x, y, r, g, b FROM pixels WHERE UNIX_TIMESTAMP(last_updated) > ?");
                $stmt->execute([$since]);
                $pixels = $stmt->fetchAll();
                
                // Group pixels by tile to identify which tiles have changed
                $updatedTiles = [];
                foreach ($pixels as $pixel) {
                    $pixelTileX = floor($pixel['x'] / TILE_SIZE);
                    $pixelTileY = floor($pixel['y'] / TILE_SIZE);
                    $tileKey = "$pixelTileX,$pixelTileY";
                    $updatedTiles[$tileKey] = true;
                }
                
                // Check all client-provided checksums and include complete tiles if needed
                foreach ($clientChecksums as $tileKey => $clientChecksum) {
                    // Parse the tile key
                    list($checkTileX, $checkTileY) = explode(',', $tileKey);
                    $checkTileX = (int)$checkTileX;
                    $checkTileY = (int)$checkTileY;
                    
                    // Skip if tile coordinates are invalid
                    $maxTileX = floor((CANVAS_WIDTH - 1) / TILE_SIZE);
                    $maxTileY = floor((CANVAS_HEIGHT - 1) / TILE_SIZE);
                    if ($checkTileX < 0 || $checkTileX > $maxTileX || $checkTileY < 0 || $checkTileY > $maxTileY) {
                        continue;
                    }
                    
                    // Calculate current checksum
                    $serverChecksum = calculateTileChecksum($checkTileX, $checkTileY, TILE_SIZE, $pdo);
                    $tileChecksums[$tileKey] = $serverChecksum;
                    
                    // If checksums don't match, include full tile data
                    if ($serverChecksum !== $clientChecksum || isset($updatedTiles[$tileKey])) {
                        // Get all pixels for this tile
                        $startX = $checkTileX * TILE_SIZE;
                        $startY = $checkTileY * TILE_SIZE;
                        $endX = $startX + TILE_SIZE - 1;
                        $endY = $startY + TILE_SIZE - 1;
                        
                        $stmt = $pdo->prepare("SELECT x, y, r, g, b FROM pixels WHERE x >= ? AND x <= ? AND y >= ? AND y <= ?");
                        $stmt->execute([$startX, $endX, $startY, $endY]);
                        $tilePixels = $stmt->fetchAll();
                        
                        $changedTiles[$tileKey] = [
                            'pixels' => $tilePixels,
                            'checksum' => $serverChecksum
                        ];
                        
                        // Remove individual pixel updates for pixels in this tile
                        // since we're sending the complete tile data
                        $pixels = array_filter($pixels, function($pixel) use ($startX, $endX, $startY, $endY) {
                            return !($pixel['x'] >= $startX && $pixel['x'] <= $endX && 
                                    $pixel['y'] >= $startY && $pixel['y'] <= $endY);
                        });
                    }
                }
            }
            
            // Get current timestamp
            try {
                $stmt = $pdo->query("SELECT UNIX_TIMESTAMP() as ts");
                $timestamp = $stmt->fetch()['ts'];
            } catch (Exception $e) {
                $timestamp = time(); // Fallback if query fails
            }
            
            // End error capture
            $phpErrors = ob_get_clean();
            if (!empty($phpErrors)) {
                error_log("PHP Warnings in get_state.php (since): " . $phpErrors);
            }
            
            echo json_encode([
                'success' => true,
                'pixels' => array_values($pixels), // Re-index array after filtering
                'changedTiles' => $changedTiles,
                'tileChecksums' => $tileChecksums,
                'timestamp' => $timestamp
            ]);
            exit;
        } catch (Exception $e) {
            returnError("Error fetching updates: " . $e->getMessage(), 500);
        }
    }
    
    // Default - return an empty response with current timestamp
    try {
        $stmt = $pdo->query("SELECT UNIX_TIMESTAMP() as ts");
        $timestamp = $stmt->fetch()['ts'];
    } catch (Exception $e) {
        $timestamp = time(); // Fallback if query fails
    }
    
    // End error capture
    $phpErrors = ob_get_clean();
    if (!empty($phpErrors)) {
        error_log("PHP Warnings in get_state.php (default): " . $phpErrors);
    }
    
    echo json_encode([
        'success' => true,
        'pixels' => [],
        'timestamp' => $timestamp
    ]);
    
} catch (Exception $e) {
    // End error capture
    $phpErrors = ob_get_clean();
    if (!empty($phpErrors)) {
        error_log("PHP Warnings in get_state.php: " . $phpErrors);
    }
    
    returnError("Unexpected error: " . $e->getMessage(), 500);
}