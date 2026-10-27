<?php
session_start();
ini_set('display_errors', '0');

function jsonOut($code, $payload) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload);
    exit();
}

set_exception_handler(function ($e) {
    error_log('[SAGE] documentos: ' . $e->getMessage());
    $out = ['success' => false, 'message' => 'Erro interno no servidor ao processar o documento.'];
    if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) $out['detail'] = $e->getMessage();
    jsonOut(500, $out);
});

if (!isset($_SESSION['user_id'])) {
    jsonOut(401, ['success' => false, 'message' => 'Não autenticado. Faça login.']);
}

require_once 'dataBaseConfiguration.php';
$db      = (new Database())->getConnection();
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? ($_POST['action'] ?? '');

const MAX_BYTES = 20 * 1024 * 1024;                      
$ALLOWED = [                                             
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp',
];
$UPLOAD_DIR = __DIR__ . '/../uploads';

function ensureUploadDir($dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('Não foi possível criar a pasta de uploads: ' . $dir);
    }
    
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('A pasta de uploads não tem permissão de escrita: ' . $dir);
    }
}

function auditDoc($db, $action, $recordId) {
    try {
        $st = $db->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id) VALUES (:u, :a, 'student_documents', :r)");
        $st->execute([':u' => $_SESSION['user_id'], ':a' => $action, ':r' => $recordId]);
    } catch (PDOException $e) {
        error_log('[SAGE] Falha ao gravar audit_log (documentos): ' . $e->getMessage());
    }
}

