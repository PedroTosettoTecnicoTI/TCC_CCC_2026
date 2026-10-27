
let alunos = [];
let users = [];
let currentUser = null;
let deleteIdx = null;
let editUserIdx = null;
let delUserIdx = null;

const API_BASE = '/api/';


async function apiCall(endpoint, method = 'POST', data = null) {
    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json',
        }
    };
    
    if (data) {
        options.body = JSON.stringify(data);
    }
    
    try {
        const response = await fetch(API_BASE + endpoint, options);
        const result = await response.json();
        return result;
    } catch (error) {
        console.error('API Error:', error);
        toast('Erro de conexão com o servidor');
        return null;
    }
}

async function doLogin() {
    const username = document.getElementById('inp-user').value.trim();
    const password = document.getElementById('inp-pass').value.trim();
    
    if (!username || !password) {
        toast('Preencha usuário e senha!');
        return;
    }
    
    const result = await apiCall('auth.php', 'POST', {
        action: 'login',
        username: username,
        password: password
    });
    
    if (result && result.success) {
        currentUser = result.user;
        document.getElementById('topbar-user').textContent = currentUser.username;
        
        // Load data after login
        await loadStudents();
        if (currentUser.role === 'admin') {
            await loadUsers();
        }
        
        goTo('screen-home');
        toast('Login realizado com sucesso!');
    } else {
        toast('Usuário ou senha incorretos!');
    }
}

function logout() {
    apiCall('auth.php', 'POST', { action: 'logout' });
    currentUser = null;
    alunos = [];
    users = [];
    document.getElementById('inp-user').value = '';
    document.getElementById('inp-pass').value = '';
    closeSettings();
    goTo('screen-login');
}


async function loadStudents() {
    const result = await apiCall('students.php?action=getAll', 'GET');
    if (result && result.success) {
        alunos = result.data;
        saveAlunos();
    }
}

async function loadUsers() {
    if (currentUser && currentUser.role === 'admin') {
        const result = await apiCall('users.php?action=getAll', 'GET');
        if (result && result.success) {
            users = result.data;
            saveUsers();
        }
    }
}


async function cadastrar() {
    const nome = document.getElementById('c-nome').value.trim();
    const cpf = document.getElementById('c-cpf').value.trim();
    
    if (!nome || !cpf) {
        toast('Nome e CPF são obrigatórios!');
        return;
    }
    
    const falecido = document.querySelector('input[name="falecido"]:checked')?.value || 'não';
    
    const studentData = {
        action: 'create',
        nome: nome,
        cpf: cpf,
        email: document.getElementById('c-email').value.trim(),
        telefone: document.getElementById('c-tel').value.trim(),
        data_nascimento: document.getElementById('c-nascimento').value,
        endereco: document.getElementById('c-end').value.trim(),
        responsavel: document.getElementById('c-responsavel').value.trim(),
        telefone_responsavel: document.getElementById('c-tel-resp').value.trim(),
        responsavel_falecido: falecido
    };
    
    const result = await apiCall('students.php', 'POST', studentData);
    
    if (result && result.success) {
        await loadStudents(); 
        clearCadastroForm();
        toast('Aluno cadastrado com sucesso! ✓');
        setTimeout(() => goTo('screen-dashboard'), 700);
    } else {
        toast('Erro ao cadastrar aluno!');
    }
}

function clearCadastroForm() {
    ['c-nome', 'c-cpf', 'c-email', 'c-tel', 'c-nascimento', 
     'c-end', 'c-responsavel', 'c-tel-resp'].forEach(id =>
        document.getElementById(id).value = ''
    );
    document.querySelector('input[name="falecido"][value="não"]').checked = true;
}


async function encontrar() {
    const nome = document.getElementById('f-nome').value.trim();
    const cpf = document.getElementById('f-cpf').value.trim();
    const resp = document.getElementById('f-resp').value.trim();
    const nasc = document.getElementById('f-nasc').value;
    
    const searchData = {
        action: 'search',
        nome: nome,
        cpf: cpf,
        responsavel: resp,
        data_nascimento: nasc
    };
    
    const result = await apiCall('students.php', 'POST', searchData);
    
    if (result && result.success) {
        displaySearchResults(result.data);
    } else {
        toast('Erro na busca');
    }
}

