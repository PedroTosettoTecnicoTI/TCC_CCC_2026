<?php

session_start();
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }


if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado.']);
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado. Apenas administradores.']);
    exit();
}

require_once 'dataBaseConfiguration.php';
require_once 'userModel.php';

$database = new Database();
$db       = $database->getConnection();
$user     = new User($db);
$method   = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"));

    if (isset($data->action) && $data->action === 'create') {
        if (empty($data->username) || empty($data->password)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Usuário e senha são obrigatórios.']);
            exit();
        }
        if (strlen($data->password) < 4) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Senha deve ter no mínimo 4 caracteres.']);
            exit();
        }

        $user->username = trim($data->username);
        $user->password = $data->password;
        $user->role     = isset($data->role) ? $data->role : 'user';

        if ($user->create()) {
            echo json_encode(['success' => true, 'message' => 'Usuário criado com sucesso!']);
        } else {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Nome de usuário já existe.']);
        }
        exit();
    }

    
    if (isset($data->action) && $data->action === 'update') {
        if (empty($data->id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID do usuário é obrigatório.']);
            exit();
        }

        $user->id   = $data->id;
        $user->role = isset($data->role) ? $data->role : 'user';
        if (!empty($data->password)) {
            if (strlen($data->password) < 4) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Nova senha deve ter no mínimo 4 caracteres.']);
                exit();
            }
            $user->password = $data->password;
        } else {
            $user->password = '';
        }

        if ($user->update()) {
            echo json_encode(['success' => true, 'message' => 'Usuário atualizado com sucesso!']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro ao atualizar usuário.']);
        }
        exit();
    }
}


if ($method === 'GET') {
    if (isset($_GET['action']) && $_GET['action'] === 'getAll') {
        $users = $user->getAll();
        echo json_encode(['success' => true, 'data' => $users]);
        exit();
    }
}


if ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"));
    if (isset($data->id)) {
        
        if ($data->id == $_SESSION['user_id']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Você não pode excluir sua própria conta.']);
            exit();
        }

        $user->id = $data->id;
        if ($user->delete()) {
            echo json_encode(['success' => true, 'message' => 'Usuário removido com sucesso!']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro ao remover usuário.']);
        }
        exit();
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
?>