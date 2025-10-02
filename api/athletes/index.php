<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3003');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../controllers/AthleteController.php';

session_start();

$controller = new AthleteController();

$method = $_SERVER['REQUEST_METHOD'];
$pathInfo = $_SERVER['PATH_INFO'] ?? '';

switch ($method) {
    case 'GET':
        if (preg_match('/^\/(\d+)$/', $pathInfo, $matches)) {
            $controller->getAthlete($matches[1]);
        } else {
            $controller->getAthletes();
        }
        break;

    case 'POST':
        $controller->createAthlete();
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}