function detectMime($path, $originalName, $allowed) {
    if (class_exists('finfo')) {
        return (new finfo(FILEINFO_MIME_TYPE))->file($path);
    }
    
    $map = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    return $map[strtolower(pathinfo($originalName, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}


if ($method === 'POST' && $action === 'upload') {
    
    if (empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        jsonOut(413, ['success' => false,
            'message' => 'O arquivo excede o limite do servidor (post_max_size = ' . ini_get('post_max_size') . '). Aumente esse valor no php.ini do XAMPP.']);
    }

    $studentId = (int)($_POST['student_id'] ?? 0);
    if ($studentId <= 0) jsonOut(400, ['success' => false, 'message' => 'Aluno não informado.']);
    if (!isset($_FILES['file'])) jsonOut(400, ['success' => false, 'message' => 'Nenhum arquivo enviado.']);

    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        switch ($f['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $msg = 'Arquivo maior que o permitido pelo servidor (upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Aumente esse valor no php.ini do XAMPP.'; break;
            case UPLOAD_ERR_PARTIAL:  $msg = 'O envio foi interrompido. Tente novamente.'; break;
            case UPLOAD_ERR_NO_FILE:  $msg = 'Nenhum arquivo enviado.'; break;
            default:                  $msg = 'Falha no envio do arquivo (código ' . $f['error'] . ').';
        }
        jsonOut(400, ['success' => false, 'message' => $msg]);
    }
    if ($f['size'] <= 0)          jsonOut(400, ['success' => false, 'message' => 'O arquivo está vazio.']);
    if ($f['size'] > MAX_BYTES)   jsonOut(413, ['success' => false, 'message' => 'O arquivo excede 20 MB.']);
    if (!is_uploaded_file($f['tmp_name'])) jsonOut(400, ['success' => false, 'message' => 'Upload inválido.']);

    $st = $db->prepare("SELECT id, cpf FROM students WHERE id = :id AND is_active = 1 LIMIT 1");
    $st->execute([':id' => $studentId]);
    $aluno = $st->fetch(PDO::FETCH_ASSOC);
    if (!$aluno) jsonOut(404, ['success' => false, 'message' => 'Aluno não encontrado.']);

    
    $mime = detectMime($f['tmp_name'], $f['name'], $ALLOWED);
    if (!isset($ALLOWED[$mime])) {
        jsonOut(415, ['success' => false, 'message' => 'Tipo de arquivo não permitido. Envie PDF, JPG ou PNG.']);
    }

    ensureUploadDir($UPLOAD_DIR);
    $stored = bin2hex(random_bytes(16)) . '.' . $ALLOWED[$mime];
    $dest   = $UPLOAD_DIR . '/' . $stored;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        throw new RuntimeException('Falha ao mover o arquivo para ' . $dest);
    }

    $name = basename(str_replace('\\', '/', (string)$f['name']));
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    $name = mb_substr($name, 0, 255);
    if ($name === '' || $name === null) $name = 'documento.' . $ALLOWED[$mime];

    try {
        $ins = $db->prepare(
            "INSERT INTO student_documents (student_id, student_cpf, filename, stored_name, mime_type, file_size, uploaded_by)
             VALUES (:sid, :cpf, :fn, :sn, :mt, :sz, :ub)"
        );
        $ins->execute([
            ':sid' => $aluno['id'], ':cpf' => $aluno['cpf'], ':fn' => $name, ':sn' => $stored,
            ':mt'  => $mime, ':sz' => (int)$f['size'], ':ub' => $_SESSION['user_id'],
        ]);
        $newId = (int)$db->lastInsertId();
    } catch (PDOException $e) {
        @unlink($dest);          
        throw $e;
    }

    auditDoc($db, 'CREATE', $newId);
    jsonOut(200, ['success' => true, 'message' => 'Documento enviado.', 'id' => $newId]);
}


if ($method === 'GET' && $action === 'list') {
    $sid = (int)($_GET['student_id'] ?? 0);
    if ($sid <= 0) jsonOut(400, ['success' => false, 'message' => 'Aluno não informado.']);

    $st = $db->prepare(
        "SELECT d.id, d.filename, d.mime_type, d.file_size, d.uploaded_at, u.username AS uploaded_by_username
           FROM student_documents d
           LEFT JOIN users u ON d.uploaded_by = u.id
          WHERE d.student_id = :sid
          ORDER BY d.uploaded_at DESC, d.id DESC"
    );
    $st->execute([':sid' => $sid]);
    jsonOut(200, ['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}


if ($method === 'GET' && $action === 'counts') {
    $rows = $db->query("SELECT student_id, COUNT(*) AS total FROM student_documents GROUP BY student_id")->fetchAll(PDO::FETCH_ASSOC);
    jsonOut(200, ['success' => true, 'data' => $rows]);
}


if ($method === 'GET' && ($action === 'view' || $action === 'download')) {
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT filename, stored_name, mime_type FROM student_documents WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jsonOut(404, ['success' => false, 'message' => 'Documento não encontrado.']);

    $path = $UPLOAD_DIR . '/' . basename($doc['stored_name']);
    if (!is_file($path)) {
        jsonOut(404, ['success' => false, 'message' => 'O arquivo não foi encontrado na pasta backend/uploads do servidor.']);
    }

    while (ob_get_level()) ob_end_clean();
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $doc['filename']);
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0');
    header('Content-Disposition: ' . ($action === 'download' ? 'attachment' : 'inline')
         . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($doc['filename']));
    readfile($path);
    exit();
}


if ($method === 'DELETE') {
    if (!$isAdmin) {
        jsonOut(403, ['success' => false, 'message' => 'Apenas administradores podem excluir documentos.']);
    }
    $data = json_decode(file_get_contents('php://input'));
    $id   = is_object($data) && isset($data->id) ? (int)$data->id : 0;
    if ($id <= 0) jsonOut(400, ['success' => false, 'message' => 'ID do documento é obrigatório.']);

    $st = $db->prepare("SELECT stored_name FROM student_documents WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jsonOut(404, ['success' => false, 'message' => 'Documento não encontrado.']);

    $db->prepare("DELETE FROM student_documents WHERE id = :id")->execute([':id' => $id]);
    $path = $UPLOAD_DIR . '/' . basename($doc['stored_name']);
    if (is_file($path) && !@unlink($path)) {
        error_log('[SAGE] Não foi possível apagar o arquivo: ' . $path);
    }
    auditDoc($db, 'DELETE', $id);
    jsonOut(200, ['success' => true, 'message' => 'Documento removido.']);
}

jsonOut(400, ['success' => false, 'message' => 'Ação inválida.']);
