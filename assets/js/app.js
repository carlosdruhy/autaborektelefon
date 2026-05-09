'use strict';

/* ═══════════════════════════════════════════════════════════════
   A. Inicializace
═══════════════════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', () => {
    initNewRequestForm();
    loadPreferencesFromStorage();
    loadRequests();
    startRefreshTimer();
    initSessionWatcher();
    initSoundToggle();
    initNotificationBtn();
    initCharCounter();
    initFilterSort();
    initSmsModal();
    makeDraggable(document.getElementById('newRequestModal'));
    makeDraggable(document.getElementById('requestModal'));
    makeDraggable(document.getElementById('smsModal'));
});

/* ═══════════════════════════════════════════════════════════════
   B. LocalStorage preferences
═══════════════════════════════════════════════════════════════ */

const KEYS = {
    REFRESH: 'AB_TEL_REFRESH',
    SORT:    'AB_TEL_SORT',
    FILTER:  'AB_TEL_FILTER',
    SOUND:   'AB_TEL_SOUND',
};

let currentSort   = 'asc';
let currentFilter = 'all';
let soundEnabled  = false;

function loadPreferencesFromStorage() {
    const r = localStorage.getItem(KEYS.REFRESH);
    if (r) {
        const sel = document.getElementById('refreshSelect');
        if (sel) sel.value = r;
        APP.refreshInterval = parseInt(r, 10) || APP.refreshInterval;
    }
    currentSort = localStorage.getItem(KEYS.SORT) || 'asc';
    updateSortIcon();

    currentFilter = localStorage.getItem(KEYS.FILTER) || 'all';
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.filter === currentFilter);
    });

    soundEnabled = localStorage.getItem(KEYS.SOUND) === '1';
    updateSoundIcon();
}

function savePreference(key, val) {
    localStorage.setItem(key, val);
}

/* ═══════════════════════════════════════════════════════════════
   C. Auto-refresh
═══════════════════════════════════════════════════════════════ */

let refreshCountdown = APP.refreshInterval;
let refreshTimer     = null;

function startRefreshTimer() {
    refreshCountdown = APP.refreshInterval;
    refreshTimer     = setInterval(tickRefresh, 1000);

    document.getElementById('refreshSelect')?.addEventListener('change', e => {
        const val = parseInt(e.target.value, 10);
        APP.refreshInterval = val;
        savePreference(KEYS.REFRESH, val);
        refreshCountdown = val;
    });

    document.getElementById('refreshNowBtn')?.addEventListener('click', () => {
        refreshCountdown = 0;
    });
}

function tickRefresh() {
    refreshCountdown--;
    updateCountdownUI();
    if (refreshCountdown <= 0) {
        refreshCountdown = APP.refreshInterval;
        loadRequests();
    }
}

function updateCountdownUI() {
    const label = document.getElementById('countdownLabel');
    const bar   = document.getElementById('countdownBar');
    if (label) label.textContent = refreshCountdown + ' s';
    if (bar)   bar.style.width  = (refreshCountdown / APP.refreshInterval * 100) + '%';
}

/* ═══════════════════════════════════════════════════════════════
   D. Načtení a zobrazení požadavků
═══════════════════════════════════════════════════════════════ */

let knownRequestIds = new Set();
let allRequests     = [];

async function loadRequests() {
    const search = document.getElementById('searchInput')?.value.trim() || '';
    const params = new URLSearchParams({
        action: 'list',
        sort:   currentSort,
        status: currentFilter,
    });
    if (search) params.set('search', search);

    try {
        const res = await apiGet('/requests.php?' + params.toString());
        if (!res.success) return;
        allRequests = res.data || [];
        detectNewRequests(allRequests);
        renderRequestList(allRequests);
        updateTabTitle(allRequests.length);
        updateSessionCountdown();
    } catch (e) {
        console.error('loadRequests error', e);
    }
}

function renderRequestList(requests) {
    const container = document.getElementById('requestList');
    const countEl   = document.getElementById('resultCount');
    if (!container) return;

    if (countEl) {
        countEl.textContent = requests.length
            ? `Zobrazeno: ${requests.length} požadavků`
            : '';
    }

    if (!requests.length) {
        container.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1"></i><br>Žádné požadavky</div>';
        return;
    }

    container.innerHTML = requests.map(createRequestCard).join('');

    container.querySelectorAll('.req-card').forEach(card => {
        card.addEventListener('click', () => {
            openRequestModal(parseInt(card.dataset.id, 10));
        });
    });
}

