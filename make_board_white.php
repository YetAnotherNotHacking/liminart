<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * make_board_white.php - Resets null pixels on the canvas to white or creates a checkerboard pattern
 * 
 * This script can be accessed as a web page with a form interface or via direct URL parameters:
 * - pattern=none|checker - Set to 'checker' for checkerboard pattern (default: none)
 * - size=N - Size of checker squares in pixels (default: 10)
 * - color=#RRGGBB - Color for white squares (default: #FFFFFF)
 * - alt_color=#RRGGBB - Color for alternate squares in checker pattern (default: #F0F0F0)
 * - dry_run=0|1 - Set to 1 to report changes without making them (default: 0)
 * - format=html|text - Output format (default: html when accessed in browser, text for API calls)
 */

// Load configuration from .env.php
$config = [];
if (file_exists('.env.php')) {
    $config = require_once('.env.php');
} elseif (file_exists('../.env.php')) {
    $config = require_once('../.env.php');
} else {
    // Fallback config if .env.php is not found
    $config = [
        'db_host' => 'localhost',
        'db_name' => 'pxl',
        'db_user' => 'pxl',
        'db_pass' => 'password',
        'canvas_width' => 1000,
        'canvas_height' => 1000
    ];
    
    die("Configuration file .env.php not found. Please create it or verify path.");
}

// Determine if this is an API call or web interface access
$is_api = isset($_GET['format']) && $_GET['format'] === 'text';
$is_form_submit = isset($_POST['submit']);

// Set content type based on format
if ($is_api) {
    header('Content-Type: text/plain');
} else {
    header('Content-Type: text/html; charset=utf-8');
}

// Process form submission or GET parameters
if ($is_form_submit) {
    // Form was submitted, use POST values
    $options = [
        'pattern' => isset($_POST['pattern']) ? $_POST['pattern'] : 'none',
        'size' => isset($_POST['size']) ? intval($_POST['size']) : 10,
        'color' => isset($_POST['color']) ? $_POST['color'] : '#FFFFFF',
        'alt_color' => isset($_POST['alt_color']) ? $_POST['alt_color'] : '#F0F0F0',
        'dry_run' => isset($_POST['dry_run']) ? (bool)intval($_POST['dry_run']) : false
    ];
} else {
    // Direct URL access, use GET values
    $options = [
        'pattern' => isset($_GET['pattern']) ? $_GET['pattern'] : 'none',
        'size' => isset($_GET['size']) ? intval($_GET['size']) : 10,
        'color' => isset($_GET['color']) ? $_GET['color'] : '#FFFFFF',
        'alt_color' => isset($_GET['alt_color']) ? $_GET['alt_color'] : '#F0F0F0',
        'dry_run' => isset($_GET['dry_run']) ? (bool)intval($_GET['dry_run']) : false
    ];
}

// Strip # from colors if present and convert to hex
$options['color'] = ltrim($options['color'], '#');
$options['alt_color'] = ltrim($options['alt_color'], '#');

// Validate pattern option
if (!in_array($options['pattern'], ['none', 'checker'])) {
    $options['pattern'] = 'none';
}

// Ensure valid size
if ($options['size'] < 1) {
    $options['size'] = 10;
}

// Log file location
$log_file = __DIR__ . '/canvas_reset.log';

// Make sure log directory is writable
try {
    if (!is_dir(dirname($log_file))) {
        mkdir(dirname($log_file), 0755, true);
    }
} catch (Exception $e) {
    // If we can't create log directory, just use the current directory
    $log_file = 'canvas_reset.log';
}

// Function to log activity
function log_activity($message) {
    global $log_file;
    try {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] $message\n";
        file_put_contents($log_file, $log_message, FILE_APPEND);
        return $log_message;
    } catch (Exception $e) {
        return $message . " (Logging failed: " . $e->getMessage() . ")";
    }
}

