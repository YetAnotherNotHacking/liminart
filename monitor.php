<?php
/**
 * Monitoring endpoint for the Pixel Canvas application
 * Provides diagnostic information about the server, PHP, and database
 */

// Set the content type to JSON
header('Content-Type: application/json');

// Enable error display for monitoring purposes
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Initialize response array
$response = [
    'success' => true,
    'time' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'memory_usage' => [
        'current' => memory_get_usage(true) / 1024 / 1024 . ' MB',
        'peak' => memory_get_peak_usage(true) / 1024 / 1024 . ' MB'
    ],
    'server_info' => [
        'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'host' => $_SERVER['HTTP_HOST'] ?? 'Unknown',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
    ],
    'php_extensions' => get_loaded_extensions(),
    'mysql_status' => 'Not checked yet',
    'file_system' => []
];

// Check if the current directory is writable
$response['file_system']['current_dir_writable'] = is_writable('.') ? 'Yes' : 'No';

// Check if we can read and write to a test file
$test_file = 'monitor_test.txt';
try {
    $test_content = 'Test write at ' . date('Y-m-d H:i:s');
    file_put_contents($test_file, $test_content);
    $response['file_system']['test_file_write'] = 'Success';
    
    $read_content = file_get_contents($test_file);
    $response['file_system']['test_file_read'] = ($read_content === $test_content) ? 'Success' : 'Failed (content mismatch)';
    
    unlink($test_file);
    $response['file_system']['test_file_delete'] = 'Success';
} catch (Exception $e) {
    $response['file_system']['file_operations'] = 'Failed: ' . $e->getMessage();
}

// Load configuration
try {
    // Try to include the .env.php file
    $response['file_system']['.env.php_exists'] = file_exists('.env.php') ? 'Yes' : 'No';
    
    if (file_exists('.env.php')) {
        // Try multiple methods to load config
        $config = null;
        
        // Method 1: Include file that returns array
        $include_result = include('.env.php');
        if (is_array($include_result)) {
            $config = $include_result;
            $response['config_method'] = 'array_return';
        } 
        // Method 2: Constants
        else if (defined('DB_HOST')) {
            $config = [
                'db_host' => DB_HOST,
                'db_name' => DB_NAME,
                'db_user' => DB_USER,
                'db_pass' => '******' // Masked for security
            ];
            $response['config_method'] = 'constants';
        }
        // Method 3: Try getConfig function
        else if (function_exists('getConfig')) {
            $config = getConfig();
            $response['config_method'] = 'getConfig_function';
        }
        
        if ($config) {
            $response['config_loaded'] = 'Yes';
            $response['config_keys'] = array_keys($config);
            
            // Extract database info (mask password)
            $db_config = [
                'host' => $config['db_host'] ?? $config['DB_HOST'] ?? 'Not defined',
                'name' => $config['db_name'] ?? $config['DB_NAME'] ?? 'Not defined',
                'user' => $config['db_user'] ?? $config['DB_USER'] ?? 'Not defined'
            ];
            $response['db_config'] = $db_config;
            
            // Extract canvas settings
            $canvas_config = [
                'width' => $config['canvas_width'] ?? $config['CANVAS_WIDTH'] ?? (defined('CANVAS_WIDTH') ? CANVAS_WIDTH : 'Not defined'),
                'height' => $config['canvas_height'] ?? $config['CANVAS_HEIGHT'] ?? (defined('CANVAS_HEIGHT') ? CANVAS_HEIGHT : 'Not defined'),
                'tile_size' => $config['tile_size'] ?? $config['TILE_SIZE'] ?? (defined('TILE_SIZE') ? TILE_SIZE : 'Not defined')
            ];
            $response['canvas_config'] = $canvas_config;
            
            // Extract rate limiting settings
            $rate_limit = $config['rate_limit_seconds'] ?? $config['RATE_LIMIT_SECONDS'] ?? (defined('RATE_LIMIT_SECONDS') ? RATE_LIMIT_SECONDS : 'Not defined');
            $response['rate_limit_seconds'] = $rate_limit;
        } else {
            $response['config_loaded'] = 'No - Format not recognized';
        }
    }
} catch (Exception $e) {
    $response['config_error'] = $e->getMessage();
}

