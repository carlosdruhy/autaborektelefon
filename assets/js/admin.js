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

/* ─── Výběr poboček uživatele (M:N + domovská) ──────────────────────────── */

function renderBranchPicker(container, selectedIds, defaultId) {
    if (!container) return;
    const selected = new Set(selectedIds.map(Number));
    container.innerHTML = `
<div class="mb-3">
    <label class="form-label">Pobočky</label>
    <div class="border rounded p-2">
        ${BRANCHES.map(b => {
            const checked  = selected.has(b.id);
            const disabled = !b.is_active && !checked;
            return `
        <div class="form-check">
            <input class="form-check-input branch-check" type="checkbox" value="${b.id}"
                   id="br_${container.closest('form').id}_${b.id}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
            <label class="form-check-label" for="br_${container.closest('form').id}_${b.id}">
                <span class="badge-branch">${esc(b.code)}</span> ${esc(b.name)}
                ${b.is_active ? '' : '<span class="text-muted small">(neaktivní)</span>'}
            </label>
        </div>`;
        }).join('')}
    </div>
    <label class="form-label mt-2">Domovská pobočka</label>
    <select class="form-select branch-default"></select>
    <div class="form-text">Předvyplní se u nového požadavku. Uživatel bez pobočky nevidí žádné požadavky.</div>
</div>`;

    const select = container.querySelector('.branch-default');
    const refreshDefault = (preferred) => {
        const ids = [...container.querySelectorAll('.branch-check:checked')].map(c => Number(c.value));
        const keep = ids.includes(Number(preferred)) ? Number(preferred) : ids[0];
        select.innerHTML = ids.length
            ? ids.map(id => {
                const b = BRANCHES.find(x => x.id === id);
                return `<option value="${id}" ${id === keep ? 'selected' : ''}>${esc(b ? b.code + ' – ' + b.name : id)}</option>`;
            }).join('')
            : '<option value="0">— žádná —</option>';
        select.disabled = !ids.length;
    };
    container.querySelectorAll('.branch-check').forEach(c =>
        c.addEventListener('change', () => refreshDefault(select.value)));
    refreshDefault(defaultId);
}

function readBranchPicker(container) {
    return {
        branch_ids: [...container.querySelectorAll('.branch-check:checked')].map(c => Number(c.value)),
        default_branch_id: Number(container.querySelector('.branch-default')?.value || 0),
    };
}

let usersById = {};

function initUsersPage() {
    loadUsers();

    document.getElementById('newUserModal')?.addEventListener('show.bs.modal', () => {
        renderBranchPicker(document.querySelector('#newUserForm .branch-picker'), [], 0);
    });

    document.getElementById('saveEditUserBtn')?.addEventListener('click', async () => {
        const form    = document.getElementById('editUserForm');
        const alertEl = document.getElementById('editUserAlert');
        const data = {
            id:    parseInt(form.querySelector('[name=id]').value, 10),
            name:  form.querySelector('[name=name]').value.trim(),
            email: form.querySelector('[name=email]').value.trim(),
            ...readBranchPicker(form.querySelector('.branch-picker')),
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
        Object.assign(data, readBranchPicker(form.querySelector('.branch-picker')));

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
        tbody.innerHTML = `<tr><td colspan="8" class="text-danger">${esc(res.error)}</td></tr>`;
        return;
    }

    if (!res.data.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center">Žádní uživatelé</td></tr>';
        return;
    }

    usersById = Object.fromEntries(res.data.map(u => [u.id, u]));

    // Kódy poboček uživatele; domovská tučně
    const branchCell = u => {
        if (!u.branch_ids.length) {
            return u.role === 'admin'
                ? '<span class="text-muted small">všechny</span>'
                : '<span class="badge bg-warning text-dark">žádná</span>';
        }
        return u.branch_ids.map(id => {
            const b = BRANCHES.find(x => x.id === id);
            const isDefault = id === Number(u.default_branch_id);
            return `<span class="badge-branch${isDefault ? ' fw-bold' : ''}" title="${esc(b ? b.name : '')}${isDefault ? ' (domovská)' : ''}">${esc(b ? b.code : id)}</span>`;
        }).join(' ');
    };

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
    <td>${branchCell(u)}</td>
    <td class="text-center">
        ${u.role === 'admin'
            ? '<span class="text-muted small">vždy</span>'
            : (u.can_reopen
                ? '<span class="badge bg-success">Ano</span>'
                : '<span class="badge bg-secondary">Ne</span>')}
    </td>
    <td class="text-muted small">${esc(u.last_login_local || '—')}</td>
    <td class="text-nowrap">
        <button class="btn btn-sm btn-outline-primary me-1" onclick="openEditUser(${u.id})">
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

function openEditUser(id) {
    const u = usersById[id];
    if (!u) return;
    const form = document.getElementById('editUserForm');
    form.querySelector('[name=id]').value = u.id;
    form.querySelector('[name=name]').value = u.name;
    form.querySelector('[name=email]').value = u.email;
    renderBranchPicker(form.querySelector('.branch-picker'), u.branch_ids, u.default_branch_id);
    document.getElementById('editUserAlert').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).show();
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

/* ─── Stránka: Pobočky ───────────────────────────────────────────────────── */

let branchesById = {};

function initBranchesPage() {
    loadBranches();

    document.getElementById('saveBranchBtn')?.addEventListener('click', async () => {
        const form    = document.getElementById('branchForm');
        const alertEl = document.getElementById('branchAlert');
        const data = {
            id:              parseInt(form.querySelector('[name=id]').value, 10) || 0,
            code:            form.querySelector('[name=code]').value.trim().toUpperCase(),
            name:            form.querySelector('[name=name]').value.trim(),
            dms_center_code: form.querySelector('[name=dms_center_code]').value.trim(),
            sort_order:      parseInt(form.querySelector('[name=sort_order]').value, 10) || 0,
        };
        if (!data.code || !data.name) {
            showModalAlert(alertEl, 'danger', 'Vyplňte kód a název.');
            return;
        }

        const btn = document.getElementById('saveBranchBtn');
        btn.disabled = true;
        const result = await adminPost(BRANCHES_API + '?action=save', data);
        btn.disabled = false;

        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('branchModal'))?.hide();
            showPageAlert('success', 'Pobočka byla uložena.');
            loadBranches();
        } else {
            showModalAlert(alertEl, 'danger', result.error || 'Chyba');
        }
    });
}

async function loadBranches() {
    const tbody = document.getElementById('branchesTable');
    if (!tbody) return;

    const res = await adminGet(BRANCHES_API + '?action=list');
    if (!res.success) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-danger">${esc(res.error)}</td></tr>`;
        return;
    }
    if (!res.data.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center">Žádné pobočky</td></tr>';
        return;
    }

    branchesById = Object.fromEntries(res.data.map(b => [b.id, b]));
    tbody.innerHTML = res.data.map(b => {
        const active = Number(b.is_active) === 1;
        return `
<tr class="${active ? '' : 'text-muted'}">
    <td><span class="badge-branch">${esc(b.code)}</span></td>
    <td>${esc(b.name)}</td>
    <td>${b.dms_center_code ? esc(b.dms_center_code) : '<span class="text-muted">—</span>'}</td>
    <td class="text-end">${esc(b.sort_order)}</td>
    <td class="text-end">${esc(b.user_count)}</td>
    <td class="text-end">${esc(b.open_count)}</td>
    <td>${active
        ? '<span class="badge bg-success">Aktivní</span>'
        : '<span class="badge bg-secondary">Neaktivní</span>'}</td>
    <td class="text-nowrap text-end">
        <button class="btn btn-sm btn-outline-primary me-1" onclick="openBranchModal(${Number(b.id)})">Upravit</button>
        <button class="btn btn-sm ${active ? 'btn-outline-warning' : 'btn-outline-success'}"
                onclick="toggleBranch(${Number(b.id)}, ${active})">
            ${active ? 'Deaktivovat' : 'Aktivovat'}
        </button>
    </td>
</tr>`;
    }).join('');
}