// Function to render output as HTML
function render_html_page($title, $content, $options = null, $results = null) {
    global $config;
    $canvas_width = isset($config['canvas_width']) ? $config['canvas_width'] : 1000;
    $canvas_height = isset($config['canvas_height']) ? $config['canvas_height'] : 1000;
    
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$title</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            color: #333;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }
        .form-container {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }
        input[type="text"], input[type="number"], select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 16px;
        }
        input[type="color"] {
            padding: 0;
            height: 35px;
            width: 60px;
        }
        input[type="submit"] {
            background: #2c3e50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        input[type="submit"]:hover {
            background: #34495e;
        }
        .checkerboard-preview {
            width: 200px;
            height: 200px;
            border: 1px solid #ddd;
            margin-top: 10px;
        }
        .results {
            background: #e8f4f8;
            border: 1px solid #b8e0e9;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            white-space: pre-wrap;
            font-family: monospace;
        }
        .error {
            background: #f8e8e8;
            border: 1px solid #e9b8b8;
            color: #c83030;
        }
    </style>
    <script>
        function updatePreview() {
            const pattern = document.getElementById('pattern').value;
            const size = document.getElementById('size').value;
            const color = document.getElementById('color').value;
            const altColor = document.getElementById('alt_color').value;
            const preview = document.getElementById('preview');
            
            if (preview && pattern) {
                if (pattern === 'none') {
                    preview.style.background = color;
                } else if (pattern === 'checker') {
                    preview.style.background = 'repeating-conic-gradient(' + 
                        color + ' 0% 25%, ' + 
                        altColor + ' 0% 50%, ' + 
                        color + ' 0% 75%, ' + 
                        altColor + ' 0% 100%' + 
                    ') 0 0 / ' + (size * 2) + 'px ' + (size * 2) + 'px';
                }
            }
            
            // Show/hide alt color based on pattern
            const altColorGroup = document.getElementById('alt_color_group');
            const sizeGroup = document.getElementById('size_group');
            
            if (altColorGroup && sizeGroup) {
                if (pattern === 'checker') {
                    altColorGroup.style.display = 'block';
                    sizeGroup.style.display = 'block';
                } else {
                    altColorGroup.style.display = 'none';
                    sizeGroup.style.display = 'none';
                }
            }
        }
        
        window.onload = function() {
            updatePreview();
            document.getElementById('pattern').addEventListener('change', updatePreview);
            document.getElementById('size').addEventListener('input', updatePreview);
            document.getElementById('color').addEventListener('input', updatePreview);
            document.getElementById('alt_color').addEventListener('input', updatePreview);
        };
    </script>
</head>
<body>
    <h1>$title</h1>
    $content
HTML;

    if ($options) {
        $colorVal = '#' . $options['color'];
        $altColorVal = '#' . $options['alt_color'];
        $patternNoneSelected = $options['pattern'] === 'none' ? 'selected' : '';
        $patternCheckerSelected = $options['pattern'] === 'checker' ? 'selected' : '';
        $dryRunChecked = $options['dry_run'] ? 'checked' : '';
        
        $html .= <<<HTML
    <div class="form-container">
        <form method="post" action="">
            <div class="form-group">
                <label for="pattern">Pattern:</label>
                <select id="pattern" name="pattern">
                    <option value="none" $patternNoneSelected>Solid Color</option>
                    <option value="checker" $patternCheckerSelected>Checkerboard</option>
                </select>
            </div>
            
            <div class="form-group" id="size_group">
                <label for="size">Checker Size (pixels):</label>
                <input type="number" id="size" name="size" min="1" max="100" value="{$options['size']}">
            </div>
            
            <div class="form-group">
                <label for="color">Primary Color:</label>
                <input type="color" id="color" name="color" value="$colorVal"> 
                <input type="text" value="$colorVal" id="color_text" disabled style="width: 100px; margin-left: 10px;">
            </div>
            
            <div class="form-group" id="alt_color_group">
                <label for="alt_color">Alternate Color:</label>
                <input type="color" id="alt_color" name="alt_color" value="$altColorVal">
                <input type="text" value="$altColorVal" id="alt_color_text" disabled style="width: 100px; margin-left: 10px;">
            </div>
            
            <div class="form-group">
                <label for="preview">Preview:</label>
                <div id="preview" class="checkerboard-preview"></div>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" name="dry_run" value="1" $dryRunChecked> 
                    Dry Run (simulate without changing database)
                </label>
            </div>
            
            <div class="form-group">
                <input type="submit" name="submit" value="Apply Changes">
            </div>
        </form>
    </div>
HTML;
    }

    if ($results) {
        $resultsClass = strpos($results, 'Error:') !== false ? 'results error' : 'results';
        $html .= "<div class=\"$resultsClass\">$results</div>";
    }

    $html .= <<<HTML
    <p><strong>Canvas Dimensions:</strong> {$canvas_width}x{$canvas_height}</p>
    <p><small>API Usage: Add <code>?format=text</code> to URL for plain text output</small></p>
</body>
</html>
HTML;

    return $html;
}

// Initialize result message
$results = '';

