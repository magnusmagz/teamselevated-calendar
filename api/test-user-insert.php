<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();

    // Test 1: Simple SELECT
    echo json_encode(['step' => 'Test 1: SELECT']) . "\n";
    $stmt = $connection->prepare("SELECT id FROM users LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch();
    echo json_encode(['result' => $result]) . "\n";

    // Test 2: Check if specific email exists
    echo json_encode(['step' => 'Test 2: Check email exists']) . "\n";
    $stmt = $connection->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute(['test123@example.com']);
    $result = $stmt->fetch();
    echo json_encode(['result' => $result]) . "\n";

    // Test 3: Try INSERT in a transaction
    echo json_encode(['step' => 'Test 3: INSERT with transaction']) . "\n";
    $connection->beginTransaction();

    // Try to set row_security off
    try {
        $connection->exec("SET LOCAL row_security = off");
        echo json_encode(['status' => 'RLS disabled']) . "\n";
    } catch (Exception $e) {
        echo json_encode(['warning' => 'Could not disable RLS: ' . $e->getMessage()]) . "\n";
    }

    $testEmail = 'testuser' . time() . '@example.com';
    $stmt = $connection->prepare("
        INSERT INTO users (email, first_name, last_name, system_role)
        VALUES (?, ?, ?, 'user')
        RETURNING id
    ");
    $stmt->execute([$testEmail, 'Test', 'User']);
    $userId = $stmt->fetchColumn();

    $connection->rollBack(); // Don't actually save

    echo json_encode([
        'success' => true,
        'message' => 'Insert test successful',
        'user_id' => $userId,
        'test_email' => $testEmail
    ]) . "\n";

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
