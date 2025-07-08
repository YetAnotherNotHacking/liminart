<?php
header('Content-Type: application/json');

$db = new SQLite3('pixels.db');

// Get hot regions
$stmt = $db->prepare('
    SELECT region_x, region_y, changes 
    FROM statistics 
    WHERE last_hour >= :hour_ago 
    ORDER BY changes DESC 
    LIMIT 5
');

$stmt->bindValue(':hour_ago', time() - 3600, SQLITE3_INTEGER);
$result = $stmt->execute();

$hotRegions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $hotRegions[] = [
        'x' => $row['region_x'],
        'y' => $row['region_y'],
        'changes' => $row['changes']
    ];
}

echo json_encode([
    'hotRegions' => $hotRegions
]); 