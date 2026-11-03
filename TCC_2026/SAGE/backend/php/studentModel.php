<?php

class Student {
    private $conn;
    private $table_name = "students";

    public $id;
    public $nome;
    public $cpf;
    public $email;
    public $telefone;
    public $data_nascimento;
    public $endereco;
    public $responsavel;
    public $telefone_responsavel;
    public $responsavel_falecido;
    public $created_by;

    public $last_error        = null;
    public $last_error_detail = null;
  
    public $reactivated       = false;

    public function __construct($db) {
        $this->conn = $db;
    }

    public static function formatCpf($cpf) {
        $d = preg_replace('/\D/', '', (string)$cpf);
        if (strlen($d) === 11) {
            return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
        }
        return trim((string)$cpf);
    }

    public static function validCpf($cpf) {
        $d = preg_replace('/\D/', '', (string)$cpf);
        if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) return false;
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) $sum += (int)$d[$i] * (($t + 1) - $i);
            $r = ((10 * $sum) % 11) % 10;
            if ((int)$d[$t] !== $r) return false;
        }
        return true;
    }

    public function create() {
        $this->last_error        = null;
        $this->last_error_detail = null;
        $this->reactivated       = false;
        $this->cpf               = self::formatCpf($this->cpf);

        $query = "INSERT INTO " . $this->table_name . 
                 " SET nome=:nome, cpf=:cpf, email=:email, telefone=:telefone, 
                  data_nascimento=:data_nascimento, endereco=:endereco, 
                  responsavel=:responsavel, telefone_responsavel=:telefone_responsavel, 
                  responsavel_falecido=:responsavel_falecido, created_by=:created_by";

        try {
            $stmt = $this->conn->prepare($query);

            $stmt->bindValue(':nome', $this->nome);
            $stmt->bindValue(':cpf', $this->cpf);
            $stmt->bindValue(':email', $this->email);
            $stmt->bindValue(':telefone', $this->telefone);
            $stmt->bindValue(':data_nascimento', $this->data_nascimento);
            $stmt->bindValue(':endereco', $this->endereco);
            $stmt->bindValue(':responsavel', $this->responsavel);
            $stmt->bindValue(':telefone_responsavel', $this->telefone_responsavel);
            $stmt->bindValue(':responsavel_falecido', $this->responsavel_falecido);
            $stmt->bindValue(':created_by', $this->created_by);

            if ($stmt->execute()) {
                $this->id = $this->conn->lastInsertId();
                $this->logAudit('CREATE');
                return true;
            }
            $this->last_error = 'db';
            return false;
        } catch (PDOException $e) {
            
            if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
                return $this->handleDuplicateCpf();
            }
            $this->last_error        = 'db';
            $this->last_error_detail = $e->getMessage();
            error_log('[SAGE] Erro ao cadastrar aluno: ' . $e->getMessage());
            return false;
        }
    }

   
    private function handleDuplicateCpf() {
        try {
            $stmt = $this->conn->prepare("SELECT id, is_active FROM " . $this->table_name . " WHERE cpf = :cpf LIMIT 1");
            $stmt->bindValue(':cpf', $this->cpf);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && (int)$row['is_active'] === 0) {
                $up = $this->conn->prepare(
                    "UPDATE " . $this->table_name . " SET
                        nome=:nome, email=:email, telefone=:telefone, data_nascimento=:data_nascimento,
                        endereco=:endereco, responsavel=:responsavel, telefone_responsavel=:telefone_responsavel,
                        responsavel_falecido=:responsavel_falecido, created_by=:created_by, is_active=1
                     WHERE id=:id"
                );
                $up->bindValue(':nome', $this->nome);
                $up->bindValue(':email', $this->email);
                $up->bindValue(':telefone', $this->telefone);
                $up->bindValue(':data_nascimento', $this->data_nascimento);
                $up->bindValue(':endereco', $this->endereco);
                $up->bindValue(':responsavel', $this->responsavel);
                $up->bindValue(':telefone_responsavel', $this->telefone_responsavel);
                $up->bindValue(':responsavel_falecido', $this->responsavel_falecido);
                $up->bindValue(':created_by', $this->created_by);
                $up->bindValue(':id', $row['id'], PDO::PARAM_INT);
                $up->execute();

                $this->id          = $row['id'];
                $this->reactivated = true;
                $this->logAudit('CREATE');
                return true;
            }
        } catch (PDOException $e) {
            $this->last_error        = 'db';
            $this->last_error_detail = $e->getMessage();
            error_log('[SAGE] Erro ao tratar CPF duplicado: ' . $e->getMessage());
            return false;
        }

        $this->last_error = 'duplicate';
        return false;
    }

    public function getAll() {
        $query = "SELECT s.*, u.username AS created_by_username
                  FROM " . $this->table_name . " s
                  LEFT JOIN users u ON s.created_by = u.id
                  WHERE s.is_active = 1
                  ORDER BY s.nome";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function search($filters) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE is_active = 1";
        $params = [];
        
        if(!empty($filters['nome'])) {
            $query .= " AND nome LIKE :nome";
            $params[':nome'] = '%' . $filters['nome'] . '%';
        }
        if(!empty($filters['cpf'])) {
            $query .= " AND cpf LIKE :cpf";
            $params[':cpf'] = '%' . $filters['cpf'] . '%';
        }
        if(!empty($filters['responsavel'])) {
            $query .= " AND responsavel LIKE :responsavel";
            $params[':responsavel'] = '%' . $filters['responsavel'] . '%';
        }
        if(!empty($filters['data_nascimento'])) {
            $query .= " AND data_nascimento = :data_nascimento";
            $params[':data_nascimento'] = $filters['data_nascimento'];
        }
        
        $query .= " ORDER BY nome";
        $stmt = $this->conn->prepare($query);
        
        foreach($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function update() {
        $query = "UPDATE " . $this->table_name . 
                 " SET nome=:nome, email=:email, telefone=:telefone,
                  data_nascimento=:data_nascimento, endereco=:endereco,
                  responsavel=:responsavel,
                  telefone_responsavel=:telefone_responsavel,
                  responsavel_falecido=:responsavel_falecido
                  WHERE id = :id AND is_active = 1";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':nome',                 $this->nome);
        $stmt->bindParam(':email',                $this->email);
        $stmt->bindParam(':telefone',             $this->telefone);
        $stmt->bindParam(':data_nascimento',      $this->data_nascimento);
        $stmt->bindParam(':endereco',             $this->endereco);
        $stmt->bindParam(':responsavel',          $this->responsavel);
        $stmt->bindParam(':telefone_responsavel', $this->telefone_responsavel);
        $stmt->bindParam(':responsavel_falecido', $this->responsavel_falecido);
        $stmt->bindParam(':id',                   $this->id);

        if ($stmt->execute()) {
            $this->logAudit('UPDATE');
            return true;
        }
        return false;
    }

    public function delete() {
        $query = "UPDATE " . $this->table_name . " SET is_active = 0 WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $this->id);
        
        if($stmt->execute()) {
            $this->logAudit('DELETE');
            return true;
        }
        return false;
    }

    private function logAudit($action) {
        
        try {
            $query = "INSERT INTO audit_log (user_id, action, table_name, record_id) 
                      VALUES (:user_id, :action, :table_name, :record_id)";
            $stmt = $this->conn->prepare($query);

            $user_id    = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            $table_name = $this->table_name;

            $stmt->bindValue(':user_id', $user_id);
            $stmt->bindValue(':action', $action);
            $stmt->bindValue(':table_name', $table_name);
            $stmt->bindValue(':record_id', $this->id);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log('[SAGE] Falha ao gravar audit_log: ' . $e->getMessage());
        }
    }
}
?>