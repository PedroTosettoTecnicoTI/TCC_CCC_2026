

const API_AUTH    = '../backend/php/APIendpoint.php';
const API_STUDENT = '../backend/php/APIstudentEndpoint.php';
const API_USER    = '../backend/php/APIuserEndpoint.php';
const API_DOC     = '../backend/php/APIdocumentEndpoint.php';


let currentUser    = null;
let alunos         = [];
let deleteId       = null;
let editId         = null;
let editUserIdx    = null;
let delUserId      = null;
let docsAlunoIdx   = null;
let currentDocData = null;
let appTheme       = localStorage.getItem('ea_theme') || 'light';


const isAdmin    = () => currentUser?.role === 'admin';
const isLoggedIn = () => currentUser !== null;
const canModify  = () => isLoggedIn() && isAdmin();

function requireAdmin() {
  if (!canModify()) {
    toast('Acesso negado! Apenas administradores podem realizar esta ação.', 'error');
    return false;
  }
  return true;
}

async function apiFetch(url, options = {}) {
  let res;
  try {
    res = await fetch(url, { credentials: 'same-origin', ...options });
  } catch (err) {
    console.error('Erro de rede:', err);
    return { ok: false, success: false, message: 'Não foi possível conectar ao servidor. Verifique se o Apache e o MySQL estão ligados no XAMPP e se o site foi aberto por http://localhost/...' };
  }

  const raw = await res.text();
  try {
    const json = JSON.parse(raw);
    return { ok: res.ok, status: res.status, ...json };
  } catch (err) {
    // O servidor respondeu, mas não com JSON (geralmente erro de PHP)
    console.error(`Resposta inválida (HTTP ${res.status}) de ${url}:`, raw);
    return { ok: false, success: false, status: res.status,
             message: `O servidor respondeu com erro (HTTP ${res.status}). Veja o log do Apache/PHP ou abra diagnostico.php.` };
  }
}

function applyTheme(t) {
  appTheme = t;
  document.documentElement.setAttribute('data-theme', t);
  document.getElementById('btn-light')?.classList.toggle('active', t === 'light');
  document.getElementById('btn-dark')?.classList.toggle('active',  t === 'dark');
  localStorage.setItem('ea_theme', t);
}
function setTheme(t) { applyTheme(t); }


function goTo(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.getElementById(id)?.classList.add('active');

  if (id === 'screen-dashboard') {
    loadAndRenderDash();

    document.querySelectorAll('.btn-export-csv, .btn-export-pdf').forEach(btn => {
      btn.style.display = canModify() ? 'inline-flex' : 'none';
    });
  }

  if (id === 'screen-home') {
   
    const btnDash = document.getElementById('btn-dash');
    if (btnDash) btnDash.style.display = isLoggedIn() ? 'flex' : 'none';

    
    const btnCad = document.querySelector('.home-action-primary');
    if (btnCad) {
      const lbl = btnCad.querySelector('.hac-title');
      const sub = btnCad.querySelector('.hac-sub');
      if (!canModify()) {
        if (lbl) lbl.textContent = '🔒 Cadastrar Aluno';
        if (sub) sub.textContent = 'Apenas administradores';
        btnCad.style.opacity = '.6';
        btnCad.style.cursor  = 'not-allowed';
      } else {
        if (lbl) lbl.textContent = '✏️ Cadastrar Aluno';
        if (sub) sub.textContent = 'Registrar novo aluno no sistema';
        btnCad.style.opacity = '1';
        btnCad.style.cursor  = 'pointer';
      }
    }
  }

  if (id === 'screen-cadastro' && !canModify()) {
    toast('Apenas administradores podem cadastrar alunos.', 'error');
    goTo('screen-home');
  }
}


async function doLogin() {
  const u = document.getElementById('inp-user').value.trim();
  const p = document.getElementById('inp-pass').value.trim();
  if (!u || !p) { toast('Preencha usuário e senha!', 'error'); return; }

  const btn = document.querySelector('.btn-login');
  if (btn) { btn.textContent = 'Entrando...'; btn.disabled = true; }

  const result = await apiFetch(API_AUTH, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ action: 'login', username: u, password: p }),
  });

  if (btn) { btn.textContent = 'Entrar no Sistema'; btn.disabled = false; }

  if (result.success) {
    currentUser = result.user;
    const topbarUser = document.getElementById('topbar-user');
    if (topbarUser) topbarUser.textContent = currentUser.username;
    document.getElementById('inp-user').value = '';
    document.getElementById('inp-pass').value = '';
    toast(`Bem-vindo, ${currentUser.username}! ✅`);
    goTo('screen-home');
  } else {
    toast(result.message || 'Usuário ou senha incorretos!', 'error');
  }
}

