'use strict';

/* ─── Helpers ─────────────────────────────────────────────────────────────── */

function esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function adminGet(url) {
    const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
    });
    return res.json();
}

async function adminPost(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': CSRF,
            'Accept': 'application/json',
        },
        body: JSON.stringify(body),
    });
    return { status: res.status, ...(await res.json()) };
}

function showPageAlert(type, msg) {
    const el = document.getElementById('pageAlert');
    if (!el) return;
    el.className = `alert alert-${type}`;
    el.innerHTML = msg;
    el.classList.remove('d-none');
    window.scrollTo(0, 0);
    setTimeout(() => el.classList.add('d-none'), 6000);
}

/* ─── Stránka: Uživatelé ─────────────────────────────────────────────────── */

function initUsersPage() {
    loadUsers();

    document.getElementById('saveEditUserBtn')?.addEventListener('click', async () => {
        const form    = document.getElementById('editUserForm');
        const alertEl = document.getElementById('editUserAlert');
        const data = {
            id:    parseInt(form.querySelector('[name=id]').value, 10),
            name:  form.querySelector('[name=name]').value.trim(),
            email: form.querySelector('[name=email]').value.trim(),
        };

        if (!data.name || !data.email) {
            showModalAlert(alertEl, 'danger', 'Vyplňte jméno a e-mail.');
            return;
        }

        const btn = document.getElementById('saveEditUserBtn');
        btn.disabled = true;

        const result = await adminPost(API + '?action=update', data);
        btn.disabled = false;

        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('editUserModal'))?.hide();
            showPageAlert('success', 'Údaje uživatele byly uloženy.');
            loadUsers();
        } else {
            showModalAlert(alertEl, 'danger', esc(result.error || 'Chyba'));
        }
    });

    document.getElementById('saveNewUserBtn')?.addEventListener('click', async () => {
        const form   = document.getElementById('newUserForm');
        const alertEl = document.getElementById('newUserAlert');
        const formData = new FormData(form);
        // checkbox — pokud není zaškrtnut, FormData ho neobsahuje
        const data = Object.fromEntries(formData);
        data.can_reopen = formData.has('can_reopen') ? 1 : 0;

        if (!data.name || !data.email) {
            showModalAlert(alertEl, 'danger', 'Vyplňte jméno a e-mail.');
            return;
        }

        const btn = document.getElementById('saveNewUserBtn');
        btn.disabled = true;

        const result = await adminPost(API + '?action=create', data);
        btn.disabled = false;

        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('newUserModal'))?.hide();
            form.reset();
            showPageAlert('success', 'Uživatel byl vytvořen. E-mail s odkazem byl odeslán.');
            loadUsers();
        } else {
            showModalAlert(alertEl, 'danger', esc(result.error || 'Chyba'));
        }
    });
}

async function loadUsers() {
    const tbody = document.getElementById('usersTable');
    if (!tbody) return;

    const res = await adminGet(API + '?action=list');
    if (!res.success) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-danger">${esc(res.error)}</td></tr>`;
        return;
    }

    if (!res.data.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-muted text-center">Žádní uživatelé</td></tr>';
        return;
    }

    tbody.innerHTML = res.data.map(u => `
<tr>
    <td>${esc(u.name)}</td>
    <td>${esc(u.email)}</td>
    <td><span class="badge ${u.role === 'admin' ? 'bg-danger' : 'bg-secondary'}">${esc(u.role)}</span></td>
    <td>
        ${u.is_active
            ? '<span class="badge bg-success">Aktivní</span>'
            : '<span class="badge bg-warning text-dark">Blokován</span>'}
    </td>
    <td class="text-center">
        ${u.role === 'admin'
            ? '<span class="text-muted small">vždy</span>'
            : (u.can_reopen
                ? '<span class="badge bg-success">Ano</span>'
                : '<span class="badge bg-secondary">Ne</span>')}
    </td>
    <td class="text-muted small">${esc(u.last_login_local || '—')}</td>
    <td class="text-nowrap">
        <button class="btn btn-sm btn-outline-primary me-1"
                data-id="${u.id}" data-name="${esc(u.name)}" data-email="${esc(u.email)}"
                onclick="openEditUser(this.dataset.id, this.dataset.name, this.dataset.email)">
            Upravit
        </button>
        <button class="btn btn-sm ${u.is_active ? 'btn-outline-warning' : 'btn-outline-success'} me-1"
                onclick="toggleUser(${u.id}, ${u.is_active})">
            ${u.is_active ? 'Blokovat' : 'Odblokovat'}
        </button>
        ${u.role !== 'admin' ? `
        <button class="btn btn-sm btn-outline-secondary"
                onclick="toggleReopen(${u.id}, ${u.can_reopen})"
                title="Přepnout oprávnění znovuotevření">
            ${u.can_reopen ? 'Zakázat znovuotevření' : 'Povolit znovuotevření'}
        </button>` : ''}
    </td>
</tr>`).join('');
}

