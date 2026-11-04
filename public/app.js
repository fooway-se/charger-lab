'use strict';

const chargerList = document.querySelector('#charger-list');
const chargerTemplate = document.querySelector('#charger-template');
const notice = document.querySelector('#notice');

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

function renderLog(workspace, entries) {
    const content = workspace.querySelector('[data-role="log-content"]');
    const count = workspace.querySelector('[data-role="log-count"]');
    const clearButton = workspace.querySelector('[data-action="clear-log"]');
    count.textContent = String(entries.length).padStart(2, '0');
    clearButton.hidden = entries.length === 0;
    content.replaceChildren();

    if (entries.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'empty-state';
        const icon = document.createElement('div');
        icon.className = 'empty-icon';
        icon.textContent = '⌁';
        const title = document.createElement('strong');
        title.textContent = 'Waiting for a message';
        const description = document.createElement('p');
        description.textContent = 'Connect this station to start its OCPP exchange.';
        const line = document.createElement('div');
        line.className = 'empty-line';
        empty.append(icon, title, description, line);
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

function renderOutlets(workspace, outlets, topology, isConnected) {
    const list = workspace.querySelector('[data-role="outlet-list"]');
    const card = workspace.querySelector('[data-role="charger-card"]');
    const count = workspace.querySelector('[data-role="outlet-count"]');
    const deviceType = workspace.querySelector('[data-role="device-type"]');
    const topologyHint = workspace.querySelector('[data-role="topology-hint"]');
    list.replaceChildren();
    card.dataset.outletCount = String(outlets.length);
    count.textContent = `${outlets.length} OUTLET${outlets.length === 1 ? '' : 'S'}`;
    deviceType.textContent = `AC · ${outlets.length} OUTLET${outlets.length === 1 ? '' : 'S'}`;
    topologyHint.textContent = topology === 'shared'
        ? 'One EVSE with shared charging hardware.'
        : 'Separate EVSE IDs for each outlet.';

    for (const outlet of outlets) {
        const tile = document.createElement('article');
        tile.className = 'outlet-tile';
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
        const connectorStatus = outlet.connector_status || 'Available';
        badge.className = `outlet-tag outlet-status-${connectorStatus.toLowerCase()}`;
        badge.textContent = connectorStatus.toUpperCase();
        const action = document.createElement('button');
        action.className = 'button button-quiet outlet-action';
        action.type = 'button';
        action.dataset.action = connectorStatus === 'Occupied' ? 'unplug' : 'plug-in';
        action.dataset.outletNumber = String(outlet.number);
        action.disabled = !isConnected;
        action.textContent = connectorStatus === 'Occupied' ? 'Unplug' : 'Plug in';
        tile.append(marker, details, badge, action);
        list.append(tile);
    }
}

function renderCharger(charger) {
    const workspace = chargerTemplate.content.firstElementChild.cloneNode(true);
    workspace.dataset.chargerId = String(charger.id);
    const configForm = workspace.querySelector('[data-action="save"]');
    for (const [name, value] of Object.entries(charger)) {
        const field = configForm.elements.namedItem(name);
        if (field) {
            field.value = value;
        }
    }
    const usesDefaultCsms = configForm.elements.namedItem('uses_default_csms');
    usesDefaultCsms.checked = charger.uses_default_csms;
    configForm.elements.namedItem('csms_url').disabled = usesDefaultCsms.checked;
    const passwordField = configForm.elements.namedItem('csms_password');
    passwordField.value = '';
    passwordField.placeholder = charger.has_password ? 'Leave blank to keep saved password' : 'No password saved';
    configForm.elements.namedItem('clear_csms_password').disabled = !charger.has_password;

    workspace.querySelector('[data-role="station-identity"]').textContent = charger.station_identity;
    workspace.querySelector('[data-role="vendor-name"]').textContent = charger.vendor_name;
    workspace.querySelector('[data-role="model-name"]').textContent = charger.model;
    const connectionStatus = charger.connection_status;
    workspace.querySelector('[data-role="boot-status-text"]').textContent = connectionStatus === 'Connected'
        ? `Connected · Boot ${charger.boot_status}`
        : connectionStatus;
    const status = workspace.querySelector('[data-role="boot-status"]');
    status.classList.toggle('status-online', connectionStatus === 'Connected');
    status.classList.toggle('status-error', connectionStatus === 'Connection failed');
    status.classList.toggle('status-idle', connectionStatus === 'Not connected');
    renderOutlets(workspace, charger.outlets, charger.outlet_topology, connectionStatus === 'Connected');
    const outletSelect = workspace.querySelector('[data-action="authorize"] select[name="outlet_number"]');
    const occupiedOutlets = charger.outlets.filter((outlet) => outlet.connector_status === 'Occupied');
    for (const outlet of occupiedOutlets) {
        const option = document.createElement('option');
        option.value = String(outlet.number);
        option.textContent = `Outlet ${outlet.number} · EVSE ${outlet.evse_id}`;
        outletSelect.append(option);
    }
    if (occupiedOutlets.length === 1) {
        outletSelect.value = String(occupiedOutlets[0].number);
    }
    renderLog(workspace, charger.entries);

    return workspace;
}

function renderState(state) {
    document.querySelector('#workspace-csms-form').elements.namedItem('default_csms_url').value = state.settings.default_csms_url;
    chargerList.replaceChildren(...state.chargers.map(renderCharger));
    document.querySelector('#charger-count').textContent = String(state.chargers.length).padStart(2, '0');
}

async function refreshState() {
    renderState(await api('/api/state'));
}

async function postAction(path, payload) {
    const result = await api(path, { method: 'POST', body: JSON.stringify(payload) });
    await refreshState();
    showNotice(result.notice || 'Done.');
}

chargerList.addEventListener('submit', async (event) => {
    const form = event.target;
    const workspace = form.closest('.charger-workspace');
    const chargerId = Number(workspace.dataset.chargerId);
    event.preventDefault();
    try {
        if (form.dataset.action === 'save') {
            await postAction('/api/charger', {
                ...formValues(form),
                charger_id: chargerId,
                uses_default_csms: form.elements.namedItem('uses_default_csms').checked,
            });
        } else if (form.dataset.action === 'authorize') {
            await postAction('/api/authorize', {
                rfid_token: formValues(form).rfid_token,
                outlet_number: formValues(form).outlet_number || undefined,
                charger_id: chargerId,
            });
        }
    } catch (error) {
        await refreshState().catch(() => {});
        showNotice(error.message, true);
    }
});

chargerList.addEventListener('change', (event) => {
    if (event.target.name !== 'uses_default_csms') {
        return;
    }

    event.target.form.elements.namedItem('csms_url').disabled = event.target.checked;
});

chargerList.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action], button.remove-charger');
    if (!button) {
        return;
    }

    const workspace = button.closest('.charger-workspace');
    const chargerId = Number(workspace.dataset.chargerId);
    try {
        if (button.dataset.action === 'boot') {
            const form = workspace.querySelector('[data-action="save"]');
            await postAction('/api/boot', {
                ...formValues(form),
                charger_id: chargerId,
                uses_default_csms: form.elements.namedItem('uses_default_csms').checked,
            });
        } else if (button.dataset.action === 'clear-log') {
            await postAction('/api/log/clear', { charger_id: chargerId });
        } else if (button.dataset.action === 'plug-in' || button.dataset.action === 'unplug') {
            const path = button.dataset.action === 'plug-in' ? '/api/outlet/plug-in' : '/api/outlet/unplug';
            await postAction(path, { charger_id: chargerId, outlet_number: Number(button.dataset.outletNumber) });
        } else if (button.dataset.action === 'delete') {
            await postAction('/api/charger/delete', { charger_id: chargerId });
        }
    } catch (error) {
        await refreshState().catch(() => {});
        showNotice(error.message, true);
    }
});

document.querySelector('#workspace-csms-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        await postAction('/api/settings/csms', formValues(event.currentTarget));
    } catch (error) {
        showNotice(error.message, true);
    }
});

document.querySelector('#add-charger').addEventListener('click', async () => {
    try {
        const result = await api('/api/charger/create', { method: 'POST', body: '{}' });
        await refreshState();
        showNotice(result.notice);
    } catch (error) {
        showNotice(error.message, true);
    }
});

refreshState().catch((error) => showNotice(error.message, true));
