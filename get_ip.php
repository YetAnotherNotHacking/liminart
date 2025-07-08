<?php
header('Content-Type: application/json');

$ip = $_SERVER['REMOTE_ADDR'];
// For privacy, only show first 3 octets
$ip_parts = explode('.', $ip);
$ip_parts[3] = 'xxx';
$masked_ip = implode('.', $ip_parts);

echo json_encode(['ip' => $masked_ip]); 