'use strict';

const chargerForm = document.querySelector('#charger-form');
const tokenForm = document.querySelector('#token-form');
const notice = document.querySelector('#notice');
let currentCharger = null;

function formValues(form) {
    return Object.fromEntries(new FormData(form).entries());
}

async function api(path, options = {}) {
    const response = await fetch(path, {
        ...options,
        headers: {
            ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
            ...(options.headers || {}),
        },
    });
    const result = await response.json();
    if (!response.ok) {
        throw new Error(result.error || `Request failed (${response.status}).`);
    }

    return result;
}

function showNotice(message, isError = false) {
    notice.hidden = !message;
    notice.classList.toggle('notice-error', isError);
    notice.replaceChildren();
    if (!message) {
        return;
    }

    const icon = document.createElement('span');
    icon.textContent = isError ? '!' : '✓';
    const text = document.createElement('span');
    text.textContent = message;
    notice.append(icon, text);
}

function renderLog(entries) {
    const content = document.querySelector('#log-content');
    const count = document.querySelector('#log-count');
    const clearButton = document.querySelector('#clear-log');
    count.textContent = String(entries.length).padStart(2, '0');
    clearButton.hidden = entries.length === 0;
    content.replaceChildren();

    if (entries.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'empty-state';
        empty.innerHTML = '<div class="empty-icon">⌁</div><strong>Waiting for a message</strong><p>Connect the station to start your first OCPP exchange.</p><div class="empty-line"></div>';
        content.append(empty);
        return;
    }

    const list = document.createElement('div');
    list.className = 'log-list';
    for (const entry of entries) {
        const outgoing = entry.direction === 'outgoing';
        const system = entry.direction === 'system';
        const row = document.createElement('article');
        row.className = `log-entry${system ? ' log-system' : ''}`;

        const head = document.createElement('div');
        head.className = 'log-entry-head';
        const direction = document.createElement('span');
        direction.className = `direction-tag ${system ? 'tag-system' : (outgoing ? 'tag-out' : 'tag-in')}`;
        const arrow = document.createElement('span');
        arrow.textContent = system ? '!' : (outgoing ? '↑' : '↓');
        direction.append(arrow, document.createTextNode(system ? 'SYSTEM' : (outgoing ? 'STATION → CSMS' : 'CSMS → STATION')));
        const time = document.createElement('span');
        time.className = 'log-time';
        time.textContent = new Date(entry.created_at).toLocaleTimeString([], { hour12: false });
        head.append(direction, time);

        const title = document.createElement('div');
        title.className = 'message-title';
        const caret = document.createElement('span');
        caret.className = 'message-caret';
        caret.textContent = '⌄';
        const action = document.createElement('span');
        action.textContent = entry.action;
        const kind = document.createElement('span');
        kind.className = 'message-kind';
        kind.textContent = system ? 'EVENT' : (outgoing ? 'CALL' : 'RESPONSE');
        title.append(caret, action, kind);

        const pre = document.createElement('pre');
        try {
            pre.textContent = JSON.stringify(JSON.parse(entry.message), null, 2);
        } catch {
            pre.textContent = entry.message;
        }
        row.append(head, title, pre);
        list.append(row);
    }
    content.append(list);
}

function renderOutlets(outlets, topology) {
    const list = document.querySelector('#outlet-list');
    const count = document.querySelector('#outlet-map-count');
    const chargerCard = document.querySelector('#charger-card');
    const deviceType = document.querySelector('#device-type');
    const topologyHint = document.querySelector('#topology-hint');
    list.replaceChildren();
    chargerCard.dataset.outletCount = String(outlets.length);
    count.textContent = `${outlets.length} OUTLET${outlets.length === 1 ? '' : 'S'}`;
    deviceType.textContent = `AC · ${outlets.length} OUTLET${outlets.length === 1 ? '' : 'S'}`;
    topologyHint.textContent = topology === 'shared'
        ? 'One EVSE with shared charging hardware.'
        : 'Separate EVSE IDs for each outlet.';

    for (const outlet of outlets) {
        const card = document.createElement('article');
        card.className = 'outlet-tile';
        const marker = document.createElement('span');
        marker.className = 'outlet-marker';
        marker.textContent = String(outlet.number).padStart(2, '0');
        const details = document.createElement('span');
        details.className = 'outlet-details';
        const title = document.createElement('strong');
        title.textContent = `Outlet ${outlet.number}`;
        const ids = document.createElement('small');
        ids.textContent = `EVSE ${outlet.evse_id} · Connector ${outlet.connector_id}`;
        details.append(title, ids);
        const badge = document.createElement('span');
        badge.className = 'outlet-tag';
        badge.textContent = 'MAPPED';
        card.append(marker, details, badge);
        list.append(card);
    }
}

function renderState(state) {
    const charger = state.charger;
    currentCharger = charger;
    for (const [name, value] of Object.entries(charger)) {
        const field = chargerForm.elements.namedItem(name);
        if (field) {
            field.value = value;
        }
    }
    document.querySelector('#station-identity').textContent = charger.station_identity;
    document.querySelector('#vendor-name').textContent = charger.vendor_name;
    document.querySelector('#model-name').textContent = charger.model;
    document.querySelector('#boot-status-text').textContent = charger.boot_status;
    renderOutlets(state.outlets, charger.outlet_topology);

    const status = document.querySelector('#boot-status');
    status.classList.toggle('status-online', charger.boot_status === 'Accepted');
    status.classList.toggle('status-error', charger.boot_status === 'Connection failed');
    status.classList.toggle('status-idle', charger.boot_status !== 'Accepted' && charger.boot_status !== 'Connection failed');
    renderLog(state.entries);
}

async function refreshState() {
    renderState(await api('/api/state'));
}

async function postAction(path, payload) {
    const result = await api(path, { method: 'POST', body: JSON.stringify(payload) });
    await refreshState();
    showNotice(result.notice || 'Done.');
}

chargerForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        await postAction('/api/charger', formValues(chargerForm));
    } catch (error) {
        showNotice(error.message, true);
    }
});

document.querySelector('#boot-button').addEventListener('click', async () => {
    try {
        await postAction('/api/boot', formValues(chargerForm));
    } catch (error) {
        await refreshState().catch(() => {});
        showNotice(error.message, true);
    }
});

tokenForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        await postAction('/api/authorize', { ...currentCharger, rfid_token: formValues(tokenForm).rfid_token });
        tokenForm.reset();
    } catch (error) {
        await refreshState().catch(() => {});
        showNotice(error.message, true);
    }
});

document.querySelector('#clear-log').addEventListener('click', async () => {
    try {
        await postAction('/api/log/clear', {});
    } catch (error) {
        showNotice(error.message, true);
    }
});

refreshState().catch((error) => showNotice(error.message, true));
