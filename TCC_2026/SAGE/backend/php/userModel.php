<?php

class User {
    private $conn;
    private $table_name = "users";

    public $id;
    public $username;
    public $password;
    public $role;
    public $is_active;

    public function __construct($db) {
        $this->conn = $db;
    }

    
    public function login() {
        $query = "SELECT id, username, password, role
                  FROM {$this->table_name}
                  WHERE username = :username AND is_active = 1
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $this->username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (password_verify($this->password, $row['password'])) {
                return $row;
            }
        }
        return false;
    }

    
    public function updateLastLogin() {
        $query = "UPDATE {$this->table_name} SET last_login = NOW() WHERE id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(':id', $this->id);
        $stmt->execute();
    }

    public function create() {
        $query = "INSERT INTO {$this->table_name} (username, password, role)
                  VALUES (:username, :password, :role)";
        $stmt  = $this->conn->prepare($query);

        $hashed = password_hash($this->password, PASSWORD_DEFAULT);

        $stmt->bindParam(':username', $this->username);
        $stmt->bindParam(':password', $hashed);
        $stmt->bindParam(':role',     $this->role);

        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            
            return false;
        }
    }

   
    public function getAll() {
        $query = "SELECT id, username, role, is_active, last_login, created_at
                  FROM {$this->table_name}
                  ORDER BY username";
        $stmt  = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

   
    public function update() {
        if (!empty($this->password)) {
            $query = "UPDATE {$this->table_name}
                      SET role = :role, password = :password
                      WHERE id = :id";
        } else {
            $query = "UPDATE {$this->table_name}
                      SET role = :role
                      WHERE id = :id";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':role', $this->role);
        $stmt->bindParam(':id',   $this->id);

        if (!empty($this->password)) {
            $hashed = password_hash($this->password, PASSWORD_DEFAULT);
            $stmt->bindParam(':password', $hashed);
        }

        return $stmt->execute();
    }

   
    public function delete() {
        $query = "DELETE FROM {$this->table_name} WHERE id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(':id', $this->id);
        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }
    }
}
?>