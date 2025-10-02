<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3003');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../../config/database.php';
require_once '../../controllers/SeasonController.php';

$controller = new SeasonController();
$controller->create();