<?php
/**
 * Database Setup Script for Pixel Canvas
 * This script helps set up the database and tables for the pixel canvas application.
 */

// Set content type to HTML
header('Content-Type: text/html; charset=utf-8');

// Start session for auth
session_start();

// Check if we're handling an authentication attempt
$auth_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'authenticate') {
    $submitted_password = $_POST['admin_password'] ?? '';
    
    // A simple hardcoded password for example - in real applications, use a proper authentication system
    $admin_password = 'pixeladmin';
    
    if ($submitted_password === $admin_password) {
        $_SESSION['db_setup_authenticated'] = true;
    } else {
        $auth_error = 'Invalid password. Please try again.';
    }
}

// Check if authenticated
$is_authenticated = isset($_SESSION['db_setup_authenticated']) && $_SESSION['db_setup_authenticated'] === true;

// Load configuration only if authenticated
$db_host = '';
$db_name = '';
$db_user = '';
$db_pass = '';
$connection_status = [
    'success' => false,
    'message' => 'Not connected'
];
$messages = [];

if ($is_authenticated) {
    // Load configuration
    $config = include('.env.php');

    // Get database parameters from config or use defaults
    $db_host = defined('DB_HOST') ? DB_HOST : ($config['db_host'] ?? 'localhost');
    $db_name = defined('DB_NAME') ? DB_NAME : ($config['db_name'] ?? 'pxllat');
    $db_user = defined('DB_USER') ? DB_USER : ($config['db_user'] ?? 'root');
    $db_pass = defined('DB_PASS') ? DB_PASS : ($config['db_pass'] ?? '');

    // Handle form submission to update database credentials
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_credentials') {
        $new_host = $_POST['db_host'] ?? $db_host;
        $new_name = $_POST['db_name'] ?? $db_name;
        $new_user = $_POST['db_user'] ?? $db_user;
        $new_pass = $_POST['db_pass'] ?? $db_pass;
        
        // Update .env.php file
        $env_content = <<<PHP
<?php
/**
 * Database Configuration
 * This file defines database connection parameters and canvas settings.
 * It supports both array return and direct constants format.
 */

// Database connection parameters
define('DB_HOST', '{$new_host}');
define('DB_NAME', '{$new_name}');
define('DB_USER', '{$new_user}');
define('DB_PASS', '{$new_pass}');

// Canvas configuration
define('CANVAS_WIDTH', 1000);
define('CANVAS_HEIGHT', 1000);
define('TILE_SIZE', 32);

// Rate limiting (in seconds)
define('RATE_LIMIT_SECONDS', 0); // Set to 0 to disable rate limiting

// Function to get configuration as an array (for backward compatibility)
function getConfig() {
    return [
        'db_host' => DB_HOST,
        'db_name' => DB_NAME,
        'db_user' => DB_USER,
        'db_pass' => DB_PASS,
        'canvas_width' => CANVAS_WIDTH,
        'canvas_height' => CANVAS_HEIGHT,
        'tile_size' => TILE_SIZE,
        'rate_limit_seconds' => RATE_LIMIT_SECONDS
    ];
}

// Return config array for legacy code that expects it
return getConfig();
PHP;

        // Write to file
        if (file_put_contents('.env.php', $env_content)) {
            $messages[] = [
                'type' => 'success',
                'text' => 'Database credentials updated successfully!'
            ];
            
            // Update variables
            $db_host = $new_host;
            $db_name = $new_name;
            $db_user = $new_user;
            $db_pass = $new_pass;
        } else {
            $messages[] = [
                'type' => 'error',
                'text' => 'Failed to update .env.php file. Check file permissions.'
            ];
        }
    }

    // Handle database setup request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'setup_database') {
        // Try to connect to MySQL server
        try {
            // Connect to server
            $mysqli = new mysqli($db_host, $db_user, $db_pass);
            
            if ($mysqli->connect_error) {
                throw new Exception("Connection failed: " . $mysqli->connect_error);
            }
            
            $messages[] = [
                'type' => 'info',
                'text' => 'Connected to MySQL server successfully.'
            ];
            
            // Check if database exists
            $result = $mysqli->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db_name'");
            $database_exists = $result && $result->num_rows > 0;
            
            if (!$database_exists) {
                // Create database
                if ($mysqli->query("CREATE DATABASE IF NOT EXISTS `$db_name`")) {
                    $messages[] = [
                        'type' => 'success',
                        'text' => "Database '$db_name' created successfully."
                    ];
                } else {
                    throw new Exception("Error creating database: " . $mysqli->error);
                }
            } else {
                $messages[] = [
                    'type' => 'info',
                    'text' => "Database '$db_name' already exists."
                ];
            }
            
            // Select database
            $mysqli->select_db($db_name);
            
            // Check if table exists
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
                
                if ($mysqli->query($create_table_sql)) {
                    $messages[] = [
                        'type' => 'success',
                        'text' => "Table 'pixels' created successfully."
                    ];
                } else {
                    throw new Exception("Error creating table: " . $mysqli->error);
                }
            } else {
                $messages[] = [
                    'type' => 'info',
                    'text' => "Table 'pixels' already exists."
                ];
                
                // Check if ip column exists
                $result = $mysqli->query("SHOW COLUMNS FROM `pixels` LIKE 'ip'");
                $ip_column_exists = $result && $result->num_rows > 0;
                
                if (!$ip_column_exists) {
                    // Add ip column
                    if ($mysqli->query("ALTER TABLE `pixels` ADD COLUMN `ip` VARCHAR(45) DEFAULT NULL")) {
                        $messages[] = [
                            'type' => 'success',
                            'text' => "Added 'ip' column to pixels table."
                        ];
                    } else {
                        $messages[] = [
                            'type' => 'error',
                            'text' => "Error adding 'ip' column: " . $mysqli->error
                        ];
                    }
                } else {
                    $messages[] = [
                        'type' => 'info',
                        'text' => "Column 'ip' already exists in pixels table."
                    ];
                }
                
                // Get pixel count
                $result = $mysqli->query("SELECT COUNT(*) AS count FROM `pixels`");
                if ($result && $row = $result->fetch_assoc()) {
                    $pixel_count = $row['count'];
                    $messages[] = [
                        'type' => 'info',
                        'text' => "Current pixel count: $pixel_count"
                    ];
                }
            }
            
            $messages[] = [
                'type' => 'success',
                'text' => "Database setup completed successfully!"
            ];
            
            // Close connection
            $mysqli->close();
            
        } catch (Exception $e) {
            $messages[] = [
                'type' => 'error',
                'text' => "Database Error: " . $e->getMessage()
            ];
        }
    }

    // Handle database reset request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_database') {
        // Try to connect to MySQL server
        try {
            // Connect to server
            $mysqli = new mysqli($db_host, $db_user, $db_pass);
            
            if ($mysqli->connect_error) {
                throw new Exception("Connection failed: " . $mysqli->connect_error);
            }
            
            // Check if database exists
            $result = $mysqli->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db_name'");
            
            if ($result && $result->num_rows > 0) {
                // Select database
                $mysqli->select_db($db_name);
                
                // Check if table exists
                $result = $mysqli->query("SHOW TABLES LIKE 'pixels'");
                
                if ($result && $result->num_rows > 0) {
                    // Truncate table
                    if ($mysqli->query("TRUNCATE TABLE `pixels`")) {
                        $messages[] = [
                            'type' => 'success',
                            'text' => "Pixel data has been reset successfully."
                        ];
                    } else {
                        throw new Exception("Error truncating pixels table: " . $mysqli->error);
                    }
                } else {
                    $messages[] = [
                        'type' => 'warning',
                        'text' => "Table 'pixels' does not exist. Nothing to reset."
                    ];
                }
            } else {
                $messages[] = [
                    'type' => 'warning',
                    'text' => "Database '$db_name' does not exist. Nothing to reset."
                ];
            }
            
            // Close connection
            $mysqli->close();
            
        } catch (Exception $e) {
            $messages[] = [
                'type' => 'error',
                'text' => "Database Error: " . $e->getMessage()
            ];
        }
    }

    // Check connection status
    $connection_status = [
        'success' => false,
        'message' => 'Not connected'
    ];

    try {
        $mysqli = new mysqli($db_host, $db_user, $db_pass);
        
        if (!$mysqli->connect_error) {
            $connection_status = [
                'success' => true,
                'message' => 'Connected to MySQL server'
            ];
            
            // Check if database exists
            $result = $mysqli->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db_name'");
            $database_exists = $result && $result->num_rows > 0;
            
            if ($database_exists) {
                $mysqli->select_db($db_name);
                $connection_status['message'] .= " and database '$db_name'";
                
                // Check if table exists
                $result = $mysqli->query("SHOW TABLES LIKE 'pixels'");
                $table_exists = $result && $result->num_rows > 0;
                
                if ($table_exists) {
                    $connection_status['message'] .= ", table 'pixels' exists";
                    
                    // Get pixel count
                    $result = $mysqli->query("SELECT COUNT(*) AS count FROM `pixels`");
                    if ($result && $row = $result->fetch_assoc()) {
                        $pixel_count = $row['count'];
                        $connection_status['message'] .= ", contains $pixel_count pixels";
                    }
                } else {
                    $connection_status['message'] .= ", table 'pixels' does not exist";
                }
            } else {
                $connection_status['message'] .= ", database '$db_name' does not exist";
            }
            
            $mysqli->close();
        } else {
            $connection_status = [
                'success' => false,
                'message' => 'MySQL connection failed: ' . $mysqli->connect_error
            ];
        }
    } catch (Exception $e) {
        $connection_status = [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}

// Handle logout request
if (isset($_GET['logout'])) {
    unset($_SESSION['db_setup_authenticated']);
    header('Location: db_setup.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup - Pixel Canvas</title>
    <style>
        :root {
            --primary-color: #3498db;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --error-color: #e74c3c;
            --info-color: #3498db;
            --bg-color: #171717;
            --text-color: #ffffff;
            --card-bg: #2a2a2a;
            --border-color: #444;
            --hover-color: #3d3d3d;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }
        
        .container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        
        h1, h2, h3 {
            margin-top: 0;
        }
        
        .card {
            background-color: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .card-header {
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }
        
        button, .button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
        }
        
        button:hover, .button:hover {
            background-color: #2980b9;
        }
        
        button:disabled, .button:disabled {
            background-color: #aaa;
            cursor: not-allowed;
        }
        
        .button-danger {
            background-color: var(--error-color);
        }
        
        .button-danger:hover {
            background-color: #c0392b;
        }
        
        .message {
            padding: 10px 15px;
            margin-bottom: 10px;
            border-radius: 4px;
        }
        
        .message-success { background-color: rgba(46, 204, 113, 0.2); border-left: 4px solid var(--success-color); }
        .message-error { background-color: rgba(231, 76, 60, 0.2); border-left: 4px solid var(--error-color); }
        .message-warning { background-color: rgba(243, 156, 18, 0.2); border-left: 4px solid var(--warning-color); }
        .message-info { background-color: rgba(52, 152, 219, 0.2); border-left: 4px solid var(--info-color); }
        
        .status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 14px;
            margin-top: 8px;
        }
        
        .status-success { background-color: var(--success-color); }
        .status-error { background-color: var(--error-color); }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 8px;
            margin-bottom: 15px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            background-color: #333;
            color: var(--text-color);
        }
        
        .button-row {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .auth-form {
            max-width: 400px;
            margin: 0 auto;
        }
        
        .hidden {
            display: none;
        }
        
        .confirm-panel {
            margin-top: 15px;
            padding: 15px;
            background-color: rgba(231, 76, 60, 0.1);
            border: 1px solid var(--error-color);
            border-radius: 4px;
        }
        
        .footer {
            text-align: center;
            margin-top: 40px;
            font-size: 14px;
            color: #888;
        }
        
        .header-button {
            float: right;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Pixel Canvas - Database Setup</h1>
        
        <?php if (!$is_authenticated): ?>
            <!-- Authentication Form -->
            <div class="card">
                <div class="card-header">
                    <h2>Authentication Required</h2>
                </div>
                <div class="auth-form">
                    <p>Please enter the administrator password to access the database setup.</p>
                    
                    <?php if ($auth_error): ?>
                        <div class="message message-error">
                            <?php echo $auth_error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="authenticate">
                        
                        <label for="admin_password">Administrator Password</label>
                        <input type="password" id="admin_password" name="admin_password" required autofocus>
                        
                        <button type="submit">Login</button>
                    </form>
                </div>
            </div>
            
            <!-- Back to Canvas -->
            <div class="card">
                <a href="index.html" class="button">Return to Canvas</a>
            </div>
        <?php else: ?>
            <!-- Authenticated view -->
            
            <!-- Connection Status -->
            <div class="card">
                <div class="card-header">
                    <h2>Connection Status</h2>
                    <a href="?logout=1" class="button header-button">Logout</a>
                </div>
                <div>
                    <p>
                        <strong>Status:</strong> 
                        <span class="status <?php echo $connection_status['success'] ? 'status-success' : 'status-error'; ?>">
                            <?php echo $connection_status['success'] ? 'Connected' : 'Disconnected'; ?>
                        </span>
                    </p>
                    <p><?php echo $connection_status['message']; ?></p>
                </div>
            </div>
            
            <!-- Messages -->
            <?php if (count($messages) > 0): ?>
                <div class="card">
                    <div class="card-header">
                        <h2>Messages</h2>
                    </div>
                    <?php foreach ($messages as $message): ?>
                        <div class="message message-<?php echo $message['type']; ?>">
                            <?php echo $message['text']; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Database Credentials -->
            <div class="card">
                <div class="card-header">
                    <h2>Database Credentials</h2>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="update_credentials">
                    
                    <label for="db_host">Database Host</label>
                    <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($db_host); ?>" required>
                    
                    <label for="db_name">Database Name</label>
                    <input type="text" id="db_name" name="db_name" value="<?php echo htmlspecialchars($db_name); ?>" required>
                    
                    <label for="db_user">Database User</label>
                    <input type="text" id="db_user" name="db_user" value="<?php echo htmlspecialchars($db_user); ?>" required>
                    
                    <label for="db_pass">Database Password</label>
                    <input type="password" id="db_pass" name="db_pass" value="<?php echo htmlspecialchars($db_pass); ?>">
                    
                    <button type="submit">Update Credentials</button>
                </form>
            </div>
            
            <!-- Database Setup -->
            <div class="card">
                <div class="card-header">
                    <h2>Database Actions</h2>
                </div>
                <div>
                    <p>Use these buttons to set up or reset the database:</p>
                    
                    <div class="button-row">
                        <form method="post">
                            <input type="hidden" name="action" value="setup_database">
                            <button type="submit">Set Up Database</button>
                        </form>
                        
                        <button id="show-reset" class="button-danger">Reset Pixel Data</button>
                    </div>
                    
                    <div id="reset-confirm" class="confirm-panel hidden">
                        <p><strong>Warning:</strong> This will delete all pixel data. This action cannot be undone.</p>
                        
                        <div class="button-row">
                            <form method="post">
                                <input type="hidden" name="action" value="reset_database">
                                <button type="submit" class="button-danger">Confirm Reset</button>
                            </form>
                            <button id="cancel-reset">Cancel</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Back to Canvas -->
            <div class="card">
                <a href="index.html" class="button">Return to Canvas</a>
            </div>
        <?php endif; ?>
        
        <div class="footer">
            <p>Pixel Canvas - A collaborative pixel art application</p>
        </div>
    </div>
    
    <script>
        // Toggle reset confirmation panel
        if (document.getElementById('show-reset')) {
            document.getElementById('show-reset').addEventListener('click', function() {
                document.getElementById('reset-confirm').classList.remove('hidden');
                this.classList.add('hidden');
            });
            
            document.getElementById('cancel-reset').addEventListener('click', function() {
                document.getElementById('reset-confirm').classList.add('hidden');
                document.getElementById('show-reset').classList.remove('hidden');
            });
        }
    </script>
</body>
</html> 