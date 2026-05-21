<?php
require_once 'admin_config.php';
require_admin();

header('Content-Type: application/json');

$host = trim($_GET['host'] ?? '');
$port = max(1, min(65535, (int)($_GET['port'] ?? 22)));

if (!$host) {
    echo json_encode(['status' => 'unknown']);
    exit;
}

$fp = @fsockopen($host, $port, $errno, $errstr, 3);
if ($fp) {
    fclose($fp);
    echo json_encode(['status' => 'online']);
} else {
    echo json_encode(['status' => 'offline', 'error' => $errstr]);
}
