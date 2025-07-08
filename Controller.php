<?php
class Controller {
    private $db;
    private $config;

    public function __construct($config) {
        $this->config = $config;
        $this->db = new Database($config);
    }

    public function getAllPixels() {
        return $this->db->getAllPixels();
    }

    public function getTilePixels($tileX, $tileY) {
        return $this->db->getTilePixels($tileX, $tileY);
    }

    public function getUpdatedTiles($timestamp) {
        return $this->db->getUpdatedTiles($timestamp);
    }

    public function getUpdatedPixels($timestamp) {
        return $this->db->getUpdatedPixels($timestamp);
    }

    public function getLastBoardUpdate() {
        return $this->db->getLastBoardUpdate();
    }

    public function getTileInfo() {
        return $this->db->getTileInfo();
    }

    public function setPixelColor($x, $y, $r, $g, $b, $ip) {
        // Validate coordinates
        if ($x < 0 || $x >= 1024 || $y < 0 || $y >= 1024) {
            throw new Exception('Invalid coordinates');
        }

        // Validate colors
        if ($r < 0 || $r > 255 || $g < 0 || $g > 255 || $b < 0 || $b > 255) {
            throw new Exception('Invalid color values');
        }

        // Update pixel and stats
        $this->db->beginTransaction();
        try {
            $this->db->setPixel($x, $y, $r, $g, $b, $ip);
            $this->db->updateUserStats($ip);
            $this->db->commit();
            
            // Get updated stats
            $stats = $this->getUserStats($ip);
            return [
                'success' => true,
                'stats' => $stats
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function getUserStats($ip) {
        $stats = $this->db->getUserStats($ip);
        $totalPixelsPlaced = $stats['pixels_placed'] ?? 0;
        $totalPixelsOnBoard = $this->db->getTotalPixels();
        $percentage = $totalPixelsOnBoard > 0 ? 
            round(($totalPixelsPlaced / $totalPixelsOnBoard) * 100, 2) : 0;
        
        return [
            'user_pixels' => $totalPixelsPlaced,
            'total_pixels' => $totalPixelsOnBoard,
            'percentage' => $percentage
        ];
    }

    public function scrambleCanvas() {
        try {
            // Get board info to determine size
            $tileInfo = $this->getTileInfo();
            $maxX = 1023; // Set to 1024x1024 board
            $maxY = 1023;
            
            // Generate static noise for a subset of pixels to avoid memory issues
            $pixels = [];
            $chunkSize = 1000;
            $timestamp = time();
            
            // Clear the database first
            $this->db->pdo->beginTransaction();
            $this->db->pdo->exec("DELETE FROM pixels");
            $this->db->pdo->exec("DELETE FROM tile_updates");
            
            // Prepare the insert statement
            $stmt = $this->db->pdo->prepare("
                INSERT INTO pixels (x, y, r, g, b, ip_address, last_updated) 
                VALUES (:x, :y, :r, :g, :b, 'SYSTEM', :time)
            ");
            
            // Insert tiles in a grid pattern instead of all at once
            for ($tileX = 0; $tileX <= 7; $tileX++) { // 1024/128 = 8 tiles
                for ($tileY = 0; $tileY <= 7; $tileY++) {
                    // Generate and insert all pixels for this tile
                    for ($x = $tileX * 128; $x < ($tileX + 1) * 128 && $x <= $maxX; $x++) {
                        for ($y = $tileY * 128; $y < ($tileY + 1) * 128 && $y <= $maxY; $y++) {
                            $isWhite = rand(0, 1) === 1;
                            $color = $isWhite ? 255 : 0;
                            
                            $stmt->execute([
                                ':x' => $x,
                                ':y' => $y,
                                ':r' => $color,
                                ':g' => $color,
                                ':b' => $color,
                                ':time' => $timestamp
                            ]);
                        }
                    }
                    
                    // Add a tile update entry
                    $stmtTile = $this->db->pdo->prepare("
                        INSERT INTO tile_updates (tile_x, tile_y, last_updated)
                        VALUES (:tile_x, :tile_y, :time)
                    ");
                    
                    $stmtTile->execute([
                        ':tile_x' => $tileX,
                        ':tile_y' => $tileY,
                        ':time' => $timestamp
                    ]);
                    
                    // Commit each tile to avoid memory issues
                    $this->db->pdo->commit();
                    $this->db->pdo->beginTransaction();
                }
            }
            
            $this->db->pdo->commit();
            return ['success' => true, 'message' => 'Canvas scrambled to 1024x1024'];
            
        } catch (Exception $e) {
            $this->db->pdo->rollBack();
            error_log("Scramble error: " . $e->getMessage());
            throw new Exception("Failed to scramble canvas: " . $e->getMessage());
        }
    }

    public function getActiveUsers() {
        return $this->db->getActiveUsers();
    }

    public function getTopContributor() {
        return $this->db->getTopContributor();
    }
}