<?php
header('Content-Type: text/plain');

function downloadDatabase() {
    $url = "https://cdn.jsdelivr.net/npm/@ip-location-db/asn-country/asn-country-ipv4.csv";
    $csv = file_get_contents($url);
    if (!$csv) {
        // Fallback URL if first one fails
        $url = "https://raw.githubusercontent.com/sapics/ip-location-db/main/ip2country/ip2country.csv";
        $csv = file_get_contents($url);
        if (!$csv) {
            die("Failed to download IP database from both sources\n");
        }
    }
    return $csv;
}

function populateDatabase() {
    require_once 'Database.php';
    $config = require('.env.php');
    $db = new Database($config);
    
    try {
        // Clear existing data
        $db->pdo->exec("TRUNCATE TABLE ip_to_country");
        
        $csv = downloadDatabase();
        $lines = explode("\n", $csv);
        $count = 0;
        
        $stmt = $db->pdo->prepare("
            INSERT INTO ip_to_country (ip_start, country_code) 
            VALUES (:ip_start, :country_code)
        ");
        
        foreach ($lines as $line) {
            if (empty(trim($line)) || strpos($line, '#') === 0) continue;
            
            $fields = str_getcsv($line);
            if (count($fields) < 3) continue;
            
            $start_ip = $fields[0];
            $country_code = $fields[2];
            
            if (!$start_ip || !$country_code || strlen($country_code) !== 2) continue;
            
            try {
                $stmt->execute([
                    ':ip_start' => ip2long($start_ip),
                    ':country_code' => strtoupper($country_code)
                ]);
                $count++;
                if ($count % 1000 === 0) {
                    echo "Processed $count entries...\n";
                }
            } catch (Exception $e) {
                echo "Error on $start_ip: " . $e->getMessage() . "\n";
            }
        }
        
        echo "Successfully added $count IP ranges\n";
        
    } catch (Exception $e) {
        die("Error: " . $e->getMessage() . "\n");
    }
}

// Run the update
echo "Starting IP database update...\n";
populateDatabase();
echo "Done!\n"; 