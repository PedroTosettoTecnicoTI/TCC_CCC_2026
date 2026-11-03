<?php

session_start();
header("Content-Type: application/json; charset=utf-8");


ini_set('display_errors', '0');
set_exception_handler(function ($e) {
    error_log('[SAGE] ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    $local  = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
    $out    = ['success' => false, 'message' => 'Erro interno no servidor ao processar a requisição.'];
    if ($local) $out['detail'] = $e->getMessage();   
    echo json_encode($out);
    exit();
});
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado. Faça login.']);
    exit();
}

require_once 'dataBaseConfiguration.php';
require_once 'studentModel.php';

$database = new Database();
$db       = $database->getConnection();
$student  = new Student($db);
$method   = $_SERVER['REQUEST_METHOD'];

function jsonOut($code, $payload) {
    http_response_code($code);
    echo json_encode($payload);
    exit();
}

function validarCamposAluno($d, $exigeCpf) {
    $nome = isset($d->nome) ? trim($d->nome) : '';
    if ($nome === '')                 return 'Nome é obrigatório.';
    if (mb_strlen($nome) > 100)       return 'Nome muito longo (máximo 100 caracteres).';

    if ($exigeCpf) {
        $cpf = isset($d->cpf) ? trim($d->cpf) : '';
        if ($cpf === '')              return 'CPF é obrigatório.';
        if (!Student::validCpf($cpf)) return 'CPF inválido. Verifique os dígitos.';
    }

    $limites = ['email' => 100, 'telefone' => 15, 'responsavel' => 100, 'telefone_responsavel' => 15];
    foreach ($limites as $campo => $max) {
        if (isset($d->$campo) && $d->$campo !== null && mb_strlen(trim($d->$campo)) > $max) {
            return "Campo '$campo' excede $max caracteres.";
        }
    }
    if (!empty($d->email) && !filter_var(trim($d->email), FILTER_VALIDATE_EMAIL)) {
        return 'E-mail inválido.';
    }
    if (!empty($d->data_nascimento)) {
        $p = explode('-', (string)$d->data_nascimento);
        if (count($p) !== 3 || !checkdate((int)$p[1], (int)$p[2], (int)$p[0])) {
            return 'Data de nascimento inválida.';
        }
    }
    if (isset($d->responsavel_falecido) && !in_array($d->responsavel_falecido, ['sim', 'não'], true)) {
        return "Valor inválido para 'responsável falecido'.";
    }
    return null;
}


if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"));
    if (!is_object($data)) {
        jsonOut(400, ['success' => false, 'message' => 'Requisição inválida (JSON malformado).']);
    }

    
    if (isset($data->action) && $data->action === 'create') {
        if ($_SESSION['role'] !== 'admin') {
            jsonOut(403, ['success' => false, 'message' => 'Apenas administradores podem cadastrar alunos.']);
        }
        $erro = validarCamposAluno($data, true);
        if ($erro) jsonOut(400, ['success' => false, 'message' => $erro]);

        $student->nome                 = trim($data->nome);
        $student->cpf                  = trim($data->cpf);
        $student->email                = !empty($data->email)                ? trim($data->email)                : null;
        $student->telefone             = !empty($data->telefone)             ? trim($data->telefone)             : null;
        $student->data_nascimento      = !empty($data->data_nascimento)      ? $data->data_nascimento            : null;
        $student->endereco             = !empty($data->endereco)             ? trim($data->endereco)             : null;
        $student->responsavel          = !empty($data->responsavel)          ? trim($data->responsavel)          : null;
        $student->telefone_responsavel = !empty($data->telefone_responsavel) ? trim($data->telefone_responsavel) : null;
        $student->responsavel_falecido = isset($data->responsavel_falecido)  ? $data->responsavel_falecido       : 'não';
        $student->created_by           = $_SESSION['user_id'];

        if ($student->create()) {
            $msg = $student->reactivated
                 ? 'Aluno cadastrado com sucesso! (CPF já havia sido cadastrado e excluído antes; o cadastro foi reativado com os novos dados.)'
                 : 'Aluno cadastrado com sucesso!';
            echo json_encode(['success' => true, 'message' => $msg, 'id' => $student->id]);
        } elseif ($student->last_error === 'duplicate') {
            jsonOut(409, ['success' => false, 'message' => 'Já existe um aluno ativo cadastrado com este CPF.']);
        } else {
            $out = ['success' => false, 'message' => 'Erro ao gravar no banco de dados. Verifique o log do PHP/Apache.'];
            if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) $out['detail'] = $student->last_error_detail;
            jsonOut(500, $out);
        }
        exit();
    }

    if (isset($data->action) && $data->action === 'search') {
        $filters = [];
        if (!empty($data->nome))            $filters['nome']            = $data->nome;
        if (!empty($data->cpf))             $filters['cpf']             = $data->cpf;
        if (!empty($data->responsavel))     $filters['responsavel']     = $data->responsavel;
        if (!empty($data->data_nascimento)) $filters['data_nascimento'] = $data->data_nascimento;

        $results = $student->search($filters);
        echo json_encode(['success' => true, 'data' => $results]);
        exit();
    }

    
    if (isset($data->action) && $data->action === 'update') {
        if ($_SESSION['role'] !== 'admin') {
            jsonOut(403, ['success' => false, 'message' => 'Apenas administradores podem editar alunos.']);
        }
        if (empty($data->id)) {
            jsonOut(400, ['success' => false, 'message' => 'ID é obrigatório.']);
        }
        $erro = validarCamposAluno($data, false);
        if ($erro) jsonOut(400, ['success' => false, 'message' => $erro]);

        $student->id                   = $data->id;
        $student->nome                 = trim($data->nome);
        $student->email                = isset($data->email)                ? trim($data->email)                : null;
        $student->telefone             = isset($data->telefone)             ? trim($data->telefone)             : null;
        $student->data_nascimento      = isset($data->data_nascimento)      && $data->data_nascimento !== ''    ? $data->data_nascimento : null;
        $student->endereco             = isset($data->endereco)             ? trim($data->endereco)             : null;
        $student->responsavel          = isset($data->responsavel)          ? trim($data->responsavel)          : null;
        $student->telefone_responsavel = isset($data->telefone_responsavel) ? trim($data->telefone_responsavel) : null;
        $student->responsavel_falecido = isset($data->responsavel_falecido) ? $data->responsavel_falecido       : 'não';

        if ($student->update()) {
            echo json_encode(['success' => true, 'message' => 'Aluno atualizado com sucesso!']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro ao atualizar aluno.']);
        }
        exit();
    }
}

if ($method === 'GET') {
    if (isset($_GET['action']) && $_GET['action'] === 'getAll') {
        $students = $student->getAll();
        echo json_encode(['success' => true, 'data' => $students]);
        exit();
    }
}


if ($method === 'DELETE') {
    
    if ($_SESSION['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Apenas administradores podem excluir alunos.']);
        exit();
    }

    $data = json_decode(file_get_contents("php://input"));
    if (isset($data->id)) {
        $student->id = $data->id;
        if ($student->delete()) {
            echo json_encode(['success' => true, 'message' => 'Aluno removido com sucesso!']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro ao remover aluno.']);
        }
        exit();
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
?>