// Only process if this is a form submission or API call
if ($is_form_submit || count($_GET) > 0) {
    // Connect to database
    try {
        $db = new PDO(
            "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8",
            $config['db_user'],
            $config['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
            ]
        );
    } catch (PDOException $e) {
        $error = "Database connection failed: " . $e->getMessage();
        if ($is_api) {
            die($error);
        } else {
            echo render_html_page("Canvas Reset Tool - Error", "", $options, $error);
            exit;
        }
    }

    // Start time measurement
    $start_time = microtime(true);

    // Count null pixels
    try {
        // Check if the table exists using a proper method
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('pixels', $tables)) {
            throw new PDOException("Table 'pixels' does not exist");
        }
        
        // First, get the canvas dimensions from config
        $canvas_width = isset($config['canvas_width']) ? (int)$config['canvas_width'] : 1000;
        $canvas_height = isset($config['canvas_height']) ? (int)$config['canvas_height'] : 1000;
        
        // Find the most common color in the database - this is likely the background
        $stmt = $db->query("SELECT r, g, b, COUNT(*) as count FROM pixels GROUP BY r, g, b ORDER BY count DESC LIMIT 1");
        $most_common = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($most_common) {
            $bg_r = $most_common['r'];
            $bg_g = $most_common['g'];
            $bg_b = $most_common['b'];
            $bg_count = $most_common['count'];
            
            // Calculate percentage of canvas this color represents
            $total_pixels = $canvas_width * $canvas_height;
            $bg_percentage = round(($bg_count / $total_pixels) * 100, 2);
            
            $bg_hex = sprintf('%02x%02x%02x', $bg_r, $bg_g, $bg_b);
            
            $results .= "Found background color: #{$bg_hex} (RGB: $bg_r,$bg_g,$bg_b)\n";
            $results .= "This color appears in $bg_count pixels ($bg_percentage% of canvas)\n\n";
            
            // Set this as our target condition
            $null_condition = "r = $bg_r AND g = $bg_g AND b = $bg_b";
            $null_count = $bg_count;
        } else {
            // Fallback checks in case we couldn't determine the most common color
            
            // Option 1: Check for literally NULL values
            $stmt = $db->prepare("SELECT COUNT(*) FROM pixels WHERE r IS NULL OR g IS NULL OR b IS NULL");
            $stmt->execute();
            $null_count = $stmt->fetchColumn();
            
            if ($null_count > 0) {
                $results .= "Found $null_count pixels with NULL RGB values.\n\n";
                $null_condition = "r IS NULL OR g IS NULL OR b IS NULL";
            } else {
                // Option 2: Check for black (0,0,0) pixels
                $stmt = $db->prepare("SELECT COUNT(*) FROM pixels WHERE r = 0 AND g = 0 AND b = 0");
                $stmt->execute();
                $zero_count = $stmt->fetchColumn();
                
                if ($zero_count > 0) {
                    $results .= "Found $zero_count pixels with RGB values (0,0,0) - will treat these as background pixels.\n\n";
                    $null_count = $zero_count;
                    $null_condition = "r = 0 AND g = 0 AND b = 0";
                } else {
                    // Option 3: Check for white (255,255,255) pixels
                    $stmt = $db->prepare("SELECT COUNT(*) FROM pixels WHERE r = 255 AND g = 255 AND b = 255");
                    $stmt->execute();
                    $white_count = $stmt->fetchColumn();
                    
                    if ($white_count > 0) {
                        $results .= "Found $white_count white pixels (255,255,255) - will treat these as background pixels.\n\n";
                        $null_count = $white_count;
                        $null_condition = "r = 255 AND g = 255 AND b = 255";
                    } else {
                        // Option 4: Look for missing pixels
                        $stmt = $db->prepare("SELECT COUNT(*) FROM pixels");
                        $stmt->execute();
                        $actual_pixel_count = $stmt->fetchColumn();
                        $expected_pixel_count = $canvas_width * $canvas_height;
                        
                        $missing_count = $expected_pixel_count - $actual_pixel_count;
                        
                        if ($missing_count > 0) {
                            // There are missing pixels!
                            $results .= "Found $missing_count missing pixels (expected: $expected_pixel_count, actual: $actual_pixel_count).\n";
                            $results .= "These missing pixels will be inserted into the database.\n\n";
                            
                            if (!$options['dry_run']) {
                                // We will create the missing pixels with the appropriate colors
                                $missing_mode = true;
                                $null_count = $missing_count;
                            } else {
                                $null_count = $missing_count;
                            }
                        } else {
                            // If all else fails, get the top 5 most common colors for the user to choose from
                            $stmt = $db->query("SELECT r, g, b, COUNT(*) as count FROM pixels GROUP BY r, g, b ORDER BY count DESC LIMIT 5");
                            $common_colors = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            $results .= "Couldn't automatically determine background color. Here are the 5 most common colors:\n";
                            foreach ($common_colors as $i => $color) {
                                $hex = sprintf('%02x%02x%02x', $color['r'], $color['g'], $color['b']);
                                $percentage = round(($color['count'] / $total_pixels) * 100, 2);
                                $results .= ($i+1) . ". #{$hex} (RGB: {$color['r']},{$color['g']},{$color['b']}) - {$color['count']} pixels ($percentage%)\n";
                            }
                            $results .= "\nPlease select a background color manually by specifying it in the form.\n";
                            
                            // Default to the most common color
                            $bg_r = $common_colors[0]['r'];
                            $bg_g = $common_colors[0]['g'];
                            $bg_b = $common_colors[0]['b'];
                            $null_count = $common_colors[0]['count'];
                            $null_condition = "r = $bg_r AND g = $bg_g AND b = $bg_b";
                        }
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $error = "Error analyzing canvas: " . $e->getMessage();
        if ($is_api) {
            die($error);
        } else {
            echo render_html_page("Canvas Reset Tool - Error", "", $options, $error);
            exit;
        }
    }

    // Build results output
    $results .= "Canvas Reset Tool\n";
    $results .= "================\n";
    $results .= "Mode: " . ($options['pattern'] === 'none' ? 'Solid Color' : 'Checkerboard Pattern') . "\n";
    $results .= "Primary Color: #{$options['color']}\n";

    if ($options['pattern'] === 'checker') {
        $results .= "Alternate Color: #{$options['alt_color']}\n";
        $results .= "Checker Size: {$options['size']} pixels\n";
    }

    $results .= "Dry Run: " . ($options['dry_run'] ? 'Yes (no changes will be made)' : 'No') . "\n";
    $results .= "Pixels to Process: $null_count\n\n";

    if ($null_count == 0) {
        $results .= "No pixels to process. Exiting.\n";
        if ($is_api) {
            echo $results;
        } else {
            echo render_html_page("Canvas Reset Tool - Complete", "", $options, $results);
        }
        exit;
    }

    // Begin transaction
    $db->beginTransaction();

    try {
        // Convert hex colors to RGB
        $color1_r = hexdec(substr($options['color'], 0, 2));
        $color1_g = hexdec(substr($options['color'], 2, 2));
        $color1_b = hexdec(substr($options['color'], 4, 2));
        
        $color2_r = hexdec(substr($options['alt_color'], 0, 2));
        $color2_g = hexdec(substr($options['alt_color'], 2, 2));
        $color2_b = hexdec(substr($options['alt_color'], 4, 2));
        
        // Check if we're dealing with missing pixels or existing pixels
        if (isset($missing_mode) && $missing_mode === true) {
            // We need to INSERT missing pixels
            
            // Generate a list of all expected coordinates
            $results .= "Creating missing pixels...\n";
            $timestamp = time();
            $ip_address = $_SERVER['REMOTE_ADDR'];
            
            if (!$options['dry_run']) {
                $stmt = $db->prepare("INSERT INTO pixels (x, y, r, g, b, last_updated, ip_address) VALUES (:x, :y, :r, :g, :b, :time, :ip)");
                
                // Keep track of stats
                $inserted_primary = 0;
                $inserted_alternate = 0;
                
                // First, get all existing pixel coordinates
                $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, 0); // Ensure large result sets work
                $existing_stmt = $db->query("SELECT x, y FROM pixels");
                $existing_pixels = [];
                while ($row = $existing_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $existing_pixels[$row['x'] . ',' . $row['y']] = true;
                }
                
                // Loop through all possible coordinates
                for ($y = 0; $y < $canvas_height; $y++) {
                    for ($x = 0; $x < $canvas_width; $x++) {
                        // Skip if pixel already exists
                        if (isset($existing_pixels[$x . ',' . $y])) {
                            continue;
                        }
                        
                        // Determine if this should be primary or alternate color
                        $is_alternate = ($options['pattern'] === 'checker') && 
                                        ((floor($x / $options['size']) + floor($y / $options['size'])) % 2 === 1);
                        
                        if ($is_alternate) {
                            $r = $color2_r;
                            $g = $color2_g;
                            $b = $color2_b;
                            $inserted_alternate++;
                        } else {
                            $r = $color1_r;
                            $g = $color1_g;
                            $b = $color1_b;
                            $inserted_primary++;
                        }
                        
                        $stmt->execute([
                            'x' => $x,
                            'y' => $y,
                            'r' => $r,
                            'g' => $g,
                            'b' => $b,
                            'time' => $timestamp,
                            'ip' => $ip_address
                        ]);
                    }
                }
                
                $log_message = log_activity("Created $inserted_primary missing pixels with color #{$options['color']} and $inserted_alternate with color #{$options['alt_color']}");
                $results .= $log_message;
            } else {
                // For dry run
                $results .= "Would create $null_count missing pixels with appropriate colors.\n";
            }
        } else {
            // We're dealing with existing pixels that need to be updated
            
            // If pattern is none, simply set all matching pixels to the primary color
            if ($options['pattern'] === 'none') {
                if (!$options['dry_run']) {
                    $stmt = $db->prepare("UPDATE pixels SET r = :r, g = :g, b = :b WHERE $null_condition");
                    $stmt->execute(['r' => $color1_r, 'g' => $color1_g, 'b' => $color1_b]);
                    $updated = $stmt->rowCount();
                    
                    $log_message = log_activity("Set $updated pixels to #{$options['color']}");
                    $results .= $log_message;
                } else {
                    $results .= "Would set $null_count pixels to #{$options['color']}\n";
                }
            } 
            // For checkerboard pattern, we need to apply different colors based on position
            else {
                // First, get all pixel coordinates that match our condition
                $stmt = $db->prepare("SELECT x, y FROM pixels WHERE $null_condition");
                $stmt->execute();
                $pixels_to_update = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $updated_primary = 0;
                $updated_alternate = 0;
                
                if (!$options['dry_run']) {
                    // Prepare statements for both colors
                    $stmt_primary = $db->prepare("UPDATE pixels SET r = :r, g = :g, b = :b WHERE x = :x AND y = :y");
                    $stmt_alternate = $db->prepare("UPDATE pixels SET r = :r, g = :g, b = :b WHERE x = :x AND y = :y");
                    
                    foreach ($pixels_to_update as $pixel) {
                        $x = intval($pixel['x']);
                        $y = intval($pixel['y']);
                        
                        // Determine if this should be primary or alternate color
                        // A square is alternate color if (x/size + y/size) is odd
                        $is_alternate = (floor($x / $options['size']) + floor($y / $options['size'])) % 2 === 1;
                        
                        if ($is_alternate) {
                            $stmt_alternate->execute([
                                'r' => $color2_r,
                                'g' => $color2_g,
                                'b' => $color2_b,
                                'x' => $x,
                                'y' => $y
                            ]);
                            $updated_alternate++;
                        } else {
                            $stmt_primary->execute([
                                'r' => $color1_r,
                                'g' => $color1_g,
                                'b' => $color1_b,
                                'x' => $x,
                                'y' => $y
                            ]);
                            $updated_primary++;
                        }
                    }
                    
                    $log_message = log_activity("Set $updated_primary pixels to #{$options['color']} and $updated_alternate to #{$options['alt_color']} in checkerboard pattern");
                    $results .= $log_message;
                } else {
                    // Estimate counts for dry run
                    $results .= "Would update approximately:\n";
                    $results .= "- " . round($null_count/2) . " pixels to #{$options['color']}\n";
                    $results .= "- " . round($null_count/2) . " pixels to #{$options['alt_color']}\n";
                    $results .= "in a checkerboard pattern with square size {$options['size']}\n";
                }
            }
        }
        
        // Commit changes if not dry run
        if (!$options['dry_run']) {
            $db->commit();
            $results .= "\nChanges committed successfully.\n";
        } else {
            $db->rollBack();
            $results .= "\nDry run complete. No changes were made.\n";
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error: " . $e->getMessage();
        log_activity($error_message);
        $results .= "$error_message\n";
    }

    // Calculate execution time
    $execution_time = round(microtime(true) - $start_time, 2);
    $results .= "\nExecution completed in $execution_time seconds.\n";

    // Output results based on format
    if ($is_api) {
        echo $results;
    }
}

// If this is a web request and not an API call, render the HTML page
if (!$is_api) {
    echo render_html_page(
        "Canvas Reset Tool", 
        "<p>Use this tool to reset all null (empty) pixels on the canvas to white or create a checkerboard pattern.</p>",
        $options,
        $is_form_submit || count($_GET) > 0 ? $results : null
    );
} 