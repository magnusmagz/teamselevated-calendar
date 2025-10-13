<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
require_once __DIR__ . '/../config/database.php';
try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Get registrations for a program
            $program_id = $_GET['program_id'] ?? 0;

            $stmt = $connection->prepare("
                SELECT r.*, p.name as program_name
                FROM registrations r
                LEFT JOIN programs p ON r.program_id = p.id
                WHERE r.program_id = ?
                ORDER BY r.submitted_at DESC
            ");
            $stmt->execute([$program_id]);
            $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSON form data
            foreach ($registrations as &$registration) {
                $registration['form_data'] = json_decode($registration['form_data'], true);
            }

            echo json_encode($registrations);
            break;

        case 'POST':
            // Submit new registration
            $data = json_decode(file_get_contents("php://input"), true);

            // Validate program exists and is open for registration
            $stmt = $connection->prepare("
                SELECT id, status, registration_closes
                FROM programs
                WHERE id = ? AND status = 'published'
            ");
            $stmt->execute([$data['program_id']]);
            $program = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$program) {
                http_response_code(400);
                echo json_encode(['error' => 'Program not available for registration']);
                exit();
            }

            // Check if registration is still open
            if ($program['registration_closes']) {
                $closes = new DateTime($program['registration_closes']);
                $now = new DateTime();
                if ($now > $closes) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Registration period has ended']);
                    exit();
                }
            }

            // Insert registration
            $stmt = $connection->prepare("
                INSERT INTO registrations (
                    program_id, form_data, status, submitted_at
                ) VALUES (?, ?, 'pending', NOW())
            ");

            $stmt->execute([
                $data['program_id'],
                json_encode($data['form_data'])
            ]);

            $registration_id = $connection->lastInsertId();

            // Send confirmation email (optional - implement later)
            // sendConfirmationEmail($data['form_data']);

            echo json_encode([
                'success' => true,
                'id' => $registration_id,
                'message' => 'Registration submitted successfully'
            ]);
            break;

        case 'PUT':
            // Update registration status
            $registration_id = $_GET['id'] ?? 0;
            $data = json_decode(file_get_contents("php://input"), true);

            $stmt = $connection->prepare("
                UPDATE registrations
                SET status = ?, reviewed_at = NOW(), reviewed_by = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $data['status'],
                $data['reviewed_by'] ?? null,
                $registration_id
            ]);

            echo json_encode(['success' => true, 'message' => 'Registration updated']);
            break;

        case 'DELETE':
            // Delete registration
            $registration_id = $_GET['id'] ?? 0;

            $stmt = $connection->prepare("DELETE FROM registrations WHERE id = ?");
            $stmt->execute([$registration_id]);

            echo json_encode(['success' => true, 'message' => 'Registration deleted']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>