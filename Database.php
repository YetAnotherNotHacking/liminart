<?php
class Database {
    private $pdo;

    public function __construct($config) {
        try {
            $this->pdo = new PDO(
                "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']}",
                $config['DB_USER'],
                $config['DB_PASS']
            );
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new Exception("Connection failed: " . $e->getMessage());
        }
    }

    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollback() {
        return $this->pdo->rollBack();
    }

    public function getAllPixels() {
        try {
            $stmt = $this->pdo->query("
                SELECT x, y, r, g, b 
                FROM pixels 
                ORDER BY last_updated DESC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in getAllPixels: " . $e->getMessage());
            return [];
        }
    }

    public function getTilePixels($tileX, $tileY) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT x, y, r, g, b 
                FROM pixels 
                WHERE tile_x = :tile_x AND tile_y = :tile_y
                ORDER BY last_updated DESC
            ");
            $stmt->execute([
                ':tile_x' => $tileX,
                ':tile_y' => $tileY
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in getTilePixels: " . $e->getMessage());
            return [];
        }
    }

    public function getUpdatedTiles($timestamp) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT tile_x, tile_y
                FROM pixels
                WHERE last_updated > :timestamp
                ORDER BY last_updated DESC
            ");
            $stmt->execute([':timestamp' => $timestamp]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in getUpdatedTiles: " . $e->getMessage());
            return [];
        }
    }