function createRequestCard(req) {
    const level    = getAgeLevel(req.age_minutes);
    const levelIcon = ['●', '◆', '▲', '✖', '⚠'][level - 1];
    const isNew    = !knownRequestIds.has(req.id) && knownRequestIds.size > 0;

    let badges = '';
    if (req.deleted_at) {
        badges += `<span class="badge-deleted ms-1"><i class="bi bi-trash3-fill"></i> Smazáno ${esc(req.deleted_at_local || '')}</span>`;
    }
    if (req.status === 'resolved') {
        badges += `<span class="badge-resolved ms-1"><i class="bi bi-check-circle-fill"></i> Vyřízeno</span>`;
    }
    if (req.status === 'reopened') {
        badges += `<span class="badge-reopened ms-1">↩ Znovuotevřeno</span>`;
    }
    if (req.status === 'pending') {
        badges += `<span class="badge-pending ms-1"><i class="bi bi-clock"></i> Čeká</span>`;
    }
    if (req.assigned_to_name && req.status === 'in_progress') {
        badges += `<span class="badge-technician ms-1"><i class="bi bi-person-fill"></i> Řeší: ${esc(req.assigned_to_name)}</span>`;
    }
    if (req.sms_count > 0) {
        badges += `<button class="badge-sms ms-1" onclick="event.stopPropagation();openSmsHistoryModal(${req.id},false)"><i class="bi bi-chat-dots"></i> ${req.sms_count} SMS</button>`;
    }

    return `
<div class="req-card level-${level}${isNew ? ' req-card-new' : ''}" data-id="${req.id}">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="level-icon">${levelIcon}</span>
            <span class="badge-spz">${esc(req.spz)}</span>
            <strong>${esc(req.client_name)}</strong>
            ${req.client_phone ? `<span class="text-muted">${esc(req.client_phone)}</span>` : ''}
            ${badges}
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge-age"><i class="bi bi-clock"></i> ${formatAge(req.age_minutes)}</span>
            <small class="text-muted">${esc(req.created_at_local || '')}</small>
        </div>
    </div>
    <div class="mt-1 text-truncate text-muted small" style="max-width:100%">${esc(req.request_text)}</div>
</div>`;
}

function getAgeLevel(ageMinutes) {
    const [t1, t2, t3, t4] = APP.colorThresholds;
    if (ageMinutes < t1)  return 1;
    if (ageMinutes < t2)  return 2;
    if (ageMinutes < t3)  return 3;
    if (ageMinutes < t4)  return 4;
    return 5;
}

function formatAge(minutes) {
    if (minutes < 60) return minutes + ' min';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m > 0 ? `${h} hod ${m} min` : `${h} hod`;
}

/* ═══════════════════════════════════════════════════════════════
   E. Notifikace nových ticketů
═══════════════════════════════════════════════════════════════ */

function detectNewRequests(requests) {
    if (knownRequestIds.size === 0) {
        knownRequestIds = new Set(requests.map(r => r.id));
        return;
    }

    const newOnes = requests.filter(r => !knownRequestIds.has(r.id));
    if (newOnes.length > 0) {
        if (soundEnabled) playNotificationSound();
        if (document.hidden) {
            showBrowserNotification(newOnes);
        }
    }

    knownRequestIds = new Set(requests.map(r => r.id));
}

function updateTabTitle(count) {
    document.title = count > 0
        ? `(${count}) ${APP.appName}`
        : APP.appName;
}

function initNotificationBtn() {
    const btn = document.getElementById('notifBtn');
    if (!btn) return;
    if (!('Notification' in window)) {
        btn.remove();
        return;
    }
    updateNotifIcon();
    btn.addEventListener('click', async () => {
        if (Notification.permission !== 'default') return;
        const result = await Notification.requestPermission();
        updateNotifIcon();
        if (result === 'granted') {
            new Notification(APP.appName, { body: 'Upozornění na nové požadavky jsou zapnuta.', icon: '/favicon.svg' });
        }
    });
}

function updateNotifIcon() {
    const btn  = document.getElementById('notifBtn');
    const icon = document.getElementById('notifIcon');
    if (!btn || !icon) return;
    const perm = Notification.permission;
    if (perm === 'granted') {
        icon.className = 'bi bi-bell-fill';
        btn.style.color      = 'var(--color-accent)';
        btn.style.borderColor = 'var(--color-accent)';
        btn.title    = 'Upozornění na nové požadavky: zapnuta';
        btn.disabled = false;
    } else if (perm === 'denied') {
        icon.className = 'bi bi-bell-slash';
        btn.title    = 'Upozornění zakázána v nastavení prohlížeče';
        btn.disabled = true;
        btn.style.opacity = '0.5';
    } else {
        icon.className = 'bi bi-bell';
        btn.title    = 'Klikněte pro zapnutí upozornění na nové požadavky';
        btn.disabled = false;
    }
}