// Try to connect to MySQL with three different methods
try {
    $db_connected = false;
    $mysqli = null;
    
    // Method 1: Try to connect with constants if defined
    if (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS') && defined('DB_NAME')) {
        try {
            $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if (!$mysqli->connect_error) {
                $db_connected = true;
                $response['mysql_status'] = 'Connected using constants';
                $response['mysql_connection_method'] = 'constants';
            }
        } catch (Exception $e) {
            // Try next method
        }
    }
    
    // Method 2: Try to connect with config array if not connected yet
    if (!$db_connected && isset($config) && is_array($config)) {
        try {
            $host = $config['db_host'] ?? $config['DB_HOST'] ?? null;
            $user = $config['db_user'] ?? $config['DB_USER'] ?? null;
            $pass = $config['db_pass'] ?? $config['DB_PASS'] ?? null;
            $name = $config['db_name'] ?? $config['DB_NAME'] ?? null;
            
            if ($host && $user && $name) {
                $mysqli = new mysqli($host, $user, $pass, $name);
                if (!$mysqli->connect_error) {
                    $db_connected = true;
                    $response['mysql_status'] = 'Connected using config array';
                    $response['mysql_connection_method'] = 'config_array';
                }
            }
        } catch (Exception $e) {
            // Try next method
        }
    }
    
    // Method 3: Try to connect with default values if everything else fails
    if (!$db_connected) {
        try {
            $default_host = 'localhost';
            $default_user = 'root';
            $default_pass = '';
            $default_name = 'pxllat';
            
            $mysqli = new mysqli($default_host, $default_user, $default_pass);
            
            // Check if database exists
            $result = $mysqli->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$default_name'");
            $database_exists = $result && $result->num_rows > 0;
            
            if (!$database_exists) {
                // Try to create database
                $mysqli->query("CREATE DATABASE IF NOT EXISTS `$default_name`");
            }
            
            // Select database
            $mysqli->select_db($default_name);
            
            if (!$mysqli->connect_error) {
                $db_connected = true;
                $response['mysql_status'] = 'Connected using default values';
                $response['mysql_connection_method'] = 'default_values';
                $response['mysql_default_connection'] = [
                    'host' => $default_host,
                    'user' => $default_user,
                    'name' => $default_name
                ];
            }
        } catch (Exception $e) {
            // Final failure
            $response['mysql_status'] = 'Failed to connect with all methods';
        }
    }
    
    // If connected, get additional info
    if ($db_connected && $mysqli) {
        // Check and create table if needed
        try {
            // Check if pixels table exists
            $result = $mysqli->query("SHOW TABLES LIKE 'pixels'");
            $table_exists = $result && $result->num_rows > 0;
            
            if (!$table_exists) {
                // Create table
                $create_table_sql = "CREATE TABLE `pixels` (
                    `x` INT NOT NULL,
                    `y` INT NOT NULL,
                    `r` TINYINT UNSIGNED NOT NULL,
                    `g` TINYINT UNSIGNED NOT NULL,
                    `b` TINYINT UNSIGNED NOT NULL,
                    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `ip` VARCHAR(45) DEFAULT NULL,
                    PRIMARY KEY (`x`, `y`)
                )";
                
                $mysqli->query($create_table_sql);
                
                // Check if table was created
                $result = $mysqli->query("SHOW TABLES LIKE 'pixels'");
                $table_exists = $result && $result->num_rows > 0;
                
                $response['mysql_table_creation'] = $table_exists ? 'Table created successfully' : 'Failed to create table';
            }
            
            // Get table information
            if ($table_exists) {
                // Get column information
                $result = $mysqli->query("SHOW COLUMNS FROM `pixels`");
                $columns = [];
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $columns[] = $row['Field'] . ' (' . $row['Type'] . ')';
                    }
                }
                
                // Check for specific columns
                $has_last_updated_column = in_array('last_updated', array_map(function($col) {
                    return explode(' ', $col)[0];
                }, $columns));
                
                $has_ip_column = in_array('ip', array_map(function($col) {
                    return explode(' ', $col)[0];
                }, $columns));
                
                // Get placement timestamps
                $timestamp_data = [];
                try {
                    $timeColumn = $has_last_updated_column ? 'last_updated' : 'timestamp';
                    $result = $mysqli->query("SELECT COUNT(*) as count, DATE_FORMAT($timeColumn, '%Y-%m-%d') as date FROM `pixels` GROUP BY date ORDER BY date DESC LIMIT 5");
                    
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $timestamp_data[] = [
                                'date' => $row['date'],
                                'count' => $row['count']
                            ];
                        }
                    }
                } catch (Exception $e) {
                    $timestamp_data = ['error' => $e->getMessage()];
                }
                
                // Get recent activity
                $recent_activity = [];
                try {
                    $timeColumn = $has_last_updated_column ? 'last_updated' : 'timestamp';
                    $result = $mysqli->query("SELECT COUNT(*) as count FROM `pixels` WHERE $timeColumn > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
                    
                    if ($result && $row = $result->fetch_assoc()) {
                        $recent_activity['last_hour'] = $row['count'];
                    }
                    
                    $result = $mysqli->query("SELECT COUNT(*) as count FROM `pixels` WHERE $timeColumn > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                    
                    if ($result && $row = $result->fetch_assoc()) {
                        $recent_activity['last_24_hours'] = $row['count'];
                    }
                } catch (Exception $e) {
                    $recent_activity = ['error' => $e->getMessage()];
                }
                
                // Get pixel count
                $pixel_count = 0;
                $result = $mysqli->query("SELECT COUNT(*) as count FROM `pixels`");
                if ($result && $row = $result->fetch_assoc()) {
                    $pixel_count = $row['count'];
                }
                
                // Get unique IP count
                $unique_ips = 0;
                if ($has_ip_column) {
                    $result = $mysqli->query("SELECT COUNT(DISTINCT ip) as count FROM `pixels` WHERE ip IS NOT NULL");
                    if ($result && $row = $result->fetch_assoc()) {
                        $unique_ips = $row['count'];
                    }
                }
                
                // Get most active IPs
                $top_ips = [];
                if ($has_ip_column) {
                    try {
                        $result = $mysqli->query("SELECT ip, COUNT(*) as count FROM `pixels` WHERE ip IS NOT NULL GROUP BY ip ORDER BY count DESC LIMIT 5");
                        
                        if ($result) {
                            while ($row = $result->fetch_assoc()) {
                                // Mask IP for privacy
                                $ip_parts = explode('.', $row['ip']);
                                $masked_ip = count($ip_parts) >= 4 ? 
                                    $ip_parts[0] . '.' . $ip_parts[1] . '.*.*' : 
                                    preg_replace('/.{3}$/', '***', $row['ip']);
                                
                                $top_ips[] = [
                                    'ip' => $masked_ip,
                                    'count' => $row['count']
                                ];
                            }
                        }
                    } catch (Exception $e) {
                        $top_ips = ['error' => $e->getMessage()];
                    }
                }
                
                $response['mysql_table_info'] = [
                    'exists' => true,
                    'columns' => $columns,
                    'has_last_updated_column' => $has_last_updated_column,
                    'has_ip_column' => $has_ip_column,
                    'pixel_count' => $pixel_count,
                    'unique_ips' => $unique_ips,
                    'timestamp_data' => $timestamp_data,
                    'recent_activity' => $recent_activity,
                    'top_ips' => $top_ips
                ];
            } else {
                $response['mysql_table_info'] = [
                    'exists' => false
                ];
            }
        } catch (Exception $e) {
            $response['mysql_table_error'] = $e->getMessage();
        }
        
        // Get MySQL version
        $version_result = $mysqli->query('SELECT VERSION() as version');
        if ($version_result && $row = $version_result->fetch_assoc()) {
            $response['mysql_version'] = $row['version'];
        }
        
        // Close connection
        $mysqli->close();
    }
} catch (Exception $e) {
    $response['mysql_error'] = $e->getMessage();
}