async function logout() {
  await apiFetch(API_AUTH, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ action: 'logout' }),
  });
  currentUser = null;
  alunos      = [];
  document.getElementById('inp-user').value = '';
  document.getElementById('inp-pass').value = '';
  closeSettings();
  goTo('screen-login');
  toast('Desconectado com sucesso.');
}


document.addEventListener('DOMContentLoaded', async () => {
  applyTheme(appTheme);

 
  document.getElementById('inp-pass')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') doLogin();
  });

  
  const check = await apiFetch(`${API_AUTH}?action=check`);
  if (check.logged_in) {
    currentUser = check.user;
    const topbarUser = document.getElementById('topbar-user');
    if (topbarUser) topbarUser.textContent = currentUser.username;
    toast(`Bem-vindo de volta, ${currentUser.username}!`);
    goTo('screen-home');
  }
});


function openSettings() {
  document.getElementById('section-usuarios').style.display = isAdmin() ? 'block' : 'none';
  renderUsersList();
  document.getElementById('settings-panel')?.classList.add('open');
  document.getElementById('settings-backdrop').style.display = 'block';
}
function closeSettings() {
  document.getElementById('settings-panel')?.classList.remove('open');
  document.getElementById('settings-backdrop').style.display = 'none';
}

async function renderUsersList() {
  const list = document.getElementById('users-list');
  if (!list || !isAdmin()) return;
  list.innerHTML = '<em style="color:var(--muted);font-size:.85rem">Carregando...</em>';

  const res = await apiFetch(`${API_USER}?action=getAll`);
  if (!res.success) {
    list.innerHTML = '<em style="color:red;font-size:.85rem">Erro ao carregar usuários.</em>';
    return;
  }

  list.innerHTML = '';
  res.data.forEach(u => {
    const isSelf    = u.id == currentUser?.id;
    const lastLogin = u.last_login
      ? new Date(u.last_login).toLocaleDateString('pt-BR')
      : 'nunca';

    const row = document.createElement('div');
    row.className = 'user-row';
    row.innerHTML = `
      <div class="user-row-info">
        <span class="user-row-name">👤 ${escHtml(u.username)} ${isSelf ? '<em style="font-size:.72rem;color:var(--green)">(você)</em>' : ''}</span>
        <span class="user-row-role">
          <span class="badge badge-${u.role}">${u.role}</span>
          <span style="margin-left:.4rem;font-size:.7rem;color:var(--muted)">último: ${lastLogin}</span>
        </span>
      </div>
      <div class="user-row-actions">
        <button class="icon-btn" onclick="openEditUser(${u.id},'${escHtml(u.username)}','${u.role}')" title="Editar">✏️</button>
        ${!isSelf ? `<button class="icon-btn del" onclick="askDelUser(${u.id},'${escHtml(u.username)}')" title="Excluir">🗑️</button>` : ''}
      </div>`;
    list.appendChild(row);
  });
}

function openAddUser() {
  editUserIdx = null;
  document.getElementById('modal-user-title').textContent = 'Adicionar Usuário';
  document.getElementById('nu-username').value    = '';
  document.getElementById('nu-username').disabled = false;
  document.getElementById('nu-pass').value        = '';
  document.getElementById('nu-pass2').value       = '';
  document.getElementById('nu-pass').placeholder  = 'Mínimo 4 caracteres';
  document.getElementById('nu-pass2-wrap').style.display = 'block';
  document.getElementById('nu-role').value        = 'user';
  openModal('modal-user');
}

function openEditUser(id, username, role) {
  editUserIdx = id;
  document.getElementById('modal-user-title').textContent = 'Editar Usuário';
  document.getElementById('nu-username').value    = username;
  document.getElementById('nu-username').disabled = true;
  document.getElementById('nu-pass').value        = '';
  document.getElementById('nu-pass2').value       = '';
  document.getElementById('nu-pass').placeholder  = 'Deixe em branco para não alterar';
  document.getElementById('nu-pass2-wrap').style.display = 'block';
  document.getElementById('nu-role').value        = role;
  openModal('modal-user');
}