function showBrowserNotification(newOnes) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    const count = newOnes.length;
    let body;
    if (count === 1) {
        body = `SPZ: ${newOnes[0].spz} – ${newOnes[0].client_name}`;
    } else {
        const spzList = newOnes.slice(0, 3).map(r => r.spz).join(', ');
        body = `${count} nové požadavky: ${spzList}${count > 3 ? ', …' : ''}`;
    }
    const n = new Notification(APP.appName, {
        body,
        icon:      '/favicon.svg',
        tag:       'new-request',
        renotify:  true,
    });
    n.onclick = () => { window.focus(); n.close(); };
}

function playNotificationSound() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.value = 880;
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.4);
    } catch (_) { /* tiché selhání */ }
}

function initSoundToggle() {
    const btn = document.getElementById('soundBtn');
    if (!btn) return;
    btn.addEventListener('click', () => {
        soundEnabled = !soundEnabled;
        savePreference(KEYS.SOUND, soundEnabled ? '1' : '0');
        updateSoundIcon();
    });
}

function updateSoundIcon() {
    const icon = document.getElementById('soundIcon');
    if (!icon) return;
    icon.className = soundEnabled ? 'bi bi-volume-up-fill' : 'bi bi-volume-mute-fill';
}

/* ═══════════════════════════════════════════════════════════════
   F. Modální okno
═══════════════════════════════════════════════════════════════ */

let currentRequestId  = null;
let expectedUpdatedAt = null;
let bsModal           = null;

async function openRequestModal(requestId) {
    currentRequestId = requestId;
    const modalEl = document.getElementById('requestModal');
    bsModal = bsModal || new bootstrap.Modal(modalEl);

    document.getElementById('modalBody').innerHTML =
        '<div class="text-center py-4"><div class="spinner-border"></div></div>';
    document.getElementById('modalFooter').innerHTML = '';
    bsModal.show();

    try {
        const res = await apiGet(`/requests.php?action=get&id=${requestId}`);
        if (!res.success) {
            document.getElementById('modalBody').innerHTML =
                `<div class="alert alert-danger">${esc(res.error || 'Chyba načítání')}</div>`;
            return;
        }
        const req = res.data;
        expectedUpdatedAt = req.updated_at;
        renderModal(req);
    } catch (e) {
        document.getElementById('modalBody').innerHTML =
            '<div class="alert alert-danger">Chyba připojení</div>';
    }
}

