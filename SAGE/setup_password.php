<?php
/**
 * setup_password.php
 * ─────────────────────────────────────────────────────────────
 * Script auxiliar para redefinir as senhas padrão no banco.
 * 
 * COMO USAR:
 *  1. Coloque este arquivo em: C:\xampp\htdocs\SAGE\
 *  2. Acesse: http://localhost/SAGE/setup_password.php
 *  3. As senhas serão atualizadas no banco.
 *  4. APAGUE este arquivo após usar (segurança)!
 * ─────────────────────────────────────────────────────────────
 */

// Bloqueia acesso externo (só funciona em localhost)
if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
    http_response_code(403);
    die('Acesso negado.');
}

require_once 'backend/php/dataBaseConfiguration.php';

$db = (new Database())->getConnection();

// Senhas padrão — altere conforme necessário
$senhas = [
    'admin' => 'admin123',
    'user'  => 'user1234',
];

echo '<meta charset="UTF-8">';
echo '<h2>🔐 SAGE — Configuração de Senhas Iniciais</h2>';
echo '<pre>';

foreach ($senhas as $username => $plainPassword) {
    $hash  = password_hash($plainPassword, PASSWORD_DEFAULT);
    $stmt  = $db->prepare("UPDATE users SET password = :password WHERE username = :username");
    $stmt->bindParam(':password', $hash);
    $stmt->bindParam(':username', $username);

    if ($stmt->execute() && $stmt->rowCount() > 0) {
        echo "✅ Usuário '$username' → senha atualizada para: '$plainPassword'\n";
    } else {
        echo "⚠️  Usuário '$username' não encontrado ou senha inalterada.\n";
    }
}

echo "\n";
echo "──────────────────────────────────────────────\n";
echo "Senhas configuradas! Acesse o sistema em:\n";
echo "  http://localhost/SAGE/frontend/index.html\n";
echo "──────────────────────────────────────────────\n";
echo "\n⚠️  IMPORTANTE: APAGUE este arquivo agora!\n";
echo "   Caminho: C:\\xampp\\htdocs\\SAGE\\setup_password.php\n";
echo '</pre>';
?>
