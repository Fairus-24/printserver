<?php
session_start();

$isAuthenticated = isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user']) && !empty($_SESSION['auth_user']['nim_nipy']);
$authUser = $isAuthenticated ? $_SESSION['auth_user'] : null;
$isAdmin = $isAuthenticated && (($authUser['role'] ?? '') === 'admin');

if (!$isAuthenticated) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen User - Print Server</title>
    <meta name="description" content="Panel manajemen user FIK Smart Print Server untuk admin: tambah, edit, nonaktifkan, dan hapus akun login SQL.">
    <meta name="theme-color" content="#c7f9cc">
    <meta name="msapplication-TileColor" content="#c7f9cc">
    <link rel="icon" type="image/svg+xml" href="assets/brand-icon.svg">
    <link rel="apple-touch-icon" href="assets/brand-icon.svg">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Manajemen User - FIK Smart Print Server">
    <meta property="og:description" content="Kelola akun user berbasis SQL dengan kontrol role dan status aktif.">
    <meta property="og:image" content="assets/meta-card.svg">
    <meta property="og:image:type" content="image/svg+xml">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Manajemen User - FIK Smart Print Server">
    <meta name="twitter:description" content="Kelola akun user berbasis SQL dengan kontrol role dan status aktif.">
    <meta name="twitter:image" content="assets/meta-card.svg">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --primary: #0f766e;
            --primary-dark: #115e59;
            --danger: #dc2626;
            --danger-dark: #b91c1c;
            --warning: #d97706;
            --success: #16a34a;
            --bg: #f1f5f9;
            --text: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
        }

        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(160deg, #c7f9cc 0%, #fef9c3 45%, #bfdbfe 100%);
            min-height: 100vh;
            color: var(--text);
            padding: 24px;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
        }

        .card {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.15);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(140deg, #0f766e 0%, #15803d 55%, #65a30d 100%);
            color: #fff;
            padding: 28px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
        }

        .header h1 {
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header p {
            font-size: 13px;
            opacity: 0.95;
            margin-top: 6px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn {
            border: none;
            border-radius: 10px;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-danger {
            background: var(--danger);
            color: #fff;
        }

        .btn-danger:hover {
            background: var(--danger-dark);
        }

        .btn-secondary {
            background: #334155;
            color: #fff;
        }

        .btn-secondary:hover {
            background: #1e293b;
        }

        .btn-warning {
            background: var(--warning);
            color: #fff;
        }

        .btn-warning:hover {
            background: #b45309;
        }

        .content {
            padding: 24px;
            background: var(--bg);
        }

        .alert {
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13px;
            margin-bottom: 16px;
            display: none;
        }

        .alert.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 18px;
        }

        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 18px;
        }

        .panel h2 {
            font-size: 16px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }

        .input,
        .select {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            padding: 10px 11px;
            font-size: 13px;
            background: #fff;
        }

        .input:focus,
        .select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
        }

        .hint {
            font-size: 12px;
            color: var(--muted);
            margin-top: -4px;
            margin-bottom: 10px;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th,
        td {
            border-bottom: 1px solid var(--line);
            padding: 10px 8px;
            text-align: left;
            vertical-align: middle;
        }

        th {
            font-size: 12px;
            letter-spacing: 0.2px;
            text-transform: uppercase;
            color: #475569;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            gap: 6px;
        }

        .chip.active {
            background: #dcfce7;
            color: #166534;
        }

        .chip.inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .row-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .mini-btn {
            border: none;
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .mini-btn.edit {
            background: #fef3c7;
            color: #92400e;
        }

        .mini-btn.delete {
            background: #fee2e2;
            color: #b91c1c;
        }

        .empty {
            text-align: center;
            color: var(--muted);
            padding: 24px 10px;
            font-size: 13px;
        }

        .denied {
            text-align: center;
            padding: 48px 24px;
        }

        .denied i {
            font-size: 48px;
            color: var(--danger);
            margin-bottom: 14px;
        }

        .denied h2 {
            margin-bottom: 8px;
        }

        .denied p {
            color: var(--muted);
            margin-bottom: 18px;
        }

        .confirm-modal {
            position: fixed;
            inset: 0;
            z-index: 1200;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(2px);
        }

        .confirm-modal.show {
            display: flex;
        }

        .confirm-box {
            width: 100%;
            max-width: 430px;
            background: #fff;
            border-radius: 14px;
            border: 1px solid var(--line);
            box-shadow: 0 25px 50px rgba(2, 6, 23, 0.28);
            padding: 18px;
        }

        .confirm-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 10px;
        }

        .confirm-icon.primary {
            background: #ccfbf1;
            color: #0f766e;
        }

        .confirm-icon.warning {
            background: #fef3c7;
            color: #b45309;
        }

        .confirm-icon.danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .confirm-title {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 6px;
            color: #0f172a;
        }

        .confirm-message {
            font-size: 14px;
            color: #475569;
            line-height: 1.55;
            margin-bottom: 16px;
        }

        .confirm-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        @media (max-width: 860px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="header">
            <div>
                <h1><i class="fas fa-users-cog"></i> Manajemen User</h1>
                <p>Login sebagai: <strong><?php echo htmlspecialchars($authUser['full_name'] ?? '-'); ?></strong> (<?php echo htmlspecialchars($authUser['nim_nipy'] ?? '-'); ?>)</p>
            </div>
            <div class="header-actions">
                <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali</a>
                <button id="logoutBtn" class="btn btn-danger" type="button"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </div>
        </div>

        <div class="content">
            <?php if (!$isAdmin) { ?>
                <div class="denied">
                    <i class="fas fa-user-shield"></i>
                    <h2>Akses Ditolak</h2>
                    <p>Halaman ini hanya dapat diakses oleh user dengan role <strong>admin</strong>.</p>
                    <a href="index.php" class="btn btn-primary"><i class="fas fa-home"></i> Kembali ke Dashboard</a>
                </div>
            <?php } else { ?>
                <div id="alertBox" class="alert"></div>

                <div class="grid">
                    <div class="panel">
                        <h2><i class="fas fa-user-edit"></i> Form User</h2>
                        <form id="userForm">
                            <input type="hidden" id="userId" value="">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="nimNipy">NIM/NIPY</label>
                                    <input id="nimNipy" name="nim_nipy" type="text" class="input" required maxlength="30" placeholder="Contoh: 23123456">
                                </div>
                                <div class="form-group">
                                    <label for="fullName">Nama Lengkap</label>
                                    <input id="fullName" name="full_name" type="text" class="input" required maxlength="120" placeholder="Nama user">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="password">Password</label>
                                    <input id="password" name="password" type="password" class="input" placeholder="Min. 6 karakter">
                                    <div class="hint" id="passwordHint">Wajib diisi saat tambah user. Saat edit boleh dikosongkan.</div>
                                </div>
                                <div class="form-group">
                                    <label for="role">Role</label>
                                    <select id="role" name="role" class="select">
                                        <option value="mahasiswa">mahasiswa</option>
                                        <option value="dosen">dosen</option>
                                        <option value="staff">staff</option>
                                        <option value="admin">admin</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="isActive">Status</label>
                                    <select id="isActive" name="is_active" class="select">
                                        <option value="1">Aktif</option>
                                        <option value="0">Nonaktif</option>
                                    </select>
                                </div>
                            </div>

                            <div class="actions">
                                <button id="submitBtn" type="submit" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Tambah User</button>
                                <button id="cancelEditBtn" type="button" class="btn btn-secondary" style="display:none;"><i class="fas fa-times"></i> Batal Edit</button>
                            </div>
                        </form>
                    </div>

                    <div class="panel">
                        <h2><i class="fas fa-table"></i> Daftar User</h2>
                        <div style="overflow-x:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>NIM/NIPY</th>
                                        <th>Nama</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Dibuat</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="usersTableBody">
                                    <tr><td colspan="7" class="empty">Memuat data user...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="confirmModal" class="confirm-modal" aria-hidden="true" role="dialog" aria-modal="true">
                    <div class="confirm-box">
                        <div id="confirmIcon" class="confirm-icon primary"><i class="fas fa-circle-question"></i></div>
                        <div id="confirmTitle" class="confirm-title">Konfirmasi</div>
                        <div id="confirmMessage" class="confirm-message">Apakah Anda yakin melanjutkan aksi ini?</div>
                        <div class="confirm-actions">
                            <button type="button" id="confirmCancelBtn" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Batal
                            </button>
                            <button type="button" id="confirmOkBtn" class="btn btn-primary">
                                <i class="fas fa-check"></i> Lanjutkan
                            </button>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
</div>

<?php if ($isAdmin) { ?>
<script>
    const userForm = document.getElementById('userForm');
    const userIdInput = document.getElementById('userId');
    const nimNipyInput = document.getElementById('nimNipy');
    const fullNameInput = document.getElementById('fullName');
    const passwordInput = document.getElementById('password');
    const roleInput = document.getElementById('role');
    const isActiveInput = document.getElementById('isActive');
    const submitBtn = document.getElementById('submitBtn');
    const cancelEditBtn = document.getElementById('cancelEditBtn');
    const alertBox = document.getElementById('alertBox');
    const usersTableBody = document.getElementById('usersTableBody');
    const logoutBtn = document.getElementById('logoutBtn');
    const confirmModal = document.getElementById('confirmModal');
    const confirmTitle = document.getElementById('confirmTitle');
    const confirmMessage = document.getElementById('confirmMessage');
    const confirmIcon = document.getElementById('confirmIcon');
    const confirmOkBtn = document.getElementById('confirmOkBtn');
    const confirmCancelBtn = document.getElementById('confirmCancelBtn');

    let usersCache = [];
    let editingUserId = null;
    let confirmResolver = null;

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text ?? '';
        return div.innerHTML;
    }

    function showAlert(message, type = 'success') {
        alertBox.className = `alert ${type}`;
        alertBox.textContent = message;
        alertBox.style.display = 'block';
        setTimeout(() => {
            alertBox.style.display = 'none';
        }, 3500);
    }

    function setConfirmTone(tone) {
        confirmOkBtn.classList.remove('btn-primary', 'btn-warning', 'btn-danger');
        confirmIcon.classList.remove('primary', 'warning', 'danger');

        if (tone === 'danger') {
            confirmOkBtn.classList.add('btn-danger');
            confirmIcon.classList.add('danger');
            confirmIcon.innerHTML = '<i class="fas fa-trash"></i>';
            return;
        }

        if (tone === 'warning') {
            confirmOkBtn.classList.add('btn-warning');
            confirmIcon.classList.add('warning');
            confirmIcon.innerHTML = '<i class="fas fa-pen-to-square"></i>';
            return;
        }

        confirmOkBtn.classList.add('btn-primary');
        confirmIcon.classList.add('primary');
        confirmIcon.innerHTML = '<i class="fas fa-circle-question"></i>';
    }

    function closeConfirm(result) {
        confirmModal.classList.remove('show');
        confirmModal.setAttribute('aria-hidden', 'true');
        if (confirmResolver) {
            const resolver = confirmResolver;
            confirmResolver = null;
            resolver(result);
        }
    }

    function openConfirm(options) {
        const tone = options?.tone || 'primary';
        const title = options?.title || 'Konfirmasi';
        const message = options?.message || 'Apakah Anda yakin ingin melanjutkan?';
        const okText = options?.okText || 'Lanjutkan';
        const cancelText = options?.cancelText || 'Batal';

        setConfirmTone(tone);
        confirmTitle.textContent = title;
        confirmMessage.textContent = message;
        confirmOkBtn.innerHTML = `<i class="fas fa-check"></i> ${escapeHtml(okText)}`;
        confirmCancelBtn.innerHTML = `<i class="fas fa-times"></i> ${escapeHtml(cancelText)}`;

        confirmModal.classList.add('show');
        confirmModal.setAttribute('aria-hidden', 'false');

        setTimeout(() => {
            confirmOkBtn.focus();
        }, 0);

        return new Promise((resolve) => {
            confirmResolver = resolve;
        });
    }

    async function postAction(action, payload) {
        const response = await fetch(`api.php?action=${action}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(payload).toString()
        });
        return response.json();
    }

    confirmOkBtn.addEventListener('click', () => closeConfirm(true));
    confirmCancelBtn.addEventListener('click', () => closeConfirm(false));
    confirmModal.addEventListener('click', (event) => {
        if (event.target === confirmModal) {
            closeConfirm(false);
        }
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && confirmModal.classList.contains('show')) {
            closeConfirm(false);
        }
    });

    async function fetchUsers() {
        const response = await fetch('api.php?action=list_users');
        const data = await response.json();

        if (data.auth_required || data.forbidden) {
            window.location.href = 'index.php';
            return;
        }

        if (!data.success) {
            usersTableBody.innerHTML = `<tr><td colspan="7" class="empty">${escapeHtml(data.message || 'Gagal mengambil data user')}</td></tr>`;
            return;
        }

        usersCache = data.users || [];
        renderUsersTable();
    }

    function formatDate(value) {
        if (!value) return '-';
        const date = new Date(value.replace(' ', 'T'));
        if (isNaN(date.getTime())) return value;
        return date.toLocaleString('id-ID', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function renderUsersTable() {
        if (!usersCache.length) {
            usersTableBody.innerHTML = '<tr><td colspan="7" class="empty">Belum ada user.</td></tr>';
            return;
        }

        usersTableBody.innerHTML = usersCache.map((user) => {
            const statusClass = user.is_active ? 'active' : 'inactive';
            const statusText = user.is_active ? 'Aktif' : 'Nonaktif';
            return `
                <tr>
                    <td>${user.id}</td>
                    <td>${escapeHtml(user.nim_nipy)}</td>
                    <td>${escapeHtml(user.full_name)}</td>
                    <td>${escapeHtml(user.role || '-')}</td>
                    <td><span class="chip ${statusClass}">${statusText}</span></td>
                    <td>${escapeHtml(formatDate(user.created_at))}</td>
                    <td>
                        <div class="row-actions">
                            <button class="mini-btn edit" data-action="edit" data-id="${user.id}" type="button">Edit</button>
                            <button class="mini-btn delete" data-action="delete" data-id="${user.id}" type="button">Hapus</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    function resetForm() {
        editingUserId = null;
        userIdInput.value = '';
        nimNipyInput.value = '';
        fullNameInput.value = '';
        passwordInput.value = '';
        roleInput.value = 'mahasiswa';
        isActiveInput.value = '1';
        submitBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Tambah User';
        cancelEditBtn.style.display = 'none';
        passwordInput.required = true;
    }

    function startEdit(userId) {
        const user = usersCache.find((item) => Number(item.id) === Number(userId));
        if (!user) {
            showAlert('Data user tidak ditemukan untuk diedit.', 'error');
            return;
        }

        editingUserId = user.id;
        userIdInput.value = String(user.id);
        nimNipyInput.value = user.nim_nipy || '';
        fullNameInput.value = user.full_name || '';
        passwordInput.value = '';
        roleInput.value = user.role || 'mahasiswa';
        isActiveInput.value = user.is_active ? '1' : '0';
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Simpan Perubahan';
        cancelEditBtn.style.display = 'inline-flex';
        passwordInput.required = false;
        nimNipyInput.focus();
    }

    async function deleteUser(userId) {
        const user = usersCache.find((item) => Number(item.id) === Number(userId));
        if (!user) {
            showAlert('Data user tidak ditemukan.', 'error');
            return;
        }

        const shouldDelete = await openConfirm({
            tone: 'danger',
            title: 'Konfirmasi Hapus User',
            message: `Hapus user ${user.full_name} (${user.nim_nipy})? Aksi ini tidak bisa dibatalkan.`,
            okText: 'Ya, Hapus User',
            cancelText: 'Tidak'
        });

        if (!shouldDelete) {
            return;
        }

        const data = await postAction('delete_user', {id: user.id});
        if (!data.success) {
            showAlert(data.message || 'Gagal menghapus user', 'error');
            return;
        }

        showAlert(data.message || 'User berhasil dihapus', 'success');
        if (editingUserId === user.id) {
            resetForm();
        }
        await fetchUsers();
    }

    userForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const payload = {
            nim_nipy: nimNipyInput.value.trim(),
            full_name: fullNameInput.value.trim(),
            password: passwordInput.value,
            role: roleInput.value,
            is_active: isActiveInput.value
        };

        if (!payload.nim_nipy || !payload.full_name) {
            showAlert('NIM/NIPY dan nama wajib diisi.', 'error');
            return;
        }

        const isEditing = editingUserId !== null;
        if (!isEditing && !payload.password) {
            showAlert('Password wajib diisi saat tambah user.', 'error');
            return;
        }

        if (isEditing) {
            const shouldUpdate = await openConfirm({
                tone: 'warning',
                title: 'Konfirmasi Simpan Edit',
                message: `Simpan perubahan untuk user ${payload.full_name} (${payload.nim_nipy})?`,
                okText: 'Ya, Simpan',
                cancelText: 'Batal'
            });

            if (!shouldUpdate) {
                return;
            }

            payload.id = String(editingUserId);
        }

        const action = isEditing ? 'update_user' : 'create_user';
        const data = await postAction(action, payload);
        if (!data.success) {
            showAlert(data.message || 'Operasi user gagal', 'error');
            return;
        }

        showAlert(data.message || 'Operasi user berhasil', 'success');
        resetForm();
        await fetchUsers();
    });

    cancelEditBtn.addEventListener('click', resetForm);

    usersTableBody.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const action = target.getAttribute('data-action');
        const userId = target.getAttribute('data-id');
        if (!action || !userId) {
            return;
        }

        if (action === 'edit') {
            startEdit(Number(userId));
        } else if (action === 'delete') {
            deleteUser(Number(userId));
        }
    });

    logoutBtn.addEventListener('click', async () => {
        if (!confirm('Logout sekarang?')) {
            return;
        }

        try {
            await fetch('api.php?action=logout', { method: 'POST' });
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            window.location.href = 'index.php';
        }
    });

    resetForm();
    fetchUsers();
</script>
<?php } ?>
</body>
</html>
