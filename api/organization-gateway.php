<?php
/**
 * Organization Gateway API
 * Handles organization creation and management
 */

header('Content-Type: application/json');

// Dynamic CORS based on environment
$allowedOrigins = [
    'http://localhost:3003',
    'http://localhost:3000',
    'https://teams-elevated.netlify.app'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../lib/Email.php';

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Create Organization and User
 */
function handleCreateOrganization($conn, $input) {
    // Validate required fields
    if (empty($input['organizationName']) || empty($input['yourName']) || empty($input['email']) || empty($input['roles'])) {
        http_response_code(400);
        return ['error' => 'Organization name, your name, email, and roles are required'];
    }

    $organizationName = trim($input['organizationName']);
    $userName = trim($input['yourName']);
    $email = strtolower(trim($input['email']));
    $phone = !empty($input['phone']) ? trim($input['phone']) : null;
    $roles = $input['roles']; // Array of role strings

    try {
        $conn->exec('BEGIN');

        // 1. Check if user already exists
        $stmt = $conn->prepare('SELECT id, name, email FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            // User already exists, send magic link to existing user
            $userId = $existingUser['id'];

            // Generate magic link token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            // Store token
            $stmt = $conn->prepare('
                INSERT INTO magic_link_tokens (user_id, token, expires_at, created_at)
                VALUES (:user_id, :token, :expires_at, CURRENT_TIMESTAMP)
            ');
            $stmt->execute([
                'user_id' => $userId,
                'token' => $token,
                'expires_at' => $expiresAt
            ]);

            // Send magic link email
            $magicLink = getenv('APP_URL') . '/verify-magic-link?token=' . $token;
            $email = new Email();
            $email->sendMagicLink($email, $existingUser['name'], $magicLink);

            $conn->exec('COMMIT');

            return [
                'success' => true,
                'message' => 'User already exists. Magic link sent to your email.',
                'existingUser' => true,
                'magicLink' => getenv('APP_ENV') === 'development' ? $magicLink : null
            ];
        }

        // 2. Create new user
        $stmt = $conn->prepare('
            INSERT INTO users (name, email, phone, auth_provider, created_at)
            VALUES (:name, :email, :phone, :auth_provider, CURRENT_TIMESTAMP)
            RETURNING id
        ');
        $stmt->execute([
            'name' => $userName,
            'email' => $email,
            'phone' => $phone,
            'auth_provider' => 'magic_link'
        ]);
        $result = $stmt->fetch();
        $userId = $result['id'];

        // 3. Create organization (club profile)
        $stmt = $conn->prepare('
            INSERT INTO club_profile (name, contact_email, contact_phone, created_at)
            VALUES (:name, :email, :phone, CURRENT_TIMESTAMP)
            RETURNING id
        ');
        $stmt->execute([
            'name' => $organizationName,
            'email' => $email,
            'phone' => $phone
        ]);
        $result = $stmt->fetch();
        $clubId = $result['id'];

        // 4. Assign roles to user
        // Map frontend role names to database role names
        $roleMapping = [
            'league' => 'league_admin',
            'team' => 'team_manager',
            'coach' => 'coach',
            'administrator' => 'administrator',
            'parent' => 'parent'
        ];

        foreach ($roles as $role) {
            $dbRole = $roleMapping[$role] ?? $role;

            // Insert into user_roles table
            $stmt = $conn->prepare('
                INSERT INTO user_roles (user_id, role, club_profile_id, created_at)
                VALUES (:user_id, :role, :club_profile_id, CURRENT_TIMESTAMP)
            ');
            $stmt->execute([
                'user_id' => $userId,
                'role' => $dbRole,
                'club_profile_id' => $clubId
            ]);
        }

        // 5. Generate magic link for authentication
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $stmt = $conn->prepare('
            INSERT INTO magic_link_tokens (user_id, token, expires_at, created_at)
            VALUES (:user_id, :token, :expires_at, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => $expiresAt
        ]);

        // 6. Send magic link email
        $magicLink = getenv('APP_URL') . '/verify-magic-link?token=' . $token;
        $emailSender = new Email();
        $emailSender->sendMagicLink($email, $userName, $magicLink);

        $conn->exec('COMMIT');

        return [
            'success' => true,
            'message' => 'Organization created successfully',
            'user' => [
                'id' => $userId,
                'name' => $userName,
                'email' => $email
            ],
            'organization' => [
                'id' => $clubId,
                'name' => $organizationName
            ],
            'roles' => $roles,
            'magicLink' => getenv('APP_ENV') === 'development' ? $magicLink : null
        ];

    } catch (Exception $e) {
        $conn->exec('ROLLBACK');
        error_log('Organization creation error: ' . $e->getMessage());
        http_response_code(500);
        return [
            'error' => 'Failed to create organization',
            'details' => getenv('APP_ENV') === 'development' ? $e->getMessage() : null
        ];
    }
}

// Route handler
try {
    if ($method === 'POST' && $action === 'create') {
        $input = json_decode(file_get_contents('php://input'), true);
        $response = handleCreateOrganization($conn, $input);
        echo json_encode($response);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
    }
} catch (Exception $e) {
    error_log('API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'details' => getenv('APP_ENV') === 'development' ? $e->getMessage() : null
    ]);
}
