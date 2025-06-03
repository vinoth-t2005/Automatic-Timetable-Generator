<?php
class DatabaseConfig {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $config = [
            'host' => 'localhost',
            'user' => 'root',
            'password' => '',
            'database' => 'autotime',
            'charset' => 'utf8mb4'
        ];

        try {
            $this->conn = new mysqli(
                $config['host'], 
                $config['user'], 
                $config['password'], 
                $config['database']
            );

            if ($this->conn->connect_error) {
                throw new Exception("Connection failed: " . $this->conn->connect_error);
            }

            $this->conn->set_charset($config['charset']);
        } catch (Exception $e) {
            error_log($e->getMessage());
            die("Database connection error. Please try again later.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new DatabaseConfig();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }
}
?>