function displaySearchResults(results) {
    const tbody = document.getElementById('find-tbody');
    const empty = document.getElementById('find-empty');
    const wrap = document.getElementById('find-results');
    
    wrap.style.display = 'block';
    tbody.innerHTML = '';
    
    if (results.length === 0) {
        empty.style.display = 'block';
        tbody.parentElement.style.display = 'none';
    } else {
        empty.style.display = 'none';
        tbody.parentElement.style.display = '';
        
        results.forEach(a => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escapeHtml(a.nome)}</td>
                <td>${a.cpf}</td>
                <td>${a.data_nascimento ? formatDate(a.data_nascimento) : '—'}</td>
                <td>${escapeHtml(a.responsavel || '—')}</td>
                <td><span class="badge ${a.responsavel_falecido === 'sim' ? 'badge-sim' : 'badge-nao'}">${a.responsavel_falecido}</span></td>
            `;
            tbody.appendChild(tr);
        });
    }
}


async function renderDash() {
    await loadStudents(); 
    
    const tbody = document.getElementById('dash-tbody');
    const empty = document.getElementById('dash-empty');
    
    tbody.innerHTML = '';
    
    if (alunos.length === 0) {
        empty.style.display = 'block';
        tbody.parentElement.style.display = 'none';
    } else {
        empty.style.display = 'none';
        tbody.parentElement.style.display = '';
        
        alunos.forEach((a, i) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${escapeHtml(a.nome)}</strong></td>
                <td>${a.cpf}</td>
                <td>${a.data_nascimento ? formatDate(a.data_nascimento) : '—'}</td>
                <td>${a.telefone || '—'}</td>
                <td>${escapeHtml(a.responsavel || '—')}</td>
                <td><span class="badge ${a.responsavel_falecido === 'sim' ? 'badge-sim' : 'badge-nao'}">${a.responsavel_falecido}</span></td>
                <td><button onclick="askDelete(${a.id})" class="delete-btn">✕</button></td>
            `;
            tbody.appendChild(tr);
        });
    }
}

async function askDelete(id) {
    deleteIdx = id;
    openModal('modal-delete');
}

async function confirmDelete() {
    if (deleteIdx === null) return;
    
    const result = await apiCall('students.php', 'DELETE', { id: deleteIdx });
    
    if (result && result.success) {
        await loadStudents();
        await renderDash();
        closeModal('modal-delete');
        toast('Aluno removido.');
        deleteIdx = null;
    } else {
        toast('Erro ao remover aluno.');
    }
}

async function renderUsersList() {
    if (currentUser && currentUser.role === 'admin') {
        await loadUsers();
        
        const list = document.getElementById('users-list');
        list.innerHTML = '';
        
        users.forEach((u, i) => {
            const row = document.createElement('div');
            row.className = 'user-row';
            const isSelf = currentUser && u.username === currentUser.username;
            row.innerHTML = `
                <div class="user-row-info">
                    <span class="user-row-name">👤 ${escapeHtml(u.username)} ${isSelf ? '<em style="font-size:.75rem;color:var(--green)">(você)</em>' : ''}</span>
                    <span class="user-row-role"><span class="badge ${u.role === 'admin' ? 'badge-admin' : 'badge-user'}">${u.role}</span></span>
                </div>
                <div class="user-row-actions">
                    <button class="icon-btn" onclick="openEditUser(${u.id})" title="Editar">✏️</button>
                    ${!isSelf ? `<button class="icon-btn del" onclick="askDelUser(${u.id})" title="Excluir">🗑️</button>` : ''}
                </div>`;
            list.appendChild(row);
        });
    }
}

function openAddUser() {
    editUserIdx = null;
    document.getElementById('modal-user-title').textContent = 'Adicionar Usuário';
    document.getElementById('nu-username').value = '';
    document.getElementById('nu-pass').value = '';
    document.getElementById('nu-pass2').value = '';
    document.getElementById('nu-role').value = 'user';
    document.getElementById('nu-pass').placeholder = 'Mínimo 4 caracteres';
    document.getElementById('nu-pass2-wrap').style.display = 'block';
    document.getElementById('nu-username').disabled = false;
    openModal('modal-user');
}