function renderModal(req) {
    const isAdmin    = APP.currentUser.isAdmin;
    const isAssigned = req.assigned_to_id === APP.currentUser.id;
    const statusLabels = {
        new: 'Nový', in_progress: 'Převzatý', pending: 'Čeká',
        resolved: 'Vyřízen', reopened: 'Znovuotevřen',
    };

    // Připravíme draft
    const draft = loadDraft(req.id);

    document.getElementById('modalTitle').textContent =
        `Požadavek #${req.id} — ${req.spz}`;

    document.getElementById('modalBody').innerHTML = `
<div class="row g-3 mb-3">
    <div class="col-sm-6">
        <table class="table table-sm table-borderless mb-0">
            <tr><th class="text-muted fw-normal ps-0" style="width:35%">SPZ</th>
                <td><strong class="badge-spz">${esc(req.spz)}</strong></td></tr>
            <tr><th class="text-muted fw-normal ps-0">Jméno</th>
                <td>${esc(req.client_name)}</td></tr>
            <tr><th class="text-muted fw-normal ps-0">Telefon</th>
                <td>${req.client_phone ? `<a href="tel:${esc(req.client_phone)}">${esc(req.client_phone)}</a>` : '—'}</td></tr>
            <tr><th class="text-muted fw-normal ps-0">E-mail</th>
                <td>${req.client_email ? `<a href="mailto:${esc(req.client_email)}">${esc(req.client_email)}</a>` : '—'}</td></tr>
        </table>
    </div>
    <div class="col-sm-6">
        <table class="table table-sm table-borderless mb-0">
            <tr><th class="text-muted fw-normal ps-0" style="width:45%">Stav</th>
                <td>${statusLabels[req.status] || req.status}</td></tr>
            <tr><th class="text-muted fw-normal ps-0">Stáří</th>
                <td>${formatAge(req.age_minutes)}</td></tr>
            <tr><th class="text-muted fw-normal ps-0">Přijato</th>
                <td>${esc(req.created_at_local || '')}</td></tr>
            <tr><th class="text-muted fw-normal ps-0">Řeší</th>
                <td>${req.assigned_to_name
                    ? `<span class="badge-technician">${esc(req.assigned_to_name)}</span>`
                    : '<span class="text-muted">Nepřevzato</span>'}</td></tr>
        </table>
    </div>
</div>

<div class="mb-3">
    <label class="form-label fw-semibold">Požadavek</label>
    <div class="border rounded p-2 bg-light" style="white-space:pre-wrap">${esc(req.request_text)}</div>
</div>

${req.pending_reason ? `
<div class="mb-3">
    <label class="form-label fw-semibold text-primary">Důvod čekání</label>
    <div class="border rounded p-2">${esc(req.pending_reason)}</div>
</div>` : ''}

${req.reopen_reason ? `
<div class="mb-3">
    <label class="form-label fw-semibold text-warning">Důvod znovuotevření</label>
    <div class="border rounded p-2">${esc(req.reopen_reason)}</div>
</div>` : ''}

${req.technician_note ? `
<div class="mb-3">
    <label class="form-label fw-semibold">Poznámka technika</label>
    <div class="border rounded p-2" style="white-space:pre-wrap">${esc(req.technician_note)}</div>
</div>` : ''}

<!-- Akční formuláře (přepínané) -->
<div id="actionArea"></div>

<!-- Konflikt warning -->
<div id="conflictWarning" class="alert alert-warning d-none"></div>

<!-- Historie -->
${req.history && req.history.length ? `
<hr>
<div class="accordion accordion-flush" id="historyAccordion">
    <div class="accordion-item border-0">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed ps-0 text-muted small" type="button"
                    data-bs-toggle="collapse" data-bs-target="#historyPane">
                Historie změn (${req.history.length})
            </button>
        </h2>
        <div id="historyPane" class="accordion-collapse collapse">
            <div class="accordion-body ps-0">
                ${req.history.map(h => `
                    <div class="history-entry mb-2">
                        <div><strong>${esc(h.user_name)}</strong>
                            <span class="text-muted">${esc(h.created_at_local || h.created_at)}</span></div>
                        <div class="text-muted">${esc(h.action)}${h.field_name ? ' › ' + esc(h.field_name) : ''}</div>
                        ${h.old_value ? `<div><del class="text-danger small">${esc(h.old_value)}</del></div>` : ''}
                        ${h.new_value ? `<div><ins class="text-success small" style="text-decoration:none">${esc(h.new_value)}</ins></div>` : ''}
                    </div>`).join('')}
            </div>
        </div>
    </div>
</div>` : ''}
`;

    renderModalActions(req, draft);
}