async function saveUser() {
  const username = document.getElementById('nu-username').value.trim();
  const pass     = document.getElementById('nu-pass').value.trim();
  const pass2    = document.getElementById('nu-pass2').value.trim();
  const role     = document.getElementById('nu-role').value;

  if (editUserIdx === null) {
    if (!username)               { toast('Informe o nome de usuário!', 'error'); return; }
    if (!pass || pass.length < 4){ toast('Senha deve ter mínimo 4 caracteres!', 'error'); return; }
    if (pass !== pass2)          { toast('Senhas não coincidem!', 'error'); return; }

    const res = await apiFetch(API_USER, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'create', username, password: pass, role }),
    });
    if (res.success) { toast('Usuário criado! ✅'); closeModal('modal-user'); renderUsersList(); }
    else              toast(res.message || 'Erro ao criar usuário.', 'error');
  } else {
    if (pass && pass.length < 4) { toast('Senha deve ter mínimo 4 caracteres!', 'error'); return; }
    if (pass && pass !== pass2)  { toast('Senhas não coincidem!', 'error'); return; }

    const body = { action: 'update', id: editUserIdx, role };
    if (pass) body.password = pass;

    const res = await apiFetch(API_USER, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(body),
    });
    if (res.success) { toast('Usuário atualizado! ✅'); closeModal('modal-user'); renderUsersList(); }
    else              toast(res.message || 'Erro ao atualizar usuário.', 'error');
  }
}

function askDelUser(id, username) {
  delUserId = id;
  document.getElementById('del-user-msg').textContent =
    `Excluir "${username}"? Esta ação não pode ser desfeita.`;
  openModal('modal-del-user');
}

async function confirmDelUser() {
  if (delUserId === null) return;
  const res = await apiFetch(API_USER, {
    method:  'DELETE',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ id: delUserId }),
  });
  if (res.success) { toast('Usuário removido.'); closeModal('modal-del-user'); renderUsersList(); }
  else              toast(res.message || 'Erro ao remover usuário.', 'error');
  delUserId = null;
}


function maskCPF(v) {
  v = v.replace(/\D/g,'').slice(0,11);
  if (v.length > 9)      v = v.replace(/(\d{3})(\d{3})(\d{3})(\d)/,'$1.$2.$3-$4');
  else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d)/,'$1.$2.$3');
  else if (v.length > 3) v = v.replace(/(\d{3})(\d)/,'$1.$2');
  return v;
}

function maskPhone(v) {
  v = v.replace(/\D/g,'').slice(0,11);
  if (v.length > 10)      v = v.replace(/(\d{2})(\d{5})(\d{4})/,'($1) $2-$3');
  else if (v.length > 6)  v = v.replace(/(\d{2})(\d{4})(\d{0,4})/,'($1) $2-$3');
  else if (v.length > 2)  v = v.replace(/(\d{2})(\d{0,5})/,'($1) $2');
  return v;
}

document.addEventListener('DOMContentLoaded', () => {
  ['c-cpf','f-cpf','e-cpf'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', function() { this.value = maskCPF(this.value); });
  });
  ['c-tel','c-tel-resp','e-tel','e-tel-resp'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', function() { this.value = maskPhone(this.value); });
  });
});


function validarCPF(cpf) {
  cpf = cpf.replace(/\D/g,'');
  if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
  let s = 0;
  for (let i = 0; i < 9; i++) s += parseInt(cpf[i]) * (10 - i);
  let r = 11 - (s % 11);
  if ((r > 9 ? 0 : r) !== parseInt(cpf[9])) return false;
  s = 0;
  for (let i = 0; i < 10; i++) s += parseInt(cpf[i]) * (11 - i);
  r = 11 - (s % 11);
  return (r > 9 ? 0 : r) === parseInt(cpf[10]);
}


