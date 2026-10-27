<?php
/**
 * diagnostico.php — Página de diagnóstico do SAGE
 * Acesse: http://localhost/SAGE/diagnostico.php
 * APAGUE após confirmar que tudo funciona!
 */

// Só funciona em localhost
if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
    http_response_code(403);
    die('Acesso negado.');
}

require_once 'backend/php/dataBaseConfiguration.php';

$db     = null;
$erroDb = null;
try {
    $db = (new Database())->getConnection();
} catch (Exception $e) {
    $erroDb = $e->getMessage();
}

// Coleta dados se conectado
$dados = [];
if ($db) {
    $tabelas = ['users','students','audit_log','system_settings','student_documents','sessions','backup_history'];
    foreach ($tabelas as $t) {
        try {
            $r = $db->query("SELECT COUNT(*) AS total FROM $t")->fetch();
            $dados[$t] = $r['total'];
        } catch (Exception $e) {
            $dados[$t] = 'ERRO';
        }
    }
    // Últimos alunos
    try {
        $ultimos = $db->query("SELECT nome, cpf, created_at FROM students WHERE is_active=1 ORDER BY created_at DESC LIMIT 5")->fetchAll();
    } catch (Exception $e) { $ultimos = []; }
    // Usuários cadastrados
    try {
        $usuarios = $db->query("SELECT username, role, last_login FROM users ORDER BY id")->fetchAll();
    } catch (Exception $e) { $usuarios = []; }
    // Versão MySQL
    try {
        $versao = $db->query("SELECT VERSION() AS v")->fetch()['v'];
    } catch (Exception $e) { $versao = 'desconhecido'; }
}