function openBranchModal(id) {
    const b    = id ? branchesById[id] : null;
    const form = document.getElementById('branchForm');
    form.querySelector('[name=id]').value              = b ? b.id : 0;
    form.querySelector('[name=code]').value            = b ? b.code : '';
    form.querySelector('[name=name]').value            = b ? b.name : '';
    form.querySelector('[name=dms_center_code]').value = b ? (b.dms_center_code || '') : '';
    form.querySelector('[name=sort_order]').value      = b ? b.sort_order : 0;
    document.getElementById('branchModalTitle').textContent = b ? 'Upravit pobočku' : 'Nová pobočka';
    document.getElementById('branchAlert').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('branchModal')).show();
}

async function toggleBranch(id, isActive) {
    const action = isActive ? 'deaktivovat' : 'aktivovat';
    if (!confirm(`Opravdu chcete pobočku ${action}?`)) return;

    const result = await adminPost(BRANCHES_API + '?action=toggle_active', { id });
    if (result.success) {
        loadBranches();
    } else {
        showPageAlert('danger', esc(result.error || 'Chyba'));
    }
}

/* ─── Stránka: Statistiky ────────────────────────────────────────────────── */

function initStatsPage() {
    loadStatsByBranch();
    loadStatsByTechnician();
    loadStatsByAge();
}

async function loadStatsByBranch() {
    const tbody = document.getElementById('branchTable');
    if (!tbody) return;

    const res = await adminGet(`${STATS_API}?view=by_branch&from=${FROM_DATE}&to=${TO_DATE}`);
    if (!res.success) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-danger">${esc(res.error)}</td></tr>`;
        return;
    }
    if (!res.data.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-muted text-center">Žádné pobočky</td></tr>';
        return;
    }

    tbody.innerHTML = res.data.map(r => `
<tr class="${Number(r.is_active) === 1 ? '' : 'text-muted'}">
    <td><span class="badge-branch">${esc(r.code)}</span> ${esc(r.name)}</td>
    <td class="text-end">${esc(r.created_count)}</td>
    <td class="text-end">${esc(r.resolved_count)}</td>
    <td class="text-end">${r.avg_minutes !== null ? esc(r.avg_minutes) : '—'}</td>
    <td class="text-end">${esc(r.moved_in)}</td>
    <td class="text-end">${esc(r.moved_out)}</td>
</tr>`).join('');
}

async function loadStatsByTechnician() {
    const tbody = document.getElementById('techTable');
    if (!tbody) return;

    const url = `${STATS_API}?view=by_technician&from=${FROM_DATE}&to=${TO_DATE}&branch=${BRANCH_ID}`;
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

    const url = `${STATS_API}?view=by_age&from=${FROM_DATE}&to=${TO_DATE}&branch=${BRANCH_ID}`;
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