async function cadastrar() {
  if (!requireAdmin()) return;

  const nome = document.getElementById('c-nome').value.trim();
  const cpf  = document.getElementById('c-cpf').value.trim();

  if (!nome) { toast('Nome é obrigatório!', 'error'); return; }
  if (!cpf)  { toast('CPF é obrigatório!', 'error'); return; }
  if (!validarCPF(cpf)) { toast('CPF inválido! Verifique os dígitos.', 'error'); return; }

  const falecido = document.querySelector('input[name="falecido"]:checked')?.value || 'não';

  const btn = document.getElementById('btn-cadastrar');
  if (btn && btn.disabled) return;             
  const btnHtml = btn ? btn.innerHTML : '';
  if (btn) { btn.textContent = 'Salvando...'; btn.disabled = true; }

  const res = await apiFetch(API_STUDENT, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action:               'create',
      nome,
      cpf,
      email:                document.getElementById('c-email').value.trim()       || null,
      telefone:             document.getElementById('c-tel').value.trim()         || null,
      data_nascimento:      document.getElementById('c-nascimento').value         || null,
      endereco:             document.getElementById('c-end').value.trim()         || null,
      responsavel:          document.getElementById('c-responsavel').value.trim() || null,
      telefone_responsavel: document.getElementById('c-tel-resp').value.trim()    || null,
      responsavel_falecido: falecido,
    }),
  });

  if (btn) { btn.innerHTML = btnHtml; btn.disabled = false; }

  if (res.success) {
    ['c-nome','c-cpf','c-email','c-tel','c-nascimento','c-end','c-responsavel','c-tel-resp']
      .forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
    document.querySelector('input[name="falecido"][value="não"]').checked = true;
    toast(res.message || 'Aluno cadastrado com sucesso! ✅');
    setTimeout(() => goTo('screen-dashboard'), 600);
  } else {
    if (res.detail) console.error('Detalhe do erro (servidor):', res.detail);
    toast(res.message || 'Erro ao cadastrar aluno.', 'error');
  }
}