function renderModalActions(req, draft) {
    const area   = document.getElementById('actionArea');
    const footer = document.getElementById('modalFooter');
    const isAdmin    = APP.currentUser.isAdmin;
    const isAssigned = req.assigned_to_id === APP.currentUser.id;
    const isMine     = isAssigned;

    area.innerHTML   = '';
    footer.innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zavřít</button>';

    const addBtn = (label, cls, onclick) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `btn btn-sm ${cls}`;
        btn.textContent = label;
        btn.onclick = onclick;
        footer.appendChild(btn);
    };

    // Smazaný požadavek — jen obnovit, žádné jiné akce
    if (req.deleted_at) {
        if (isAdmin) {
            addBtn('Obnovit požadavek', 'btn-outline-success ms-auto', () => {
                if (!confirm('Obnovit tento požadavek?')) return;
                submitAction('restore', {});
            });
        }
        return;
    }

    switch (req.status) {
        case 'new':
        case 'reopened':
            addBtn('Převzít', 'btn-success', () => submitAction('assign', {}));
            break;

        case 'in_progress':
            if (isMine || isAdmin) {
                // Uzavřít
                area.innerHTML = `
<div class="mb-3">
    <label class="form-label fw-semibold">Poznámka k řešení <span class="text-danger">*</span></label>
    <textarea id="noteField" class="form-control" rows="3" placeholder="Co bylo sděleno / provedeno…">${esc(draft)}</textarea>
</div>`;
                document.getElementById('noteField')?.addEventListener('input', e => {
                    saveDraft(req.id, e.target.value);
                });
                addBtn('Uzavřít (Vyřízeno)', 'btn-success', () => {
                    const note = document.getElementById('noteField').value.trim();
                    if (!note) { alert('Zadejte poznámku k řešení.'); return; }
                    submitAction('resolve', { technician_note: note });
                });

                // Označit jako čekající
                area.innerHTML += `
<div class="mb-3">
    <label class="form-label fw-semibold">Důvod čekání</label>
    <input type="text" id="pendingReasonField" class="form-control" placeholder="Čeká na díl, klienta…">
</div>`;
                addBtn('Označit jako čekající', 'btn-warning', () => {
                    const reason = document.getElementById('pendingReasonField').value.trim();
                    if (!reason) { alert('Zadejte důvod čekání.'); return; }
                    submitAction('set_pending', { pending_reason: reason });
                });
            }

            // Přebrat (jiný technik nebo admin)
            if (!isMine || isAdmin) {
                area.innerHTML += `
<div class="mb-3">
    <label class="form-label fw-semibold">Důvod přebrání (volitelný)</label>
    <input type="text" id="takeoverReasonField" class="form-control">
</div>`;
                addBtn('Přebrat', 'btn-outline-primary', () => {
                    const reason = document.getElementById('takeoverReasonField')?.value.trim() || '';
                    if (!confirm('Opravdu chcete převzít tento ticket od ' + esc(req.assigned_to_name || 'technika') + '?')) return;
                    submitAction('takeover', { takeover_reason: reason });
                });
            }
            break;

        case 'pending':
            addBtn('Pokračovat v řešení', 'btn-primary', () => submitAction('resume', {}));
            break;

        case 'resolved':
            if (APP.currentUser.canReopen) {
                area.innerHTML = `
<div class="mb-3">
    <label class="form-label fw-semibold">Důvod znovuotevření <span class="text-danger">*</span></label>
    <input type="text" id="reopenReasonField" class="form-control" placeholder="Klient zavolal znovu…">
</div>`;
                addBtn('Znovuotevřít', 'btn-warning', () => {
                    const reason = document.getElementById('reopenReasonField').value.trim();
                    if (!reason) { alert('Zadejte důvod znovuotevření.'); return; }
                    submitAction('reopen', { reopen_reason: reason });
                });
            } else {
                area.innerHTML = '<p class="text-muted small">Nemáte oprávnění znovuotevřít uzavřený požadavek.</p>';
            }
            break;
    }

    // SMS (kdokoliv, pokud je telefon a SMS je povoleno)
    if (APP.smsEnabled && req.client_phone) {
        addBtn('Odeslat SMS', 'btn-outline-info', () => openSmsModal(req));
    }

    // Soft-delete (jen admin)
    if (isAdmin && req.status !== 'new') {
        addBtn('Smazat (skrýt)', 'btn-outline-danger ms-auto', () => {
            if (!confirm('Opravdu chcete ticket skrýt? Akce je nevratná přes UI.')) return;
            submitAction('soft_delete', {});
        });
    }
}

/* ═══════════════════════════════════════════════════════════════
   G. Akce z modálu
═══════════════════════════════════════════════════════════════ */

async function submitAction(actionType, payload) {
    const body = {
        id:                 currentRequestId,
        expected_updated_at: expectedUpdatedAt,
        action_type:        actionType,
        ...payload,
    };

    try {
        const result = await apiPost('/requests.php?action=update', body);

        if (result.status === 409) {
            const warn = document.getElementById('conflictWarning');
            if (warn) {
                warn.textContent = result.error || 'Konflikt – někdo jiný mezitím upravil tento požadavek.';
                warn.classList.remove('d-none');
            }
            return;
        }

        if (!result.success) {
            alert(result.error || 'Chyba při ukládání.');
            return;
        }

        clearDraft(currentRequestId);
        bsModal?.hide();
        await loadRequests();
    } catch (e) {
        alert('Chyba připojení k serveru.');
    }
}

/* ═══════════════════════════════════════════════════════════════
   H. Formulář nového požadavku
═══════════════════════════════════════════════════════════════ */