    public function getUpdatedPixels($timestamp) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT x, y, r, g, b, tile_x, tile_y
                FROM pixels
                WHERE last_updated > :timestamp
                ORDER BY last_updated DESC
            ");
            $stmt->execute([':timestamp' => $timestamp]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in getUpdatedPixels: " . $e->getMessage());
            return [];
        }
    }

    public function setPixel($x, $y, $r, $g, $b, $ip) {
        $timestamp = time();
        
        // Start a transaction to ensure atomic update
        $this->pdo->beginTransaction();
        
        try {
            // Insert/update the pixel
            $stmt = $this->pdo->prepare(
                "INSERT INTO pixels (x, y, r, g, b, last_updated, ip_address) 
                VALUES (:x, :y, :r, :g, :b, :time, :ip)
                ON DUPLICATE KEY UPDATE 
                    r = VALUES(r), 
                    g = VALUES(g), 
                    b = VALUES(b), 
                    last_updated = VALUES(last_updated),
                    ip_address = VALUES(ip_address)"
            );
            
            $stmt->execute([
                ':x' => $x,
                ':y' => $y,
                ':r' => $r,
                ':g' => $g,
                ':b' => $b,
                ':time' => $timestamp,
                ':ip' => $ip
            ]);
            
            // Get the tile coordinates for this pixel
            $tileX = floor($x / 128);
            $tileY = floor($y / 128);
            
            // Update the tile_updates table
            $stmt = $this->pdo->prepare(
                "INSERT INTO tile_updates (tile_x, tile_y, last_updated) 
                VALUES (:tile_x, :tile_y, :time)
                ON DUPLICATE KEY UPDATE last_updated = VALUES(last_updated)"
            );
            
            $stmt->execute([
                ':tile_x' => $tileX,
                ':tile_y' => $tileY,
                ':time' => $timestamp
            ]);
            
            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Database error in setPixel: " . $e->getMessage());
            throw $e;
        }
    }

    public function updateUserStats($ip) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_stats (ip_address, pixels_placed, last_placed) 
            VALUES (:ip, 1, :time)
            ON DUPLICATE KEY UPDATE 
                pixels_placed = pixels_placed + 1,
                last_placed = VALUES(last_placed)"
        );
        
        return $stmt->execute([
            ':ip' => $ip,
            ':time' => time()
        ]);
    }

    public function getUserStats($ip) {
        // Get user's pixel count
        $stmt = $this->pdo->prepare("
            SELECT pixels_placed 
            FROM user_stats 
            WHERE ip_address = :ip
        ");
        $stmt->execute([':ip' => $ip]);
        $userPixels = (int)($stmt->fetchColumn() ?: 0);
        
        // Get total pixels on board
        $totalPixels = $this->getTotalPixels();
        
        return [
            'pixels_placed' => $userPixels,
            'total_pixels' => $totalPixels,
            'percentage' => $totalPixels > 0 ? round(($userPixels / $totalPixels) * 100, 2) : 0
        ];
    }

    public function getLastPlaced($ip) {
        $stmt = $this->pdo->prepare(
            "SELECT last_placed 
            FROM user_stats 
            WHERE ip_address = :ip"
        );
        $stmt->execute([':ip' => $ip]);
        return $stmt->fetchColumn();
    }

    public function getTotalPixels() {
        // Sum all pixels placed by all users
        return (int)$this->pdo->query("
            SELECT SUM(pixels_placed) 
            FROM user_stats
        ")->fetchColumn() ?: 0;
    }

    public function getPixel($x, $y) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM pixels 
            WHERE x = :x AND y = :y
        ");
        $stmt->execute([':x' => $x, ':y' => $y]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getCurrentUserPixelCount($ip) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM pixels 
            WHERE ip_address = :ip
        ");
        $stmt->execute([':ip' => $ip]);
        return (int)$stmt->fetchColumn();
    }

    public function getActiveUsers() {
        // Clean up old sessions first (older than 30 seconds)
        $this->pdo->exec("DELETE FROM active_users WHERE last_seen < " . (time() - 30));
        
        // Update or insert current user
        $stmt = $this->pdo->prepare("
            INSERT INTO active_users (ip_address, last_seen) 
            VALUES (:ip, :time)
            ON DUPLICATE KEY UPDATE last_seen = :time
        ");
        $stmt->execute([
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':time' => time()
        ]);
        
        // Get count of active users
        return $this->pdo->query("SELECT COUNT(*) FROM active_users")->fetchColumn();
    }

    public function getTopContributor() {
        $stmt = $this->pdo->prepare("
            SELECT 
                CONCAT(
                    SUBSTRING_INDEX(ip_address, '.', 2),
                    '.xxx.xxx'
                ) as partial_ip,
                pixels_placed,
                CASE
                    WHEN SUBSTRING(ip_address, 1, 3) = '192' THEN 'Local'
                    WHEN SUBSTRING(ip_address, 1, 3) = '172' THEN 'Local'
                    WHEN SUBSTRING(ip_address, 1, 3) = '10.' THEN 'Local'
                END as country
            FROM user_stats 
            ORDER BY pixels_placed DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'partial_ip' => $result['partial_ip'] ?? 'unknown.xxx.xxx',
            'pixels' => $result['pixels_placed'] ?? 0,
            'country' => $result['country'] ?? 'unknown'
        ];
    }

    public function getLastBoardUpdate() {
        try {
            return (int)$this->pdo->query("
                SELECT MAX(last_updated) FROM pixels
            ")->fetchColumn() ?: 0;
        } catch (PDOException $e) {
            error_log("Database error in getLastBoardUpdate: " . $e->getMessage());
            return 0;
        }
    }

    public function getTileInfo() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    MIN(x) as min_x,
                    MAX(x) as max_x,
                    MIN(y) as min_y,
                    MAX(y) as max_y,
                    MAX(tile_x) as max_tile_x,
                    MAX(tile_y) as max_tile_y
                FROM pixels
            ");
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result || $result['max_tile_x'] === null) {
                // No data yet, return default dimensions
                return [
                    'min_x' => 0,
                    'max_x' => 1023,
                    'min_y' => 0,
                    'max_y' => 1023,
                    'max_tile_x' => 7, // 1024/128 - 1 = 7
                    'max_tile_y' => 7,
                    'tile_width' => 128,
                    'tile_height' => 128
                ];
            }
            
            $result['tile_width'] = 128;
            $result['tile_height'] = 128;
            
            return $result;
        } catch (PDOException $e) {
            error_log("Database error in getTileInfo: " . $e->getMessage());
            return [
                'min_x' => 0,
                'max_x' => 1023,
                'min_y' => 0,
                'max_y' => 1023,
                'max_tile_x' => 7,
                'max_tile_y' => 7,
                'tile_width' => 128,
                'tile_height' => 128
            ];
        }
    }
}