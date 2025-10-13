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
$path = $_GET['path'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($path === 'list') {
                // Get all programs for a club
                $club_id = $_GET['club_id'] ?? 1; // Default to club 1 for now
                $stmt = $connection->prepare("
                    SELECT p.*,
                           (SELECT COUNT(*) FROM registrations WHERE program_id = p.id AND status != 'rejected') as registration_count
                    FROM programs p
                    WHERE p.club_id = ?
                    ORDER BY p.created_at DESC
                ");
                $stmt->execute([$club_id]);
                $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($programs);
            } elseif ($path === 'details') {
                // Get single program with form fields
                $program_id = $_GET['id'] ?? 0;
                $stmt = $connection->prepare("
                    SELECT p.*,
                           c.club_name,
                           c.logo_data as club_logo,
                           c.primary_color as club_primary_color
                    FROM programs p
                    LEFT JOIN club_profile c ON p.club_id = c.id
                    WHERE p.id = ?
                ");
                $stmt->execute([$program_id]);
                $program = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($program) {
                    // Get form fields
                    $stmt = $connection->prepare("
                        SELECT * FROM program_form_fields
                        WHERE program_id = ?
                        ORDER BY section, display_order
                    ");
                    $stmt->execute([$program_id]);
                    $program['form_fields'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode($program);
            } elseif ($path === 'by-embed') {
                // Get program by embed code (for widget)
                $embed_code = $_GET['code'] ?? '';
                $stmt = $connection->prepare("
                    SELECT p.*,
                           c.club_name,
                           c.logo_data as club_logo,
                           c.primary_color as club_primary_color
                    FROM programs p
                    LEFT JOIN club_profile c ON p.club_id = c.id
                    WHERE p.embed_code = ? AND p.status = 'published'
                ");
                $stmt->execute([$embed_code]);
                $program = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($program) {
                    // Get form fields
                    $stmt = $connection->prepare("
                        SELECT * FROM program_form_fields
                        WHERE program_id = ?
                        ORDER BY section, display_order
                    ");
                    $stmt->execute([$program['id']]);
                    $program['form_fields'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode($program);
            }
            break;

        case 'POST':
            if ($path === 'create') {
                $data = json_decode(file_get_contents("php://input"), true);

                // Generate unique embed code
                $embed_code = 'PRG' . strtoupper(bin2hex(random_bytes(8)));

                $stmt = $connection->prepare("
                    INSERT INTO programs (
                        club_id, name, type, description,
                        start_date, end_date, registration_opens, registration_closes,
                        min_age, max_age, capacity, status, embed_code
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $data['club_id'] ?? 1,
                    $data['name'],
                    $data['type'],
                    !empty($data['description']) ? $data['description'] : null,
                    !empty($data['start_date']) ? $data['start_date'] : null,
                    !empty($data['end_date']) ? $data['end_date'] : null,
                    !empty($data['registration_opens']) ? $data['registration_opens'] : null,
                    !empty($data['registration_closes']) ? $data['registration_closes'] : null,
                    !empty($data['min_age']) ? $data['min_age'] : null,
                    !empty($data['max_age']) ? $data['max_age'] : null,
                    !empty($data['capacity']) ? $data['capacity'] : null,
                    $data['status'] ?? 'draft',
                    $embed_code
                ]);

                $program_id = $connection->lastInsertId();

                // Add default form fields
                $default_fields = [
                    ['field_name' => 'athlete_first_name', 'field_label' => 'Athlete First Name', 'field_type' => 'text', 'required' => true, 'section' => 'athlete_info', 'display_order' => 1],
                    ['field_name' => 'athlete_last_name', 'field_label' => 'Athlete Last Name', 'field_type' => 'text', 'required' => true, 'section' => 'athlete_info', 'display_order' => 2],
                    ['field_name' => 'athlete_dob', 'field_label' => 'Date of Birth', 'field_type' => 'date', 'required' => true, 'section' => 'athlete_info', 'display_order' => 3],
                    ['field_name' => 'parent_name', 'field_label' => 'Parent/Guardian Name', 'field_type' => 'text', 'required' => true, 'section' => 'parent_info', 'display_order' => 4],
                    ['field_name' => 'parent_email', 'field_label' => 'Email', 'field_type' => 'email', 'required' => true, 'section' => 'parent_info', 'display_order' => 5],
                    ['field_name' => 'parent_phone', 'field_label' => 'Phone', 'field_type' => 'tel', 'required' => true, 'section' => 'parent_info', 'display_order' => 6],
                    ['field_name' => 'emergency_contact', 'field_label' => 'Emergency Contact Name', 'field_type' => 'text', 'required' => true, 'section' => 'emergency', 'display_order' => 7],
                    ['field_name' => 'emergency_phone', 'field_label' => 'Emergency Phone', 'field_type' => 'tel', 'required' => true, 'section' => 'emergency', 'display_order' => 8],
                    ['field_name' => 'medical_conditions', 'field_label' => 'Medical Conditions/Allergies', 'field_type' => 'textarea', 'required' => false, 'section' => 'medical', 'display_order' => 9],
                    ['field_name' => 'waiver_agreed', 'field_label' => 'I agree to the terms and conditions', 'field_type' => 'checkbox', 'required' => true, 'section' => 'waiver', 'display_order' => 10]
                ];

                $field_stmt = $connection->prepare("
                    INSERT INTO program_form_fields (
                        program_id, field_name, field_label, field_type, required, section, display_order
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($default_fields as $field) {
                    $field_stmt->execute([
                        $program_id,
                        $field['field_name'],
                        $field['field_label'],
                        $field['field_type'],
                        $field['required'] ? 1 : 0,  // Convert boolean to integer
                        $field['section'],
                        $field['display_order']
                    ]);
                }

                echo json_encode([
                    'success' => true,
                    'id' => $program_id,
                    'embed_code' => $embed_code,
                    'message' => 'Program created successfully'
                ]);
            } elseif ($path === 'update-fields') {
                // Update form fields for a program
                $data = json_decode(file_get_contents("php://input"), true);
                $program_id = $data['program_id'];

                // Delete existing fields
                $stmt = $connection->prepare("DELETE FROM program_form_fields WHERE program_id = ?");
                $stmt->execute([$program_id]);

                // Insert new fields
                $field_stmt = $connection->prepare("
                    INSERT INTO program_form_fields (
                        program_id, field_name, field_label, field_type,
                        required, options, section, display_order
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($data['fields'] as $index => $field) {
                    $field_stmt->execute([
                        $program_id,
                        $field['field_name'],
                        $field['field_label'],
                        $field['field_type'],
                        isset($field['required']) ? ($field['required'] ? 1 : 0) : 0,  // Convert boolean to integer
                        isset($field['options']) ? json_encode($field['options']) : null,
                        $field['section'] ?? 'general',
                        $index
                    ]);
                }

                echo json_encode(['success' => true, 'message' => 'Form fields updated']);
            }
            break;

        case 'PUT':
            $data = json_decode(file_get_contents("php://input"), true);
            $program_id = $_GET['id'] ?? 0;

            $stmt = $connection->prepare("
                UPDATE programs SET
                    name = ?, type = ?, description = ?,
                    start_date = ?, end_date = ?,
                    registration_opens = ?, registration_closes = ?,
                    min_age = ?, max_age = ?, capacity = ?, status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $data['name'],
                $data['type'],
                !empty($data['description']) ? $data['description'] : null,
                !empty($data['start_date']) ? $data['start_date'] : null,
                !empty($data['end_date']) ? $data['end_date'] : null,
                !empty($data['registration_opens']) ? $data['registration_opens'] : null,
                !empty($data['registration_closes']) ? $data['registration_closes'] : null,
                !empty($data['min_age']) ? $data['min_age'] : null,
                !empty($data['max_age']) ? $data['max_age'] : null,
                !empty($data['capacity']) ? $data['capacity'] : null,
                $data['status'] ?? 'draft',
                $program_id
            ]);

            echo json_encode(['success' => true, 'message' => 'Program updated']);
            break;

        case 'DELETE':
            $program_id = $_GET['id'] ?? 0;
            $stmt = $connection->prepare("DELETE FROM programs WHERE id = ?");
            $stmt->execute([$program_id]);
            echo json_encode(['success' => true, 'message' => 'Program deleted']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>