function initNewRequestForm() {
    const form    = document.getElementById('newRequestForm');
    const alertEl = document.getElementById('newRequestAlert');
    const modalEl = document.getElementById('newRequestModal');
    if (!form || !modalEl) return;

    // Vyčistit formulář při otevření modálu
    modalEl.addEventListener('show.bs.modal', () => {
        form.reset();
        document.getElementById('charCount').textContent = '0';
        alertEl.classList.add('d-none');
    });

    // Fokus na první pole po otevření
    modalEl.addEventListener('shown.bs.modal', () => {
        form.querySelector('[name="spz"]')?.focus();
    });

    form.addEventListener('submit', async e => {
        e.preventDefault();
        const submitBtn = document.getElementById('submitNewRequest');
        submitBtn.disabled = true;

        const data = Object.fromEntries(new FormData(form));
        data.spz = (data.spz || '').toUpperCase().replace(/[\s\-]/g, '');

        try {
            const result = await apiPost('/requests.php?action=create', data);
            if (result.success) {
                bootstrap.Modal.getInstance(modalEl)?.hide();
                await loadRequests();
            } else {
                showAlert(alertEl, 'danger', result.error || 'Chyba při odesílání.');
            }
        } catch (err) {
            showAlert(alertEl, 'danger', 'Chyba připojení k serveru.');
        } finally {
            submitBtn.disabled = false;
        }
    });
}

function initCharCounter() {
    const ta = document.querySelector('[name="request_text"]');
    const counter = document.getElementById('charCount');
    if (!ta || !counter) return;
    ta.addEventListener('input', () => {
        counter.textContent = ta.value.length;
    });
}

function showAlert(el, type, msg) {
    if (!el) return;
    el.className = `alert alert-${type}`;
    el.textContent = msg;
    el.classList.remove('d-none');
    setTimeout(() => el.classList.add('d-none'), 5000);
}

/* ═══════════════════════════════════════════════════════════════
   I. Session keepalive a warning
═══════════════════════════════════════════════════════════════ */

let sessionWarnShown = false;

function initSessionWatcher() {
    // Ukládáme čas přihlášení do sessionStorage jako referenci
    if (!sessionStorage.getItem('AB_SESSION_START')) {
        sessionStorage.setItem('AB_SESSION_START', Date.now().toString());
    }

    setInterval(checkSessionExpiry, 60000);
}

function checkSessionExpiry() {
    const start     = parseInt(sessionStorage.getItem('AB_SESSION_START') || '0', 10);
    const elapsed   = (Date.now() - start) / 1000 / 60;
    const remaining = APP.sessionTimeout - elapsed;

    if (remaining <= 5 && !sessionWarnShown) {
        sessionWarnShown = true;
        document.getElementById('sessionWarning')?.classList.remove('d-none');
    }
}

function updateSessionCountdown() {
    const el = document.getElementById('sessionCountdown');
    if (!el) return;
    const start     = parseInt(sessionStorage.getItem('AB_SESSION_START') || '0', 10);
    const elapsed   = (Date.now() - start) / 1000 / 60;
    const remaining = Math.max(0, Math.round(APP.sessionTimeout - elapsed));
    el.textContent  = `(${remaining} min)`;
}

function extendSession() {
    apiGet('/settings.php').then(() => {
        sessionStorage.setItem('AB_SESSION_START', Date.now().toString());
        sessionWarnShown = false;
        document.getElementById('sessionWarning')?.classList.add('d-none');
        updateSessionCountdown();
    });
}

/* ═══════════════════════════════════════════════════════════════
   J. Draft autosave
═══════════════════════════════════════════════════════════════ */

function saveDraft(requestId, text) {
    sessionStorage.setItem(`AB_DRAFT_${requestId}`, text);
}

function loadDraft(requestId) {
    return sessionStorage.getItem(`AB_DRAFT_${requestId}`) || '';
}

function clearDraft(requestId) {
    if (requestId) sessionStorage.removeItem(`AB_DRAFT_${requestId}`);
}

/* ═══════════════════════════════════════════════════════════════
   K. Pomocné API funkce
═══════════════════════════════════════════════════════════════ */

async function apiGet(path) {
    const res  = await fetch(APP.apiBase + path, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
    });
    const text = await res.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        console.error('API non-JSON response (' + res.status + '):', text.substring(0, 500));
        return { success: false, error: 'Chyba serveru' };
    }
}

async function apiPost(path, body) {
    const res = await fetch(APP.apiBase + path, {
        method:      'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': APP.csrfToken,
            'Accept':       'application/json',
        },
        body: JSON.stringify(body),
    });
    return { status: res.status, ...(await res.json()) };
}

/* ═══════════════════════════════════════════════════════════════
   L. UI pomocníci
═══════════════════════════════════════════════════════════════ */

function initFilterSort() {
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentFilter = btn.dataset.filter;
            savePreference(KEYS.FILTER, currentFilter);
            loadRequests();
        });
    });

    document.getElementById('sortBtn')?.addEventListener('click', () => {
        currentSort = currentSort === 'asc' ? 'desc' : 'asc';
        savePreference(KEYS.SORT, currentSort);
        updateSortIcon();
        loadRequests();
    });

    let searchTimer = null;
    document.getElementById('searchInput')?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadRequests, 300);
    });
}

