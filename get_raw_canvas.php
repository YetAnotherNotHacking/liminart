<?php
// Set headers based on format requested
$format = $_GET['format'] ?? 'json';
if ($format === 'binary') {
    header('Content-Type: application/octet-stream');
} elseif ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="canvas.csv"');
} elseif ($format === '2darray') {
    header('Content-Type: application/json');
} else {
    header('Content-Type: application/json');
}

// Enable compression for large responses
if (function_exists('ob_gzhandler') && !in_array($format, ['binary'])) {
    ob_start('ob_gzhandler');
} else {
    ob_start();
}

// Disable direct error output to browser
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Define basic error response function
function returnError($message, $httpCode = 400) {
    global $format;
    http_response_code($httpCode);
    
    if ($format === 'json' || $format === '2darray') {
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
    } else {
        echo "ERROR: $message";
    }
    exit;
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
                'canvas_height' => defined('CANVAS_HEIGHT') ? CANVAS_HEIGHT : 1000
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
    
    // Parse request parameters
    $regionX = isset($_GET['region_x']) ? (int)$_GET['region_x'] : 0;
    $regionY = isset($_GET['region_y']) ? (int)$_GET['region_y'] : 0;
    $regionWidth = isset($_GET['region_width']) ? (int)$_GET['region_width'] : CANVAS_WIDTH;
    $regionHeight = isset($_GET['region_height']) ? (int)$_GET['region_height'] : CANVAS_HEIGHT;
    
    // Check for max size limits to prevent excessive resource usage
    $maxResponseSize = 10485760; // 10MB limit
    if ($regionWidth * $regionHeight > 1048576) { // More than 1 million pixels
        // Limit region size for 2D array format which creates a full grid
        if ($format === '2darray') {
            returnError("Region too large for 2D array format. Maximum is 1 million pixels.", 400);
        }
    }
    
    // Clamp region to canvas bounds
    $regionX = max(0, min($regionX, CANVAS_WIDTH - 1));
    $regionY = max(0, min($regionY, CANVAS_HEIGHT - 1));
    $regionWidth = max(1, min($regionWidth, CANVAS_WIDTH - $regionX));
    $regionHeight = max(1, min($regionHeight, CANVAS_HEIGHT - $regionY));
    
    // Connect to database
    try {
        $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    } catch (PDOException $e) {
        returnError("Database connection failed: " . $e->getMessage(), 500);
    }
    
    // Check if pixels table exists
    $tableExists = false;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'pixels'");
        $tableExists = $stmt->rowCount() > 0;
    } catch (Exception $e) {
        // Table likely doesn't exist
    }
    
    if (!$tableExists) {
        returnError("Pixels table does not exist. Canvas may not be initialized.", 500);
    }
    
    // Get current timestamp
    try {
        $stmt = $pdo->query("SELECT UNIX_TIMESTAMP() as ts");
        $timestamp = $stmt->fetch()['ts'];
    } catch (Exception $e) {
        $timestamp = time(); // Fallback if query fails
    }
    
    // Fetch pixels from the specified region
    $stmt = $pdo->prepare("SELECT x, y, r, g, b FROM pixels 
                          WHERE x >= ? AND x < ? AND y >= ? AND y < ? 
                          ORDER BY y, x");
    $stmt->execute([$regionX, $regionX + $regionWidth, $regionY, $regionY + $regionHeight]);
    
    // Output based on requested format
    if ($format === 'binary') {
        // Binary format: 5 bytes per pixel (x:2, y:2, r:1, g:1, b:1)
        // Create a sparse binary representation
        $pixelCount = 0;
        $sparseData = pack('V', 0); // Placeholder for pixel count
        
        while ($pixel = $stmt->fetch()) {
            $x = $pixel['x'];
            $y = $pixel['y'];
            $r = $pixel['r'];
            $g = $pixel['g'];
            $b = $pixel['b'];
            
            // Pack x, y as unsigned shorts (2 bytes each) and r,g,b as unsigned chars (1 byte each)
            $sparseData .= pack('vvCCC', $x, $y, $r, $g, $b);
            $pixelCount++;
        }
        
        // Update the pixel count at the beginning
        $sparseData = pack('V', $pixelCount) . substr($sparseData, 4);
        
        // End output buffering and send data
        ob_end_clean();
        echo $sparseData;
    } elseif ($format === 'csv') {
        // CSV format: x,y,r,g,b
        echo "x,y,r,g,b\n";
        
        while ($pixel = $stmt->fetch()) {
            echo "{$pixel['x']},{$pixel['y']},{$pixel['r']},{$pixel['g']},{$pixel['b']}\n";
        }
        
    } elseif ($format === '2darray') {
        // 2D array format - useful for direct canvas manipulation
        // Initialize 2D array with default white pixels
        $grid = [];
        for ($y = 0; $y < $regionHeight; $y++) {
            $row = [];
            for ($x = 0; $x < $regionWidth; $x++) {
                // [r, g, b] format
                $row[] = [255, 255, 255];  // Default white
            }
            $grid[] = $row;
        }
        
        // Fill in the grid with actual pixel data
        while ($pixel = $stmt->fetch()) {
            $x = $pixel['x'] - $regionX;
            $y = $pixel['y'] - $regionY;
            
            if ($x >= 0 && $x < $regionWidth && $y >= 0 && $y < $regionHeight) {
                $grid[$y][$x] = [(int)$pixel['r'], (int)$pixel['g'], (int)$pixel['b']];
            }
        }
        
        // Output as JSON
        echo json_encode([
            'success' => true,
            'canvas_width' => CANVAS_WIDTH,
            'canvas_height' => CANVAS_HEIGHT,
            'region' => [
                'x' => $regionX,
                'y' => $regionY,
                'width' => $regionWidth,
                'height' => $regionHeight
            ],
            'grid' => $grid,
            'timestamp' => $timestamp
        ]);
    } else {
        // Default JSON format with sparse pixel list
        $pixels = [];
        
        while ($pixel = $stmt->fetch()) {
            $pixels[] = [
                'x' => (int)$pixel['x'],
                'y' => (int)$pixel['y'],
                'r' => (int)$pixel['r'],
                'g' => (int)$pixel['g'],
                'b' => (int)$pixel['b']
            ];
        }
        
        // Output as JSON
        echo json_encode([
            'success' => true,
            'canvas_width' => CANVAS_WIDTH,
            'canvas_height' => CANVAS_HEIGHT,
            'region' => [
                'x' => $regionX,
                'y' => $regionY,
                'width' => $regionWidth,
                'height' => $regionHeight
            ],
            'pixel_count' => count($pixels),
            'pixels' => $pixels,
            'timestamp' => $timestamp
        ]);
    }
    
} catch (Exception $e) {
    // End error capture and return error
    ob_end_clean();
    
    returnError("Unexpected error: " . $e->getMessage(), 500);
} 