// ── Verificação de estrutura e teste de cadastro (somente com ?testar=1) ──
$colunasFaltando = [];
$testeCadastro   = null;   // null = não executado | ['ok'=>bool,'msg'=>string]
if ($db) {
    $esperadas = ['id','nome','cpf','email','telefone','data_nascimento','endereco',
                  'responsavel','telefone_responsavel','responsavel_falecido','is_active','created_by'];
    try {
        $cols = array_column($db->query("SHOW COLUMNS FROM students")->fetchAll(), 'Field');
        $colunasFaltando = array_values(array_diff($esperadas, $cols));
    } catch (Exception $e) { $colunasFaltando = ['(tabela students inacessível: ' . $e->getMessage() . ')']; }

    if (isset($_GET['testar'])) {
        try {
            $db->beginTransaction();
            $cpfTeste = 'TESTE-' . substr(uniqid(), -8);          // 14 caracteres, único
            $st = $db->prepare("INSERT INTO students SET nome=:n, cpf=:c, responsavel_falecido=:f");
            $st->execute([':n' => 'Teste de Diagnostico', ':c' => $cpfTeste, ':f' => 'não']);
            $id = $db->lastInsertId();
            $au = $db->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id) VALUES (NULL,'CREATE','students',:r)");
            $au->execute([':r' => $id]);
            $db->rollBack();                                       // nada fica gravado
            $testeCadastro = ['ok' => true, 'msg' => 'INSERT em students + audit_log funcionou (e foi desfeito com ROLLBACK).'];
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $testeCadastro = ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SAGE — Diagnóstico</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; padding: 2rem; }
  h1   { font-size: 1.6rem; color: #2ecc71; margin-bottom: .3rem; }
  .sub { color: #64748b; font-size: .85rem; margin-bottom: 2rem; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px,1fr)); gap: 1.2rem; }
  .card { background: #1e293b; border-radius: 12px; padding: 1.3rem; border: 1px solid #334155; }
  .card h2 { font-size: .95rem; color: #94a3b8; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: .05em; }
  .status { display: flex; align-items: center; gap: .6rem; font-size: 1rem; font-weight: 600; }
  .ok   { color: #2ecc71; }
  .err  { color: #e74c3c; }
  .warn { color: #f39c12; }
  .dot  { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
  .dot.ok  { background: #2ecc71; box-shadow: 0 0 8px #2ecc71; }
  .dot.err { background: #e74c3c; box-shadow: 0 0 8px #e74c3c; }
  table { width: 100%; border-collapse: collapse; font-size: .85rem; margin-top: .5rem; }
  th, td { padding: .5rem .7rem; text-align: left; border-bottom: 1px solid #334155; }
  th { color: #94a3b8; font-weight: 600; }
  td { color: #cbd5e1; }
  .badge { display: inline-block; padding: .15rem .5rem; border-radius: 99px; font-size: .75rem; font-weight: 700; }
  .badge-admin { background: #7c3aed22; color: #a78bfa; border: 1px solid #7c3aed; }
  .badge-user  { background: #0369a122; color: #38bdf8; border: 1px solid #0369a1; }
  .num { font-size: 2rem; font-weight: 800; color: #2ecc71; }
  .label { font-size: .8rem; color: #64748b; margin-top: .2rem; }
  .count-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem; }
  .count-item { background: #0f172a; border-radius: 8px; padding: .8rem; text-align: center; }
  .tip { margin-top: 1.5rem; background: #1e293b; border: 1px solid #f39c1244; border-radius: 12px; padding: 1rem 1.3rem; }
  .tip h3 { color: #f39c12; margin-bottom: .5rem; font-size: .9rem; }
  .tip p  { color: #94a3b8; font-size: .83rem; line-height: 1.6; }
  code { background: #0f172a; color: #2ecc71; padding: .1rem .4rem; border-radius: 4px; font-size: .82rem; }
  .php-info { color: #94a3b8; font-size: .82rem; line-height: 1.8; }
</style>
</head>
<body>

<h1>🔍 SAGE — Diagnóstico do Banco de Dados</h1>
<p class="sub">Gerado em: <?= date('d/m/Y H:i:s') ?> &nbsp;|&nbsp; Servidor: <?= $_SERVER['SERVER_NAME'] ?> &nbsp;|&nbsp; PHP <?= PHP_VERSION ?></p>

<div class="grid">

  <!-- CONEXÃO -->
  <div class="card">
    <h2>1. Conexão com o MySQL</h2>
    <?php if ($db): ?>
      <div class="status ok"><div class="dot ok"></div> Conectado com sucesso!</div>
      <p style="color:#64748b;font-size:.82rem;margin-top:.7rem">
        Banco: <strong style="color:#e2e8f0">sage_db</strong><br>
        MySQL: <strong style="color:#e2e8f0"><?= $versao ?></strong><br>
        Host: <strong style="color:#e2e8f0">localhost</strong>
      </p>
    <?php else: ?>
      <div class="status err"><div class="dot err"></div> FALHA na conexão!</div>
      <p style="color:#e74c3c;font-size:.82rem;margin-top:.7rem"><?= htmlspecialchars($erroDb ?? 'Erro desconhecido') ?></p>
      <p style="color:#64748b;font-size:.8rem;margin-top:.5rem">
        → Verifique se o MySQL está rodando no XAMPP<br>
        → Confirme que o banco <code>sage_db</code> foi importado
      </p>
    <?php endif; ?>
  </div>

  <!-- SESSÃO PHP -->
  <div class="card">
    <h2>2. Sessão PHP (Login)</h2>
    <?php
      session_start();
      $logado = isset($_SESSION['user_id']);
    ?>
    <?php if ($logado): ?>
      <div class="status ok"><div class="dot ok"></div> Sessão ativa</div>
      <p style="color:#64748b;font-size:.82rem;margin-top:.7rem">
        Usuário: <strong style="color:#e2e8f0"><?= htmlspecialchars($_SESSION['username']) ?></strong><br>
        Perfil: <span class="badge badge-<?= $_SESSION['role'] ?>"><?= $_SESSION['role'] ?></span><br>
        ID: <strong style="color:#e2e8f0"><?= $_SESSION['user_id'] ?></strong>
      </p>
    <?php else: ?>
      <div class="status warn"><div class="dot" style="background:#f39c12"></div> Nenhuma sessão ativa</div>
      <p style="color:#64748b;font-size:.82rem;margin-top:.7rem">
        Nenhum usuário logado no momento.<br>
        Acesse o site e faça login para testar.
      </p>
    <?php endif; ?>
  </div>

  <!-- CONTAGEM POR TABELA -->
  <?php if ($db): ?>
  <div class="card">
    <h2>3. Registros por Tabela</h2>
    <div class="count-grid">
      <?php
      $icons = [
        'students'         => '🎓 Alunos',
        'users'            => '👤 Usuários',
        'audit_log'        => '📋 Auditoria',
        'student_documents'=> '📎 Documentos',
        'system_settings'  => '⚙️ Configs',
        'sessions'         => '🔑 Sessões',
        'backup_history'   => '💾 Backups',
      ];
      foreach ($dados as $tabela => $total):
      ?>
        <div class="count-item">
          <div class="num"><?= $total ?></div>
          <div class="label"><?= $icons[$tabela] ?? $tabela ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- USUÁRIOS NO BANCO -->
  <div class="card">
    <h2>4. Usuários Cadastrados</h2>
    <?php if (count($usuarios) > 0): ?>
    <table>
      <tr><th>Usuário</th><th>Perfil</th><th>Último Acesso</th></tr>
      <?php foreach ($usuarios as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['username']) ?></td>
        <td><span class="badge badge-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
        <td><?= $u['last_login'] ? date('d/m H:i', strtotime($u['last_login'])) : 'nunca' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
      <p style="color:#e74c3c;font-size:.85rem">Nenhum usuário encontrado! Execute o setup_password.php</p>
    <?php endif; ?>
  </div>

  <!-- ÚLTIMOS ALUNOS -->
  <div class="card" style="grid-column: 1 / -1">
    <h2>5. Últimos Alunos Cadastrados no Banco</h2>
    <?php if (count($ultimos) > 0): ?>
    <table>
      <tr><th>Nome</th><th>CPF</th><th>Cadastrado em</th></tr>
      <?php foreach ($ultimos as $a): ?>
      <tr>
        <td><?= htmlspecialchars($a['nome']) ?></td>
        <td><?= htmlspecialchars($a['cpf']) ?></td>
        <td><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
      <p style="color:#64748b;font-size:.85rem">
        ✅ Nenhum aluno cadastrado ainda. Acesse o site e cadastre um aluno — depois recarregue esta página para confirmar que foi salvo no banco.
      </p>
    <?php endif; ?>
  </div>

  <!-- TESTE DE CADASTRO -->
  <div class="card">
    <h2>7. Teste de Cadastro</h2>
    <?php if ($colunasFaltando): ?>
      <div class="status err"><div class="dot err"></div> Estrutura da tabela incompleta</div>
      <p style="color:#e74c3c;font-size:.82rem;margin-top:.7rem">
        Colunas ausentes em <code>students</code>: <?= htmlspecialchars(implode(', ', $colunasFaltando)) ?><br>
        → Reimporte o <code>database/BDsage.sql</code> (atenção: apaga os dados atuais — faça backup antes).
      </p>
    <?php else: ?>
      <div class="status ok"><div class="dot ok"></div> Colunas de <code>students</code> OK</div>
    <?php endif; ?>

    <?php if ($testeCadastro === null): ?>
      <p style="color:#64748b;font-size:.82rem;margin:.9rem 0 .6rem">
        Simula um cadastro (INSERT) e desfaz em seguida, mostrando o erro exato do MySQL se houver.
      </p>
      <a href="?testar=1" style="color:#2ecc71;font-weight:700;font-size:.85rem">▶ Executar teste de cadastro</a>
    <?php elseif ($testeCadastro['ok']): ?>
      <p style="color:#2ecc71;font-size:.82rem;margin-top:.9rem">✅ <?= htmlspecialchars($testeCadastro['msg']) ?></p>
    <?php else: ?>
      <p style="color:#e74c3c;font-size:.82rem;margin-top:.9rem">❌ Falhou: <?= htmlspecialchars($testeCadastro['msg']) ?></p>
    <?php endif; ?>
  </div>

  <!-- INFO PHP -->
  <div class="card">
    <h2>6. Informações do Servidor</h2>
    <div class="php-info">
      PHP PDO MySQL: <strong style="color:<?= extension_loaded('pdo_mysql') ? '#2ecc71' : '#e74c3c' ?>"><?= extension_loaded('pdo_mysql') ? '✅ Ativo' : '❌ Inativo' ?></strong><br>
      Sessões PHP: <strong style="color:<?= session_status() === PHP_SESSION_ACTIVE ? '#2ecc71' : '#f39c12' ?>"><?= session_status() === PHP_SESSION_ACTIVE ? '✅ Ativo' : '⚠️ Inativo' ?></strong><br>
      JSON: <strong style="color:<?= function_exists('json_encode') ? '#2ecc71' : '#e74c3c' ?>"><?= function_exists('json_encode') ? '✅ Ativo' : '❌ Inativo' ?></strong><br>
      Diretório SAGE: <strong style="color:#e2e8f0"><?= __DIR__ ?></strong><br>
      <?php
        $up   = __DIR__ . '/backend/uploads';
        $upOk = is_dir($up) ? is_writable($up) : is_writable(__DIR__ . '/backend');
        $toMb = function ($v) { $n = (int)$v; $u = strtoupper(substr(trim($v), -1));
                                return $u === 'G' ? $n * 1024 : ($u === 'K' ? $n / 1024 : $n); };
        $umf  = ini_get('upload_max_filesize'); $pms = ini_get('post_max_size');
        $limOk = $toMb($umf) >= 20 && $toMb($pms) >= 20;
      ?>
      Pasta de documentos (backend/uploads): <strong style="color:<?= $upOk ? '#2ecc71' : '#e74c3c' ?>"><?= $upOk ? '✅ Gravável' : '❌ Sem permissão de escrita' ?></strong><br>
      upload_max_filesize: <strong style="color:<?= $toMb($umf) >= 20 ? '#2ecc71' : '#f39c12' ?>"><?= htmlspecialchars($umf) ?></strong>
      &nbsp;|&nbsp; post_max_size: <strong style="color:<?= $toMb($pms) >= 20 ? '#2ecc71' : '#f39c12' ?>"><?= htmlspecialchars($pms) ?></strong><br>
      <?php if (!$limOk): ?><span style="color:#f39c12">⚠️ Para anexar arquivos de até 20 MB, aumente esses valores no php.ini do XAMPP e reinicie o Apache.</span><br><?php endif; ?>
      Extensão fileinfo: <strong style="color:<?= class_exists('finfo') ? '#2ecc71' : '#f39c12' ?>"><?= class_exists('finfo') ? '✅ Ativa' : '⚠️ Inativa (tipo do arquivo checado só pela extensão)' ?></strong>
    </div>
  </div>
  <?php endif; ?>

</div>

<div class="tip">
  <h3>⚠️ Como confirmar que o banco está sendo usado (passo a passo):</h3>
  <p>
    1. Acesse <code>http://localhost/SAGE/setup_password.php</code> para definir as senhas<br>
    2. Acesse <code>http://localhost/SAGE/frontend/index.html</code> e faça login com <code>admin</code> / <code>admin123</code><br>
    3. Cadastre um aluno de teste<br>
    4. Volte aqui e recarregue a página — o aluno aparecerá na tabela "Últimos Alunos Cadastrados"<br>
    5. Confirme também no <strong>phpMyAdmin</strong> → banco <code>sage_db</code> → tabela <code>students</code><br>
    6. <strong style="color:#e74c3c">Apague este arquivo e o setup_password.php após confirmar!</strong>
  </p>
</div>

</body>
</html>