function updateSortIcon() {
    const icon = document.getElementById('sortIcon');
    if (!icon) return;
    icon.className = currentSort === 'asc' ? 'bi bi-sort-up' : 'bi bi-sort-down';
}

function esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/* ═══════════════════════════════════════════════════════════════
   M. Přetahovatelné modální okno
═══════════════════════════════════════════════════════════════ */

function makeDraggable(modalEl) {
    if (!modalEl) return;

    const dialog = modalEl.querySelector('.modal-dialog');
    const header = modalEl.querySelector('.modal-header');
    if (!dialog || !header) return;

    header.style.cursor = 'grab';

    let isDragging = false;
    let startMouseX, startMouseY, startLeft, startTop;

    header.addEventListener('mousedown', e => {
        if (e.target.closest('button')) return; // nezachytávat kliknutí na tlačítka

        const rect = dialog.getBoundingClientRect();

        // Přepneme na absolutní pozici od aktuálního místa
        dialog.style.margin   = '0';
        dialog.style.position = 'absolute';
        dialog.style.left     = rect.left + 'px';
        dialog.style.top      = rect.top  + 'px';

        startMouseX = e.clientX;
        startMouseY = e.clientY;
        startLeft   = rect.left;
        startTop    = rect.top;

        isDragging = true;
        header.style.cursor = 'grabbing';
        e.preventDefault();
    });

    document.addEventListener('mousemove', e => {
        if (!isDragging) return;

        const newLeft = startLeft + (e.clientX - startMouseX);
        const newTop  = startTop  + (e.clientY - startMouseY);

        // Zabráníme vytažení zcela mimo obrazovku
        const maxLeft = window.innerWidth  - dialog.offsetWidth  - 8;
        const maxTop  = window.innerHeight - dialog.offsetHeight - 8;

        dialog.style.left = Math.max(8, Math.min(newLeft, maxLeft)) + 'px';
        dialog.style.top  = Math.max(8, Math.min(newTop,  maxTop))  + 'px';
    });

    document.addEventListener('mouseup', () => {
        if (!isDragging) return;
        isDragging = false;
        header.style.cursor = 'grab';
    });

    // Po zavření resetujeme pozici, aby se příště otevřel uprostřed
    modalEl.addEventListener('hidden.bs.modal', () => {
        dialog.style.position = '';
        dialog.style.margin   = '';
        dialog.style.left     = '';
        dialog.style.top      = '';
        header.style.cursor   = 'grab';
    });
}

/* ═══════════════════════════════════════════════════════════════
   N. SMS modál
═══════════════════════════════════════════════════════════════ */

let _smsReq = null;

async function openSmsHistoryModal(requestId, adminAll) {
    const titleEl = document.getElementById('smsHistoryTitle');
    const bodyEl  = document.getElementById('smsHistoryBody');
    titleEl.innerHTML = '<i class="bi bi-chat-dots"></i> Odeslané SMS';
    bodyEl.innerHTML  = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('smsHistoryModal')).show();

    const url = adminAll
        ? '/sms.php?action=list'
        : `/sms.php?action=list&request_id=${requestId}`;
    const res = await apiGet(url);

    if (!res.success) {
        bodyEl.innerHTML = `<div class="alert alert-danger m-3">${esc(res.error || 'Chyba načítání')}</div>`;
        return;
    }

    const rows = res.data;
    if (!rows.length) {
        bodyEl.innerHTML = '<p class="text-muted text-center py-4">Žádné SMS.</p>';
        return;
    }

    const statusBadge = s => {
        if (s === 'sent')    return '<span class="badge bg-success">Odesláno</span>';
        if (s === 'failed')  return '<span class="badge bg-danger">Chyba</span>';
        return '<span class="badge bg-secondary">Čeká</span>';
    };

    const adminCols = adminAll
        ? '<th>Požadavek</th><th>Klient</th>'
        : '';

    const adminCells = r => adminAll
        ? `<td>${r.request_id ? `#${r.request_id} ${esc(r.spz || '')}` : '—'}</td><td>${esc(r.client_name || '—')}</td>`
        : '';

    bodyEl.innerHTML = `
<table class="table table-sm table-hover mb-0">
  <thead class="table-light">
    <tr>
      ${adminCols}
      <th>Telefon</th>
      <th>Zpráva</th>
      <th>Stav</th>
      <th>Odesílatel</th>
      <th>Vytvořeno</th>
      <th>Odesláno</th>
    </tr>
  </thead>
  <tbody>
    ${rows.map(r => `<tr>
      ${adminCells(r)}
      <td>${esc(r.phone)}</td>
      <td class="text-truncate" style="max-width:200px" title="${esc(r.message)}">${esc(r.message)}</td>
      <td>${statusBadge(r.status)}${r.error_msg ? `<br><small class="text-danger">${esc(r.error_msg)}</small>` : ''}</td>
      <td>${esc(r.sent_by_name)}</td>
      <td class="text-nowrap">${esc(r.created_at_local)}</td>
      <td class="text-nowrap">${r.sent_at_local ? esc(r.sent_at_local) : '—'}</td>
    </tr>`).join('')}
  </tbody>
</table>`;
}

