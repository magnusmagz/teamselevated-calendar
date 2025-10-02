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
            // Get team players
            $team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;

            if ($team_id) {
                $stmt = $pdo->prepare("
                    SELECT tp.*, u.first_name, u.last_name, u.email
                    FROM team_players tp
                    JOIN users u ON tp.user_id = u.id
                    WHERE tp.team_id = ?
                    ORDER BY u.last_name, u.first_name
                ");
                $stmt->execute([$team_id]);
                $team_players = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'team_players' => $team_players]);
            } else {
                // Get all team players
                $stmt = $pdo->prepare("
                    SELECT tp.*, u.first_name, u.last_name, u.email, t.name as team_name
                    FROM team_players tp
                    JOIN users u ON tp.user_id = u.id
                    JOIN teams t ON tp.team_id = t.id
                    ORDER BY t.name, u.last_name, u.first_name
                ");
                $stmt->execute();
                $team_players = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'team_players' => $team_players]);
            }
            break;

        case 'POST':
            // Add player to team
            $input = json_decode(file_get_contents('php://input'), true);

            $team_id = $input['team_id'] ?? null;
            $user_id = $input['player_id'] ?? null;

            if (!$team_id || !$user_id) {
                throw new Exception('Team ID and User ID are required');
            }

            // Check if player is already on the team
            $stmt = $pdo->prepare("SELECT id FROM team_players WHERE team_id = ? AND user_id = ?");
            $stmt->execute([$team_id, $user_id]);

            if ($stmt->fetch()) {
                throw new Exception('Player is already on this team');
            }

            $stmt = $pdo->prepare("
                INSERT INTO team_players (team_id, user_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$team_id, $user_id]);

            echo json_encode(['success' => true, 'message' => 'Player added to team successfully']);
            break;

        case 'PUT':
            // Update team player
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$id) {
                throw new Exception('Team player ID is required');
            }

            $status = $input['status'] ?? null;

            if ($status) {
                $stmt = $pdo->prepare("UPDATE team_players SET status = ? WHERE id = ?");
                $stmt->execute([$status, $id]);
            }

            echo json_encode(['success' => true, 'message' => 'Team player updated successfully']);
            break;

        case 'DELETE':
            // Remove player from team
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            $team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;
            $player_id = isset($_GET['player_id']) ? (int)$_GET['player_id'] : null;

            if ($id) {
                $stmt = $pdo->prepare("DELETE FROM team_players WHERE id = ?");
                $stmt->execute([$id]);
            } elseif ($team_id && $player_id) {
                $stmt = $pdo->prepare("DELETE FROM team_players WHERE team_id = ? AND user_id = ?");
                $stmt->execute([$team_id, $player_id]);
            } else {
                throw new Exception('Either team player ID or both team ID and player ID are required');
            }

            echo json_encode(['success' => true, 'message' => 'Player removed from team successfully']);
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