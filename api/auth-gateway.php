<?php
/**
 * Authentication Gateway API
 *
 * Handles all authentication-related endpoints:
 * - Magic link generation and verification
 * - Session management
 * - User login/logout
 */

header('Content-Type: application/json');

// Dynamic CORS based on environment
$allowedOrigins = [
    'http://localhost:3003',
    'http://localhost:3001',
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

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../lib/Email.php';

// Use existing MySQL database for now (will migrate to Neon later)
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance()->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    switch ($action) {
        case 'send-magic-link':
            handleSendMagicLink($db, $input);
            break;

        case 'verify-magic-link':
            handleVerifyMagicLink($db, $input);
            break;

        case 'verify-session':
            handleVerifySession();
            break;

        case 'logout':
            handleLogout();
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Action not found']);
    }

} catch (Exception $e) {
    error_log('Auth gateway error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => Env::get('APP_ENV') === 'development' ? $e->getMessage() : null
    ]);
}

/**
 * Send magic link to user's email
 */
function handleSendMagicLink($db, $input) {
    if (empty($input['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Email is required']);
        return;
    }

    $email = strtolower(trim($input['email']));

    // Check if user exists
    $stmt = $db->prepare('SELECT id, first_name, last_name, email FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // For security, don't reveal if user exists
        // But we'll send a different message internally
        error_log("Magic link requested for non-existent user: $email");

        // Return success anyway to prevent email enumeration
        echo json_encode([
            'success' => true,
            'message' => 'If an account exists with this email, a magic link has been sent.'
        ]);
        return;
    }

    // Generate secure token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + (15 * 60)); // 15 minutes

    // Store token in database
    $stmt = $db->prepare('
        INSERT INTO magic_link_tokens (email, token, expires_at, created_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ');
    $stmt->execute([$email, $token, $expiresAt]);

    // Build magic link URL
    $appUrl = Env::get('APP_URL', 'http://localhost:3003');
    $magicLink = "$appUrl/verify-magic-link?token=$token";

    // Send email
    $emailService = new Email();
    $userName = trim($user['first_name'] . ' ' . $user['last_name']);
    $sent = $emailService->sendMagicLink($email, $userName, $magicLink);

    if (!$sent) {
        error_log("Failed to send magic link email to $email");
    }

    echo json_encode([
        'success' => true,
        'message' => 'Magic link sent to your email',
        'debug' => Env::get('APP_ENV') === 'development' ? ['link' => $magicLink] : null
    ]);
}

/**
 * Verify magic link token and create session
 */
function handleVerifyMagicLink($db, $input) {
    if (empty($input['token'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Token is required']);
        return;
    }

    $token = $input['token'];

    // Look up token
    $stmt = $db->prepare('
        SELECT id, email, expires_at, used_at
        FROM magic_link_tokens
        WHERE token = ?
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $tokenData = $stmt->fetch();

    if (!$tokenData) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or expired magic link']);
        return;
    }

    // Check if already used
    if ($tokenData['used_at'] !== null) {
        http_response_code(400);
        echo json_encode(['error' => 'This magic link has already been used']);
        return;
    }

    // Check if expired
    if (strtotime($tokenData['expires_at']) < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'This magic link has expired']);
        return;
    }

    // Mark token as used
    $stmt = $db->prepare('UPDATE magic_link_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$tokenData['id']]);

    // Get user details
    $stmt = $db->prepare('SELECT id, email, first_name, last_name FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$tokenData['email']]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(400);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    // Update last login
    $stmt = $db->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$user['id']]);

    // Generate JWT
    $userName = trim($user['first_name'] . ' ' . $user['last_name']);
    $jwt = JWT::generate($user['id'], $user['email'], $userName);

    // Return JWT in response body (no cookie needed for cross-domain)
    echo json_encode([
        'success' => true,
        'message' => 'Authentication successful',
        'token' => $jwt,
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $userName
        ]
    ]);
}

/**
 * Verify current session (check if user is authenticated)
 */
function handleVerifySession() {
    // Check for JWT in Authorization header first, then fall back to cookie
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $jwt = null;

    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $jwt = $matches[1];
    } else {
        // Fallback to cookie for backwards compatibility
        $jwt = $_COOKIE['team-auth'] ?? null;
    }

    if (!$jwt) {
        echo json_encode([
            'authenticated' => false,
            'user' => null
        ]);
        return;
    }

    // Verify JWT
    $payload = JWT::verify($jwt);

    if (!$payload) {
        // Invalid or expired token
        echo json_encode([
            'authenticated' => false,
            'user' => null
        ]);
        return;
    }

    // Token is valid
    echo json_encode([
        'authenticated' => true,
        'user' => [
            'id' => isset($payload->user_id) ? (int)$payload->user_id : null,
            'email' => $payload->email ?? null,
            'name' => $payload->name ?? null
        ]
    ]);
}

/**
 * Logout user (clear session cookie)
 */
function handleLogout() {
    // Clear the cookie by setting it to expire in the past
    setcookie(
        'team-auth',
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);
}
