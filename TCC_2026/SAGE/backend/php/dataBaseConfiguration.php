<?php

class Database {
    private $host     = "localhost";
    private $db_name  = "sage_db";       
    private $username = "root";
    private $password = "";              
    public  $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
            $this->conn = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch(PDOException $e) {
            
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro de conexão com o banco de dados.']);
            exit();
        }
        return $this->conn;
    }
}
?>