async function openEditUser(id) {
    editUserIdx = id;
    const user = users.find(u => u.id === id);
    
    if (user) {
        document.getElementById('modal-user-title').textContent = 'Editar Usuário';
        document.getElementById('nu-username').value = user.username;
        document.getElementById('nu-username').disabled = true;
        document.getElementById('nu-pass').value = '';
        document.getElementById('nu-pass2').value = '';
        document.getElementById('nu-pass').placeholder = 'Deixe em branco para não alterar';
        document.getElementById('nu-pass2-wrap').style.display = 'block';
        document.getElementById('nu-role').value = user.role;
        openModal('modal-user');
    }
}

async function saveUser() {
    const username = document.getElementById('nu-username').value.trim();
    const pass = document.getElementById('nu-pass').value.trim();
    const pass2 = document.getElementById('nu-pass2').value.trim();
    const role = document.getElementById('nu-role').value;
    
    if (editUserIdx === null) {
        // Create new user
        if (!username) {
            toast('Informe o nome de usuário!');
            return;
        }
        if (!pass || pass.length < 4) {
            toast('Senha deve ter mínimo 4 caracteres!');
            return;
        }
        if (pass !== pass2) {
            toast('Senhas não coincidem!');
            return;
        }
        
        const result = await apiCall('users.php', 'POST', {
            action: 'create',
            username: username,
            password: pass,
            role: role
        });
        
        if (result && result.success) {
            await renderUsersList();
            closeModal('modal-user');
            toast('Usuário criado! ✓');
        } else {
            toast('Erro ao criar usuário');
        }
    } else {
        // Update user
        const updateData = {
            action: 'update',
            id: editUserIdx,
            role: role
        };
        
        if (pass) {
            if (pass.length < 4) {
                toast('Senha deve ter mínimo 4 caracteres!');
                return;
            }
            if (pass !== pass2) {
                toast('Senhas não coincidem!');
                return;
            }
            updateData.password = pass;
        }
        
        const result = await apiCall('users.php', 'POST', updateData);
        
        if (result && result.success) {
            await renderUsersList();
            closeModal('modal-user');
            toast('Usuário atualizado! ✓');
        } else {
            toast('Erro ao atualizar usuário');
        }
    }
}

async function askDelUser(id) {
    delUserIdx = id;
    const user = users.find(u => u.id === id);
    document.getElementById('del-user-msg').textContent =
        `Excluir "${user.username}"? Esta ação não pode ser desfeita.`;
    openModal('modal-del-user');
}

async function confirmDelUser() {
    if (delUserIdx === null) return;
    
    const result = await apiCall('users.php', 'DELETE', { id: delUserIdx });
    
    if (result && result.success) {
        await renderUsersList();
        closeModal('modal-del-user');
        toast('Usuário removido.');
        delUserIdx = null;
    } else {
        toast('Erro ao remover usuário');
    }
}


function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const [year, month, day] = dateStr.split('-');
    return `${day}/${month}/${year}`;
}

function saveAlunos() {
    
}

function saveUsers() {
    
}

// CPF Mask
function maskCPF(v) {
    v = v.replace(/\D/g, '').slice(0, 11);
    if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d)/, '$1.$2.$3-$4');
    else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d)/, '$1.$2.$3');
    else if (v.length > 3) v = v.replace(/(\d{3})(\d)/, '$1.$2');
    return v;
}


['c-cpf', 'f-cpf'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', function() { this.value = maskCPF(this.value); });
});


function openSettings() {
    document.getElementById('section-usuarios').style.display =
        (currentUser && currentUser.role === 'admin') ? 'block' : 'none';
    renderUsersList();
    document.getElementById('settings-panel').classList.add('open');
    document.getElementById('settings-backdrop').style.display = 'block';
}

function closeSettings() {
    document.getElementById('settings-panel').classList.remove('open');
    document.getElementById('settings-backdrop').style.display = 'none';
}

function setTheme(t) {
    appTheme = t;
    document.documentElement.setAttribute('data-theme', t);
    document.getElementById('btn-light').classList.toggle('active', t === 'light');
    document.getElementById('btn-dark').classList.toggle('active', t === 'dark');
    localStorage.setItem('ea_theme', t);
}


function openModal(id) {
    document.getElementById(id).style.display = 'block';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}


function toast(msg) {
    const el = document.createElement('div');
    el.className = 'toast';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => {
        el.classList.add('show');
        setTimeout(() => {
            el.classList.remove('show');
            setTimeout(() => document.body.removeChild(el), 300);
        }, 3000);
    }, 100);
}