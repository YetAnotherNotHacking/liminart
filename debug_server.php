<?php
// Basic PHP debugging information script for the pixel canvas
header('Content-Type: text/html; charset=utf-8');

// Show all errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<html><head><title>PHP Canvas Server Debug</title>";
echo '<style>
    body { font-family: monospace; line-height: 1.5; background: #222; color: #eee; padding: 20px; }
    h1, h2 { color: #4a9eff; }
    .error { color: #ff4444; }
    .success { color: #44ff44; }
    .warning { color: #ffaa00; }
    div { margin-bottom: 20px; }
    pre { background: #333; padding: 10px; overflow-x: auto; border-radius: 4px; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { text-align: left; padding: 8px; border-bottom: 1px solid #444; }
    th { background-color: #333; }
    .status-box { padding: 10px; border-radius: 4px; margin: 10px 0; }
    .env-file { background: #444; padding: 10px; border-radius: 4px; margin: 10px 0; }
    button { background: #4a9eff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; margin-right: 10px; }
    button.fix { background: #44ff44; }
    .hidden { display: none; }
    textarea { width: 100%; height: 200px; background: #333; color: #eee; border: 1px solid #555; padding: 10px; border-radius: 4px; }
</style>';
echo "</head><body>";
echo "<h1>PHP Canvas Server Debug</h1>";

// Basic PHP information
echo "<h2>PHP Information</h2>";
echo "<div><strong>PHP Version:</strong> " . phpversion() . "</div>";
echo "<div><strong>Server Software:</strong> " . $_SERVER['SERVER_SOFTWARE'] . "</div>";
echo "<div><strong>Document Root:</strong> " . $_SERVER['DOCUMENT_ROOT'] . "</div>";
echo "<div><strong>Script Path:</strong> " . $_SERVER['SCRIPT_FILENAME'] . "</div>";

// Check common requirements
echo "<h2>Requirements Check</h2>";
echo "<div>";
$requirements = [
    "PHP >= 7.0" => version_compare(PHP_VERSION, '7.0.0') >= 0,
    "PDO Extension" => extension_loaded('pdo'),
    "PDO MySQL" => extension_loaded('pdo_mysql'),
    "JSON Extension" => extension_loaded('json'),
    "GD Library" => extension_loaded('gd'),
    "File Permissions" => is_writable('.') 
];

echo "<table>";
echo "<tr><th>Requirement</th><th>Status</th></tr>";
foreach ($requirements as $req => $status) {
    echo "<tr>";
    echo "<td>$req</td>";
    echo "<td>" . ($status ? '<span class="success">✓ Passed</span>' : '<span class="error">✗ Failed</span>') . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// Check for .env file
echo "<h2>Environment Configuration</h2>";
$envFile = '.env.php';
$envTemplate = <<<'EOT'
<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'pixelcanvas');
define('DB_USER', 'canvasuser');
define('DB_PASS', 'yourpassword');

// Canvas settings
define('CANVAS_WIDTH', 1024);
define('CANVAS_HEIGHT', 1024);
define('TILE_SIZE', 128);

// Security
define('ENABLE_RATE_LIMIT', true);
define('RATE_LIMIT_SECONDS', 5);
EOT;

if (file_exists($envFile)) {
    echo "<div class='status-box success'><strong>✓ .env.php file exists</strong></div>";
    
    // Load the env file contents without executing it
    $envContents = htmlspecialchars(file_get_contents($envFile));
    echo "<div class='env-file'><pre>$envContents</pre></div>";
    
    // Actually include it to test 
    include_once($envFile);
    
    echo "<div>";
    $configVars = [
        "DB_HOST" => defined('DB_HOST') ? DB_HOST : 'Not defined',
        "DB_NAME" => defined('DB_NAME') ? DB_NAME : 'Not defined',
        "DB_USER" => defined('DB_USER') ? DB_USER : 'Not defined',
        "DB_PASS" => defined('DB_PASS') ? '****' : 'Not defined',
        "CANVAS_WIDTH" => defined('CANVAS_WIDTH') ? CANVAS_WIDTH : 'Not defined',
        "CANVAS_HEIGHT" => defined('CANVAS_HEIGHT') ? CANVAS_HEIGHT : 'Not defined',
        "TILE_SIZE" => defined('TILE_SIZE') ? TILE_SIZE : 'Not defined'
    ];
    
    echo "<table>";
    echo "<tr><th>Configuration</th><th>Value</th></tr>";
    foreach ($configVars as $var => $value) {
        echo "<tr>";
        echo "<td>$var</td>";
        echo "<td>" . (defined($var) ? '<span class="success">' . $value . '</span>' : '<span class="error">Not defined</span>') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
} else {
    echo "<div class='status-box error'><strong>✗ .env.php file is missing</strong></div>";
    
    echo "<div>
        <p>The .env.php file is required for database configuration. Create it with the following content:</p>
        <pre>" . htmlspecialchars($envTemplate) . "</pre>
        <div id='createEnvForm'>
            <textarea id='envContent'>" . htmlspecialchars($envTemplate) . "</textarea>
            <button id='createEnvBtn' class='fix'>Create .env.php</button>
            <p><strong>Note:</strong> Update the database credentials to match your environment.</p>
        </div>
    </div>";
}

// Test database connection
echo "<h2>Database Connection Test</h2>";
if (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS') && defined('DB_NAME')) {
    echo "<div>";
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        echo "<div class='status-box success'><strong>✓ Database connection successful</strong></div>";
        
        // Check for tables
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo "<strong>Tables:</strong>";
        echo "<ul>";
        foreach ($tables as $table) {
            echo "<li>$table</li>";
        }
        echo "</ul>";
        
        // Check for pixels table specifically
        if (in_array('pixels', $tables)) {
            echo "<div class='status-box success'><strong>✓ Pixels table exists</strong></div>";
            
            // Get sample data
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM pixels");
            $pixelCount = $stmt->fetch()['count'];
            echo "<div>Total pixels in database: <strong>$pixelCount</strong></div>";
            
            if ($pixelCount > 0) {
                $stmt = $pdo->query("SELECT * FROM pixels ORDER BY id DESC LIMIT 5");
                $recentPixels = $stmt->fetchAll();
                
                echo "<div>Recent pixels:</div>";
                echo "<table>";
                echo "<tr><th>ID</th><th>X</th><th>Y</th><th>Color (RGB)</th><th>Timestamp</th></tr>";
                foreach ($recentPixels as $pixel) {
                    echo "<tr>";
                    echo "<td>{$pixel['id']}</td>";
                    echo "<td>{$pixel['x']}</td>";
                    echo "<td>{$pixel['y']}</td>";
                    echo "<td style='background-color:rgb({$pixel['r']},{$pixel['g']},{$pixel['b']})'>{$pixel['r']},{$pixel['g']},{$pixel['b']}</td>";
                    echo "<td>{$pixel['timestamp']}</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
        } else {
            echo "<div class='status-box error'><strong>✗ Pixels table is missing</strong></div>";
            
            // Show SQL to create pixels table
            $createTableSQL = "CREATE TABLE `pixels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL,
  `r` tinyint(4) NOT NULL,
  `g` tinyint(4) NOT NULL,
  `b` tinyint(4) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `x_y` (`x`,`y`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            
            echo "<div>
                <p>You need to create the pixels table. Run this SQL:</p>
                <pre>$createTableSQL</pre>
                <button id='createTableBtn' class='fix'>Create Table</button>
            </div>";
        }
        
    } catch (PDOException $e) {
        echo "<div class='status-box error'><strong>✗ Database connection failed</strong></div>";
        echo "<pre class='error'>" . $e->getMessage() . "</pre>";
        
        echo "<div>
            <p><strong>Troubleshooting:</strong></p>
            <ol>
                <li>Verify your database credentials in .env.php</li>
                <li>Make sure your MySQL server is running</li>
                <li>Check that the database exists and the user has permissions</li>
                <li>Create the database with: <pre>CREATE DATABASE pixelcanvas;</pre></li>
                <li>Create a database user: <pre>CREATE USER 'canvasuser'@'localhost' IDENTIFIED BY 'yourpassword';</pre></li>
                <li>Grant permissions: <pre>GRANT ALL PRIVILEGES ON pixelcanvas.* TO 'canvasuser'@'localhost';</pre></li>
            </ol>
        </div>";
    }
    echo "</div>";
} else {
    echo "<div class='status-box error'><strong>✗ Database configuration missing</strong></div>";
    echo "<p>Please create the .env.php file with proper database credentials.</p>";
}

// Check API endpoints
echo "<h2>API Endpoint Tests</h2>";

$endpoints = [
    "get_state.php" => "GET",
    "set_pixel.php" => "POST"
];

echo "<div id='endpointTests'>";
foreach ($endpoints as $endpoint => $method) {
    echo "<div>
        <strong>$endpoint</strong> ($method): 
        <button class='testEndpoint' data-endpoint='$endpoint' data-method='$method'>Test</button>
        <div class='endpoint-result' id='result-$endpoint'></div>
    </div>";
}
echo "</div>";

// Add JavaScript for interactive tests
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    // Test endpoint functionality
    const endpointButtons = document.querySelectorAll('.testEndpoint');
    endpointButtons.forEach(button => {
        button.addEventListener('click', async function() {
            const endpoint = this.getAttribute('data-endpoint');
            const method = this.getAttribute('data-method');
            const resultDiv = document.getElementById('result-' + endpoint);
            
            resultDiv.innerHTML = 'Testing...';
            
            try {
                let response;
                if (method === 'GET') {
                    response = await fetch(endpoint);
                } else {
                    // For POST, send a test pixel
                    response = await fetch(endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ x: 0, y: 0, r: 0, g: 0, b: 0 })
                    });
                }
                
                const responseText = await response.text();
                resultDiv.innerHTML = `
                    <div>Status: ${response.status} ${response.statusText}</div>
                    <pre>${responseText}</pre>
                `;
            } catch (error) {
                resultDiv.innerHTML = `<div class='error'>Error: ${error.message}</div>`;
            }
        });
    });
    
    // Create .env.php file
    const createEnvBtn = document.getElementById('createEnvBtn');
    if (createEnvBtn) {
        createEnvBtn.addEventListener('click', async function() {
            const content = document.getElementById('envContent').value;
            
            try {
                const response = await fetch('create_env.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content: content })
                });
                
                const result = await response.text();
                alert(result);
                location.reload();
            } catch (error) {
                alert('Error creating .env.php: ' + error.message);
            }
        });
    }
    
    // Create pixels table
    const createTableBtn = document.getElementById('createTableBtn');
    if (createTableBtn) {
        createTableBtn.addEventListener('click', async function() {
            try {
                const response = await fetch('create_table.php');
                const result = await response.text();
                alert(result);
                location.reload();
            } catch (error) {
                alert('Error creating table: ' + error.message);
            }
        });
    }
});
</script>";

echo "</body></html>"; 