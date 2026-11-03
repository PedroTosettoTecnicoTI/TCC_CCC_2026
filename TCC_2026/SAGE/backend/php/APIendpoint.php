<?php

session_start();
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

require_once 'dataBaseConfiguration.php';
require_once 'userModel.php';

$database = new Database();
$db       = $database->getConnection();
$user     = new User($db);
$method   = $_SERVER['REQUEST_METHOD'];


if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"));

  
    if (isset($data->action) && $data->action === 'login') {
        if (empty($data->username) || empty($data->password)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Usuário e senha são obrigatórios.']);
            exit();
        }

        $user->username = trim($data->username);
        $user->password = $data->password;
        $result = $user->login();

        if ($result) {
            
            $user->id = $result['id'];
            $user->updateLastLogin();

            $_SESSION['user_id']  = $result['id'];
            $_SESSION['username'] = $result['username'];
            $_SESSION['role']     = $result['role'];

            echo json_encode([
                'success' => true,
                'user'    => [
                    'id'       => $result['id'],
                    'username' => $result['username'],
                    'role'     => $result['role'],
                ]
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Usuário ou senha incorretos.']);
        }
        exit();
    }

   
    if (isset($data->action) && $data->action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true]);
        exit();
    }
}


if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            'logged_in' => true,
            'user'      => [
                'id'       => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'role'     => $_SESSION['role'],
            ]
        ]);
    } else {
        echo json_encode(['logged_in' => false]);
    }
    exit();
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
?>