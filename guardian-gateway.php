<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$servername = "localhost";
$username = "root";
$password = "root";
$dbname = "teams_elevated";
$socket = "/Applications/MAMP/tmp/mysql/mysql.sock";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;unix_socket=$socket", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Get guardians for an athlete
            $athleteId = isset($_GET['athlete_id']) ? (int)$_GET['athlete_id'] : null;

            if ($athleteId) {
                // Get guardians for specific athlete
                $stmt = $pdo->prepare("
                    SELECT ag.id as relationship_id,
                           ag.relationship_type,
                           ag.is_primary_contact,
                           ag.has_legal_custody,
                           ag.can_authorize_medical,
                           ag.can_pickup,
                           ag.receives_communications,
                           ag.financial_responsible,
                           ag.active_status,
                           g.id as guardian_id,
                           g.first_name,
                           g.last_name,
                           g.email,
                           g.mobile_phone,
                           g.work_phone
                    FROM athlete_guardians ag
                    JOIN guardians g ON ag.guardian_id = g.id
                    WHERE ag.athlete_id = ? AND ag.active_status = 1
                    ORDER BY ag.is_primary_contact DESC, g.first_name ASC
                ");
                $stmt->execute([$athleteId]);
                $guardians = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'guardians' => $guardians]);
            } else {
                // Get all guardians
                $stmt = $pdo->prepare("
                    SELECT g.id,
                           g.first_name,
                           g.last_name,
                           g.email,
                           g.mobile_phone,
                           g.work_phone,
                           COUNT(ag.id) as athlete_count
                    FROM guardians g
                    LEFT JOIN athlete_guardians ag ON g.id = ag.guardian_id AND ag.active_status = 1
                    GROUP BY g.id
                    ORDER BY g.first_name ASC, g.last_name ASC
                ");
                $stmt->execute();
                $guardians = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'guardians' => $guardians]);
            }
            break;

        case 'POST':
            // Add new guardian to athlete
            $input = json_decode(file_get_contents('php://input'), true);
            $athleteId = $input['athlete_id'] ?? null;

            if (!$athleteId) {
                throw new Exception('Athlete ID is required');
            }

            // Required fields
            $first_name = $input['first_name'] ?? null;
            $last_name = $input['last_name'] ?? null;
            $email = $input['email'] ?? null;
            $mobile_phone = $input['mobile_phone'] ?? null;
            $relationship_type = $input['relationship_type'] ?? 'Other';

            if (!$first_name || !$last_name || !$email || !$mobile_phone) {
                throw new Exception('First name, last name, email, and mobile phone are required');
            }

            $pdo->beginTransaction();

            try {
                // Check if guardian already exists by email
                $stmt = $pdo->prepare("SELECT id FROM guardians WHERE email = ?");
                $stmt->execute([$email]);
                $existingGuardian = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingGuardian) {
                    $guardianId = $existingGuardian['id'];
                } else {
                    // Create new guardian
                    $stmt = $pdo->prepare("
                        INSERT INTO guardians (first_name, last_name, email, mobile_phone, work_phone)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $first_name,
                        $last_name,
                        $email,
                        $mobile_phone,
                        $input['work_phone'] ?? null
                    ]);
                    $guardianId = $pdo->lastInsertId();
                }

                // Link guardian to athlete
                $stmt = $pdo->prepare("
                    INSERT INTO athlete_guardians (
                        athlete_id, guardian_id, relationship_type,
                        is_primary_contact, has_legal_custody, can_authorize_medical,
                        can_pickup, receives_communications, financial_responsible
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $athleteId,
                    $guardianId,
                    $relationship_type,
                    $input['is_primary_contact'] ?? 0,
                    $input['has_legal_custody'] ?? 1,
                    $input['can_authorize_medical'] ?? 1,
                    $input['can_pickup'] ?? 1,
                    $input['receives_communications'] ?? 1,
                    $input['financial_responsible'] ?? 0
                ]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Guardian added successfully']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'PUT':
            // Update guardian relationship
            $input = json_decode(file_get_contents('php://input'), true);
            $relationshipId = $input['id'] ?? null;

            if (!$relationshipId) {
                throw new Exception('Guardian relationship ID is required');
            }

            $updateFields = [];
            $updateValues = [];

            $allowedFields = [
                'relationship_type', 'is_primary_contact', 'has_legal_custody',
                'can_authorize_medical', 'can_pickup', 'receives_communications', 'financial_responsible'
            ];

            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updateFields[] = "$field = ?";
                    $updateValues[] = $input[$field];
                }
            }

            if (!empty($updateFields)) {
                $updateValues[] = $relationshipId;
                $stmt = $pdo->prepare("
                    UPDATE athlete_guardians
                    SET " . implode(', ', $updateFields) . "
                    WHERE id = ?
                ");
                $stmt->execute($updateValues);
            }

            echo json_encode(['success' => true, 'message' => 'Guardian relationship updated successfully']);
            break;

        case 'DELETE':
            // Remove guardian relationship
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

            if (!$id) {
                throw new Exception('Guardian relationship ID is required');
            }

            $stmt = $pdo->prepare("UPDATE athlete_guardians SET active_status = 0 WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Guardian relationship removed successfully']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>