// Check for common PHP function availability for the application
$required_functions = [
    'mysqli_connect' => function_exists('mysqli_connect') || class_exists('mysqli'),
    'json_encode' => function_exists('json_encode'),
    'file_get_contents' => function_exists('file_get_contents'),
    'file_put_contents' => function_exists('file_put_contents')
];

$response['required_functions'] = $required_functions;

// Include suggestion for fixing issues
$suggestions = [];

if (!isset($response['mysql_status']) || strpos($response['mysql_status'], 'Connected') === false) {
    $suggestions[] = "Check your database credentials in .env.php file.";
    $suggestions[] = "Make sure the MySQL server is running.";
}

if (isset($response['config_loaded']) && $response['config_loaded'] !== 'Yes') {
    $suggestions[] = "Fix the .env.php file to define the database credentials.";
}

if (isset($response['mysql_table_info']) && (!$response['mysql_table_info']['exists'] || $response['mysql_table_info']['pixel_count'] == 0)) {
    $suggestions[] = "Run the setup.sql script to create the database and tables.";
    $suggestions[] = "Use the db_setup.php page to set up the database.";
}

if (count($suggestions) > 0) {
    $response['suggestions'] = $suggestions;
}

// Return the response as JSON
echo json_encode($response, JSON_PRETTY_PRINT); 