function openEditUser(id, name, email) {
    const form = document.getElementById('editUserForm');
    form.querySelector('[name=id]').value = id;
    form.querySelector('[name=name]').value = name;
    form.querySelector('[name=email]').value = email;
    document.getElementById('editUserAlert').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

async function toggleUser(id, isActive) {
    const action = isActive ? 'zablokovat' : 'odblokovat';
    if (!confirm(`Opravdu chcete ${action} tohoto uživatele?`)) return;

    const result = await adminPost(API + '?action=toggle_active', { id });
    if (result.success) {
        loadUsers();
    } else {
        showPageAlert('danger', esc(result.error || 'Chyba'));
    }
}

async function toggleReopen(id, canReopen) {
    const action = canReopen ? 'zakázat' : 'povolit';
    if (!confirm(`Opravdu chcete ${action} znovuotevření pro tohoto uživatele?`)) return;

    const result = await adminPost(API + '?action=toggle_reopen', { id });
    if (result.success) {
        loadUsers();
    } else {
        showPageAlert('danger', esc(result.error || 'Chyba'));
    }
}

function showModalAlert(el, type, msg) {
    if (!el) return;
    el.className = `alert alert-${type}`;
    el.textContent = msg;
    el.classList.remove('d-none');
}

/* ─── Stránka: Statistiky ────────────────────────────────────────────────── */

function initStatsPage() {
    loadStatsByTechnician();
    loadStatsByAge();
}

async function loadStatsByTechnician() {
    const tbody = document.getElementById('techTable');
    if (!tbody) return;

    const url = `${STATS_API}?view=by_technician&from=${FROM_DATE}&to=${TO_DATE}`;
    const res = await adminGet(url);

    if (!res.success) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-danger">${esc(res.error)}</td></tr>`;
        return;
    }

    if (!res.data.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-center">Žádná data</td></tr>';
        return;
    }

    const grandTotal = res.data.reduce((sum, r) => sum + Number(r.total_resolved), 0);
    tbody.innerHTML = res.data.map(r => {
        const pct = grandTotal > 0 ? Math.round(Number(r.total_resolved) / grandTotal * 100) : 0;
        return `
<tr>
    <td>${esc(r.name)}</td>
    <td class="text-end">${r.total_resolved}</td>
    <td class="text-end">${pct} %</td>
    <td class="text-end">${r.avg_minutes !== null ? r.avg_minutes : '—'}</td>
    <td class="text-end">${r.reopened_count}</td>
</tr>`;
    }).join('');
}

async function loadStatsByAge() {
    const tbody = document.getElementById('ageTable');
    if (!tbody) return;

    const url = `${STATS_API}?view=by_age&from=${FROM_DATE}&to=${TO_DATE}`;
    const res = await adminGet(url);

    if (!res.success) {
        tbody.innerHTML = `<tr><td colspan="3" class="text-danger">${esc(res.error)}</td></tr>`;
        return;
    }

    if (!res.data.length) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-muted text-center">Žádná data</td></tr>';
        return;
    }

    const grandTotal = res.data.reduce((sum, r) => sum + Number(r.count), 0);
    tbody.innerHTML = res.data.map(r => {
        const pct = grandTotal > 0 ? Math.round(Number(r.count) / grandTotal * 100) : 0;
        return `
<tr>
    <td>${esc(r.label)}</td>
    <td class="text-end fw-semibold">${r.count}</td>
    <td class="text-end text-muted">${pct} %</td>
</tr>`;
    }).join('');
}
