<?php
class Database {
    private static $instance = null;
    private $connection;

    private $host = 'localhost';
    private $db_name = 'teams_elevated';
    private $username = 'root';
    private $password = 'root';
    private $port = 3306;

    private function __construct() {
        try {
            // Use socket connection for MAMP MySQL
            $this->connection = new PDO(
                "mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname={$this->db_name};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            // Output error as HTML comment to not break JSON responses
            echo "<!-- Database connection error: " . $e->getMessage() . " -->\n";
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed']));
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }
}