function openSmsModal(req) {
    _smsReq = req;
    document.getElementById('smsPhone').value    = req.client_phone || '';
    document.getElementById('smsText').value = '';
    updateSmsCounter('');
    const al = document.getElementById('smsAlert');
    al.className   = 'd-none mb-3';
    al.textContent = '';
    const btn = document.getElementById('smsSendBtn');
    btn.disabled  = false;
    btn.innerHTML = '<i class="bi bi-send"></i> Odeslat SMS';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('smsModal')).show();
}

// Znaky základní GSM-7 sady (každý = 1 jednotka)
const GSM7 = new Set(
    '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1bÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?' +
    '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ`¿abcdefghijklmnopqrstuvwxyzäöñüà'
);
// Rozšiřující znaky GSM-7 (každý = 2 jednotky)
const GSM7_EXT = new Set('^{}\\[~]|€');

function smsSegments(text) {
    let ucs2 = false;
    let gsm7len = 0;

    for (const ch of text) {
        if (GSM7_EXT.has(ch))       { gsm7len += 2; }
        else if (GSM7.has(ch))      { gsm7len += 1; }
        else                        { ucs2 = true; break; }
    }

    if (ucs2) {
        const len  = [...text].length;
        const segs = len <= 70 ? 1 : Math.ceil(len / 67);
        return { len, segs, limit: segs === 1 ? 70 : segs * 67, ucs2: true };
    }
    const segs = gsm7len <= 160 ? 1 : Math.ceil(gsm7len / 153);
    return { len: gsm7len, segs, limit: segs === 1 ? 160 : segs * 153, ucs2: false };
}

function updateSmsCounter(text) {
    const info    = smsSegments(text);
    const countEl = document.getElementById('smsCharCount');
    const infoEl  = document.getElementById('smsSegmentInfo');
    if (!countEl || !infoEl) return;

    countEl.textContent = info.len;
    countEl.className   = (info.limit - info.len) < 15 ? 'text-danger fw-bold' : '';

    const segLabel = info.segs === 1
        ? '1 SMS'
        : `${info.segs} SMS (${info.segs} části)`;
    infoEl.textContent = info.ucs2
        ? `${segLabel} — UCS-2 (diakritika)`
        : segLabel;
    infoEl.className = info.segs > 2 ? 'text-warning' : 'text-muted';
}

function initSmsModal() {
    const txt = document.getElementById('smsText');
    if (txt) {
        txt.addEventListener('input', () => updateSmsCounter(txt.value));
    }

    document.getElementById('smsModal')?.addEventListener('hidden.bs.modal', () => {
        bootstrap.Modal.getInstance(document.getElementById('requestModal'))?.hide();
    });

    document.getElementById('smsSendBtn')?.addEventListener('click', async () => {
        if (!_smsReq) return;
        const message = document.getElementById('smsText').value.trim();
        if (!message) { alert('Zadejte text SMS.'); return; }

        const btn = document.getElementById('smsSendBtn');
        btn.disabled  = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Odesílám…';

        const res = await apiPost('/sms.php', {
            csrf:       APP.csrfToken,
            request_id: _smsReq.id,
            phone:      _smsReq.client_phone,
            message,
        });

        const al = document.getElementById('smsAlert');
        if (res.success) {
            al.className   = 'alert alert-success mb-3';
            al.textContent = 'SMS zařazena do fronty k odeslání.';
            btn.innerHTML  = '<i class="bi bi-check-lg"></i> Zařazeno';
        } else {
            al.className   = 'alert alert-danger mb-3';
            al.textContent = res.error || 'Chyba při odesílání.';
            btn.disabled   = false;
            btn.innerHTML  = '<i class="bi bi-send"></i> Odeslat SMS';
        }
    });
}

// ─── Service Worker registrace ───────────────────────────────────────────────
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
}