async function encontrar() {
  if (!isLoggedIn()) { toast('Faça login para pesquisar!', 'error'); return; }

  const nome  = document.getElementById('f-nome').value.trim();
  const cpf   = document.getElementById('f-cpf').value.trim();
  const resp  = document.getElementById('f-resp').value.trim();
  const nasc  = document.getElementById('f-nasc').value;
  const caixa = document.getElementById('f-caixa').value.trim().toLowerCase();

  const body = { action: 'search' };
  if (nome) body.nome            = nome;
  if (cpf)  body.cpf             = cpf;
  if (resp) body.responsavel     = resp;
  if (nasc) body.data_nascimento = nasc;

  const res = await apiFetch(API_STUDENT, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify(body),
  });

  if (!res.success) { toast('Erro na pesquisa.', 'error'); return; }

  // Filtro extra local (campo "caixa")
  let results = res.data;
  if (caixa) {
    results = results.filter(a => {
      const hay = `${a.nome} ${a.cpf} ${a.responsavel||''} ${a.email||''} ${a.telefone||''}`.toLowerCase();
      return hay.includes(caixa);
    });
  }

  const tbody = document.getElementById('find-tbody');
  const empty = document.getElementById('find-empty');
  const wrap  = document.getElementById('find-results');

  wrap.style.display = 'block';
  tbody.innerHTML    = '';

  if (results.length === 0) {
    empty.style.display               = 'block';
    tbody.parentElement.style.display = 'none';
  } else {
    empty.style.display               = 'none';
    tbody.parentElement.style.display = '';
    results.forEach(a => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><strong>${escHtml(a.nome)}</strong></td>
        <td>${escHtml(a.cpf)}</td>
        <td>${a.data_nascimento ? fmtDate(a.data_nascimento) : '—'}</td>
        <td>${escHtml(a.responsavel || '—')}</td>
        <td><span class="badge ${a.responsavel_falecido === 'sim' ? 'badge-sim' : 'badge-nao'}">${a.responsavel_falecido}</span></td>`;
      tbody.appendChild(tr);
    });
  }
  toast(`${results.length} aluno(s) encontrado(s).`);
}


async function loadAndRenderDash() {
 
  const loading  = document.getElementById('dash-loading');
  const table    = document.getElementById('dash-table');
  const empty    = document.getElementById('dash-empty');
  const statsGrid = document.getElementById('stats-grid');

  if (loading) loading.style.display = 'flex';
  if (table)   table.style.display   = 'none';
  if (empty)   empty.style.display   = 'none';

  const res = await apiFetch(`${API_STUDENT}?action=getAll`);

  if (loading) loading.style.display = 'none';

  if (!res.success) {
    toast('Erro ao carregar alunos do banco.', 'error');
    if (empty) { empty.textContent = 'Erro ao carregar dados.'; empty.style.display = 'block'; }
    return;
  }

  alunos = res.data || [];

  
  const falecidos = alunos.filter(a => a.responsavel_falecido === 'sim').length;
  const vivos     = alunos.length - falecidos;

  const elTotal     = document.getElementById('stat-total');
  const elFalecidos = document.getElementById('stat-falecidos');
  const elVivos     = document.getElementById('stat-vivos');
  const badge       = document.getElementById('table-count-badge');

  if (elTotal)     elTotal.textContent     = alunos.length;
  if (elFalecidos) elFalecidos.textContent = falecidos;
  if (elVivos)     elVivos.textContent     = vivos;
  if (badge)       badge.textContent       = `${alunos.length} aluno(s)`;
  if (statsGrid)   statsGrid.style.display = alunos.length > 0 ? 'grid' : 'none';

  renderDash();

  loadDocCounts().then(() => migrateLocalDocs());
}

function renderDash() {
  const tbody = document.getElementById('dash-tbody');
  const empty = document.getElementById('dash-empty');
  const table = document.getElementById('dash-table');

  if (!tbody) return;
  tbody.innerHTML = '';

  if (alunos.length === 0) {
    if (table) table.style.display = 'none';
    if (empty) empty.style.display = 'block';
    return;
  }

  if (table) table.style.display = '';
  if (empty) empty.style.display = 'none';

  alunos.forEach((a, i) => {
    const tr = document.createElement('tr');

    const createdBy = a.created_by_username
      ? `<br><span style="font-size:.65rem;color:var(--muted)">👤 ${escHtml(a.created_by_username)}</span>`
      : '';

    const actionsHtml = canModify()
      ? `<button class="action-btn edit-btn" onclick="openEdit(${a.id})"                              title="Editar">✏️</button>
         <button class="action-btn del-btn"  onclick="askDelete(${a.id},'${escHtml(a.nome)}')"        title="Excluir">✕</button>`
      : `<button class="action-btn edit-btn" onclick="toast('Apenas administradores podem editar.','error')" style="opacity:.5;cursor:not-allowed">🔒</button>`;

    tr.innerHTML = `
      <td><strong>${escHtml(a.nome)}</strong>${createdBy}</td>
      <td>${escHtml(a.cpf)}</td>
      <td>${a.data_nascimento ? fmtDate(a.data_nascimento) : '—'}</td>
      <td>${escHtml(a.telefone || '—')}</td>
      <td>${escHtml(a.responsavel || '—')}</td>
      <td><span class="badge ${a.responsavel_falecido === 'sim' ? 'badge-sim' : 'badge-nao'}">${a.responsavel_falecido}</span></td>
      <td>
        <button class="doc-count-btn" onclick="openDocs(${i})" id="doc-btn-${i}" title="Ver documentos">
          📎 <span id="doc-count-${i}">0</span>
        </button>
      </td>
      <td class="action-cell">${actionsHtml}</td>`;
    tbody.appendChild(tr);
  });
}

function askDelete(id, nome) {
  if (!requireAdmin()) return;
  deleteId = id;
  document.querySelector('#modal-delete p').textContent =
    `Deseja realmente excluir "${nome}"? Esta ação não pode ser desfeita.`;
  openModal('modal-delete');
}

async function confirmDelete() {
  if (deleteId === null) return;
  const res = await apiFetch(API_STUDENT, {
    method:  'DELETE',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ id: deleteId }),
  });
  if (res.success) {
    closeModal('modal-delete');
    toast('Aluno removido com sucesso!');
    loadAndRenderDash();
  } else {
    toast(res.message || 'Erro ao remover aluno.', 'error');
  }
  deleteId = null;
}

function openEdit(id) {
  if (!requireAdmin()) return;
  editId = id;
  const a = alunos.find(x => x.id == id);
  if (!a) return;

  document.getElementById('e-nome').value        = a.nome                   || '';
  document.getElementById('e-email').value       = a.email                  || '';
  document.getElementById('e-cpf').value         = a.cpf                    || '';
  document.getElementById('e-responsavel').value = a.responsavel             || '';
  document.getElementById('e-tel').value         = a.telefone               || '';
  document.getElementById('e-tel-resp').value    = a.telefone_responsavel   || '';
  document.getElementById('e-nascimento').value  = a.data_nascimento        || '';
  document.getElementById('e-end').value         = a.endereco               || '';

  const falVal = a.responsavel_falecido === 'sim' ? 'sim' : 'não';
  const radio  = document.querySelector(`input[name="e-falecido"][value="${falVal}"]`);
  if (radio) radio.checked = true;

  openModal('modal-edit');
}

async function saveEdit() {
  if (editId === null) return;
  const nome = document.getElementById('e-nome').value.trim();
  if (!nome) { toast('Nome é obrigatório!', 'error'); return; }

  const falecido = document.querySelector('input[name="e-falecido"]:checked')?.value || 'não';

  const res = await apiFetch(API_STUDENT, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action:               'update',
      id:                   editId,
      nome,
      email:                document.getElementById('e-email').value.trim()       || null,
      telefone:             document.getElementById('e-tel').value.trim()         || null,
      data_nascimento:      document.getElementById('e-nascimento').value         || null,
      endereco:             document.getElementById('e-end').value.trim()         || null,
      responsavel:          document.getElementById('e-responsavel').value.trim() || null,
      telefone_responsavel: document.getElementById('e-tel-resp').value.trim()    || null,
      responsavel_falecido: falecido,
    }),
  });

  if (res.success) {
    closeModal('modal-edit');
    toast('Aluno atualizado! ✅');
    loadAndRenderDash();
  } else {
    toast(res.message || 'Erro ao atualizar aluno.', 'error');
  }
  editId = null;
}


function exportCSV() {
  if (!requireAdmin()) return;
  if (alunos.length === 0) { toast('Nenhum aluno para exportar!', 'error'); return; }

  const headers = ['Nome','CPF','Email','Telefone','Data Nascimento','Endereço','Responsável','Tel. Responsável','Resp. Falecido'];
  const rows = alunos.map(a => [
    a.nome, a.cpf, a.email, a.telefone,
    a.data_nascimento ? fmtDate(a.data_nascimento) : '',
    a.endereco, a.responsavel, a.telefone_responsavel, a.responsavel_falecido,
  ].map(v => `"${(v||'').replace(/"/g,'""')}"`));

  const csv  = [headers.join(','), ...rows.map(r => r.join(','))].join('\r\n');
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url;
  a.download = `sage_alunos_${new Date().toISOString().slice(0,10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
  toast(`${alunos.length} alunos exportados! ✅`);
}


function exportPDF() {
  if (!requireAdmin()) return;
  if (alunos.length === 0) { toast('Nenhum aluno para exportar!', 'error'); return; }
  if (typeof window.jspdf === 'undefined') { toast('Biblioteca PDF carregando, tente novamente.', 'error'); return; }

  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

  doc.setFillColor(46, 204, 113);
  doc.rect(0, 0, 297, 18, 'F');
  doc.setTextColor(255,255,255);
  doc.setFont('helvetica','bold');
  doc.setFontSize(13);
  doc.text('SAGE — Colégio Comercial Caçapava · Lista de Alunos', 14, 12);
  doc.setFontSize(9);
  doc.setFont('helvetica','normal');
  doc.text(`Gerado em: ${new Date().toLocaleDateString('pt-BR')}   Total: ${alunos.length} aluno(s)`, 200, 12);

  const rows = alunos.map(a => [
    a.nome || '', a.cpf || '',
    a.data_nascimento ? fmtDate(a.data_nascimento) : '—',
    a.telefone || '—', a.responsavel || '—', a.telefone_responsavel || '—',
    a.responsavel_falecido === 'sim' ? 'Sim' : 'Não',
  ]);

  doc.autoTable({
    startY: 22,
    head:   [['Nome','CPF','Nascimento','Telefone','Responsável','Tel. Resp.','Resp. Falecido']],
    body:   rows,
    styles:             { font: 'helvetica', fontSize: 8.5, cellPadding: 3 },
    headStyles:         { fillColor: [39,174,96], textColor: 255, fontStyle: 'bold' },
    alternateRowStyles: { fillColor: [245,250,248] },
    margin: { left: 14, right: 14 },
  });

  doc.save(`sage_alunos_${new Date().toISOString().slice(0,10)}.pdf`);
  toast(`${alunos.length} alunos exportados! ✅`);
}

const DOC_MAX_BYTES = 20 * 1024 * 1024;
const DOC_TYPES     = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
let   docsCache     = [];  

async function loadDocCounts() {
  const res = await apiFetch(`${API_DOC}?action=counts`);
  if (!res.success) return;
  const map = {};
  (res.data || []).forEach(r => { map[r.student_id] = Number(r.total); });
  alunos.forEach((a, i) => {
    const el = document.getElementById(`doc-count-${i}`);
    if (el) el.textContent = map[a.id] || 0;
  });
}

function openDocs(i) {
  if (!isLoggedIn()) { toast('Faça login para ver documentos!', 'error'); return; }
  docsAlunoIdx = i;
  const a = alunos[i];
  if (!a) return;
  document.getElementById('docs-aluno-nome').textContent = a.nome;
  renderDocsList(a);

  const zone = document.getElementById('doc-dropzone');
  if (zone) {
    zone.ondragover  = e => { e.preventDefault(); zone.classList.add('dragover'); };
    zone.ondragleave = () => zone.classList.remove('dragover');
    zone.ondrop      = e => { e.preventDefault(); zone.classList.remove('dragover'); handleDocFiles(Array.from(e.dataTransfer.files), a); };
  }
  openModal('modal-docs');
}

async function renderDocsList(aluno) {
  const list  = document.getElementById('docs-list');
  const empty = document.getElementById('docs-empty');
  if (!list || !empty) return;
  empty.style.display = 'none';
  list.innerHTML = '<em style="color:var(--muted);font-size:.85rem">Carregando...</em>';

  const res = await apiFetch(`${API_DOC}?action=list&student_id=${encodeURIComponent(aluno.id)}`);
  list.innerHTML = '';
  if (!res.success) { toast(res.message || 'Erro ao carregar documentos.', 'error'); return; }

  docsCache = res.data || [];
  if (docsCache.length === 0) { empty.style.display = 'block'; return; }

  docsCache.forEach(doc => {
    const row   = document.createElement('div');
    row.className = 'doc-row';
    const isImg = (doc.mime_type || '').startsWith('image/');
    const when  = doc.uploaded_at ? new Date(String(doc.uploaded_at).replace(' ', 'T')).toLocaleDateString('pt-BR') : '';
    const delBtn = canModify()
      ? `<button class="action-btn del-btn" onclick="deleteDoc(${Number(doc.id)})" title="Remover">✕</button>` : '';
    row.innerHTML = `
      <div class="doc-row-icon">${isImg ? '🖼️' : '📄'}</div>
      <div class="doc-row-info">
        <span class="doc-row-name">${escHtml(doc.filename)}</span>
        <span class="doc-row-meta">${formatBytes(Number(doc.file_size) || 0)} · ${when}${doc.uploaded_by_username ? ' · 👤 ' + escHtml(doc.uploaded_by_username) : ''}</span>
      </div>
      <div class="doc-row-actions">
        <button class="action-btn edit-btn" onclick="viewDoc(${Number(doc.id)})" title="Visualizar">👁</button>
        ${delBtn}
      </div>`;
    list.appendChild(row);
  });
}

function handleDocUpload(event) {
  const a = alunos[docsAlunoIdx];
  if (!a) return;
  handleDocFiles(Array.from(event.target.files), a);
  event.target.value = '';
}

function uploadDocFile(file, aluno) {
  const fd = new FormData();
  fd.append('action', 'upload');
  fd.append('student_id', aluno.id);
  fd.append('file', file);
  // Sem Content-Type manual: o navegador define o boundary do multipart
  return apiFetch(API_DOC, { method: 'POST', body: fd });
}

async function handleDocFiles(files, aluno) {
  let added = 0, failed = 0;
  for (const file of files) {
    if (file.size > DOC_MAX_BYTES) { toast(`"${file.name}" excede 20 MB.`, 'error'); failed++; continue; }
    if (file.type && !DOC_TYPES.includes(file.type)) {
      toast(`"${file.name}" — tipo não suportado (use PDF, JPG ou PNG).`, 'error'); failed++; continue;
    }
    toast(`Enviando "${file.name}"...`);
    const res = await uploadDocFile(file, aluno);
    if (res.success) { added++; }
    else { failed++; toast(`"${file.name}": ${res.message || 'falha no envio.'}`, 'error'); }
  }
  if (added > 0) {
    toast(failed === 0 ? `${added} documento(s) salvo(s) no banco! ✅`
                       : `${added} enviado(s), ${failed} com falha.`, failed === 0 ? 'success' : 'error');
  }
  await renderDocsList(aluno);
  loadDocCounts();
}

async function deleteDoc(id) {
  if (!requireAdmin()) return;
  if (!confirm('Remover este documento? Esta ação não pode ser desfeita.')) return;
  const res = await apiFetch(API_DOC, {
    method:  'DELETE',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ id }),
  });
  if (!res.success) { toast(res.message || 'Erro ao remover documento.', 'error'); return; }
  toast('Documento removido.');
  const a = alunos[docsAlunoIdx];
  if (a) await renderDocsList(a);
  loadDocCounts();
}

async function viewDoc(id) {
  const doc = docsCache.find(d => Number(d.id) === Number(id));
  if (!doc) { toast('Documento não encontrado.', 'error'); return; }

  let res;
  try {
    res = await fetch(`${API_DOC}?action=view&id=${encodeURIComponent(id)}`, { credentials: 'same-origin' });
  } catch (err) {
    toast('Não foi possível conectar ao servidor.', 'error'); return;
  }
  if (!res.ok) {
    let msg = 'Não foi possível abrir o documento.';
    try { msg = (await res.json()).message || msg; } catch (e) {}
    toast(msg, 'error'); return;
  }

  const blob = await res.blob();
  if (currentDocData?.url) URL.revokeObjectURL(currentDocData.url);
  const url  = URL.createObjectURL(blob);
  currentDocData = { url, name: doc.filename, type: doc.mime_type };

  document.getElementById('viewer-title').textContent = doc.filename;
  const body = document.getElementById('viewer-body');
  body.innerHTML = '';
  body.style.display    = '';
  body.style.alignItems = '';

  if (doc.mime_type === 'application/pdf') {
    const iframe = document.createElement('iframe');
    iframe.src   = url;
    iframe.style = 'width:100%;height:100%;border:none;border-radius:8px';
    body.appendChild(iframe);
  } else {
    const img = document.createElement('img');
    img.src   = url;
    img.style = 'max-width:100%;max-height:100%;object-fit:contain;display:block;margin:auto;border-radius:8px';
    body.style.display    = 'flex';
    body.style.alignItems = 'center';
    body.appendChild(img);
  }
  openModal('modal-viewer');
}

function downloadCurrentDoc() {
  if (!currentDocData) return;
  const a = document.createElement('a');
  a.href = currentDocData.url; a.download = currentDocData.name; a.click();
}

/* ── Migração única: documentos que estavam só no navegador (IndexedDB) ──
   Cada arquivo é enviado ao servidor e só é apagado do navegador se o envio der certo. */
const DB_NAME = 'sage_docs', DB_VERSION = 1, STORE = 'documents';
let idbConn = null, migrating = false;

function openDB() {
  return new Promise((resolve, reject) => {
    if (idbConn) { resolve(idbConn); return; }
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = e => {
      const db = e.target.result;
      if (!db.objectStoreNames.contains(STORE)) {
        const store = db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
        store.createIndex('cpf', 'cpf', { unique: false });
      }
    };
    req.onsuccess = e => { idbConn = e.target.result; resolve(idbConn); };
    req.onerror   = e => reject(e.target.error);
  });
}
const dbGetByCpf = cpf => openDB().then(db => new Promise((res,rej) => { const r = db.transaction(STORE,'readonly').objectStore(STORE).index('cpf').getAll(cpf); r.onsuccess=()=>res(r.result); r.onerror=e=>rej(e.target.error); }));
const dbDelete   = id  => openDB().then(db => new Promise((res,rej) => { const r = db.transaction(STORE,'readwrite').objectStore(STORE).delete(id); r.onsuccess=()=>res(); r.onerror=e=>rej(e.target.error); }));

async function migrateLocalDocs() {
  if (migrating || !window.indexedDB) return;
  migrating = true;
  let moved = 0;
  try {
    for (const a of alunos) {
      let docs = [];
      try { docs = await dbGetByCpf(a.cpf); } catch (e) { continue; }
      for (const d of docs) {
        const res = await uploadDocFile(new File([d.data], d.name, { type: d.type }), a);
        if (res.success) { await dbDelete(d.id); moved++; }
        else console.warn(`Migração: "${d.name}" não enviado —`, res.message);
      }
    }
  } catch (e) {
    console.warn('Migração de documentos locais interrompida:', e);
  } finally {
    migrating = false;
  }
  if (moved > 0) {
    toast(`${moved} documento(s) antigo(s) deste navegador foram salvos no banco de dados. ✅`);
    loadDocCounts();
  }
}


function openModal(id)  { document.getElementById(id)?.classList.add('open');    }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }


function fmtDate(d) {
  if (!d) return '—';
  const str = String(d).split('T')[0];
  const [y, m, dd] = str.split('-');
  return `${dd}/${m}/${y}`;
}

function formatBytes(b) {
  if (b < 1024)    return b + ' B';
  if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
  return (b/1048576).toFixed(1) + ' MB';
}

function escHtml(s) {
  return String(s||'')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

let toastTimer;
function toast(msg, type = 'success') {
  const el = document.getElementById('toast');
  if (!el) return;
  el.textContent = msg;
  el.className   = `show ${type}`;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 2800);
}

console.log('🚀 SAGE v2.0 — Colégio Comercial Caçapava — MySQL mode');