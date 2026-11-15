'use strict';

const chargerList = document.querySelector('#charger-list');
const chargerTemplate = document.querySelector('#charger-template');
const notice = document.querySelector('#notice');
const screenNavigation = document.querySelector('#screen-navigation');
const setupScreen = document.querySelector('#setup-screen');
const testingScreen = document.querySelector('#testing-screen');
const networkTestForm = document.querySelector('#network-test-form');
const networkTestResult = document.querySelector('#network-test-result');

function formValues(form) {
    return Object.fromEntries(new FormData(form).entries());
}

function setTextContent(element, value) {
    if (element.textContent !== value) {
        element.textContent = value;
    }
}

function reconcileChildren(parent, children) {
    for (const [index, child] of children.entries()) {
        const current = parent.children[index];
        if (current !== child) {
            parent.insertBefore(child, current || null);
        }
    }

    while (parent.children.length > children.length) {
        parent.lastElementChild.remove();
    }
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

function createEmptyLogState() {
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

    return empty;
}

function updateLogEntry(row, entry) {
    const outgoing = entry.direction === 'outgoing';
    const system = entry.direction === 'system';
    const version = JSON.stringify([entry.direction, entry.action, entry.message, entry.created_at]);
    if (row.dataset.logEntryVersion === version) {
        return;
    }

    row.className = `log-entry${system ? ' log-system' : ''}`;
    row.dataset.logEntryId = String(entry.id);
    row.dataset.logEntryVersion = version;
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

    row.replaceChildren(head, title, pre);
}

function renderLog(workspace, entries) {
    const content = workspace.querySelector('[data-role="log-content"]');
    const count = workspace.querySelector('[data-role="log-count"]');
    const clearButton = workspace.querySelector('[data-action="clear-log"]');
    setTextContent(count, String(entries.length).padStart(2, '0'));
    clearButton.hidden = entries.length === 0;

    if (entries.length === 0) {
        const empty = content.querySelector('.empty-state') || createEmptyLogState();
        reconcileChildren(content, [empty]);
        return;
    }

    let list = content.querySelector('.log-list');
    if (list === null) {
        list = document.createElement('div');
        list.className = 'log-list';
    }

    const existingEntries = new Map(Array.from(list.children, (row) => [row.dataset.logEntryId, row]));
    const rows = [...entries].reverse().map((entry) => {
        const entryId = String(entry.id);
        const row = existingEntries.get(entryId) || document.createElement('article');
        updateLogEntry(row, entry);

        return row;
    });
    reconcileChildren(list, rows);
    reconcileChildren(content, [list]);
}

function createOutletTile(outlet, isConnected) {
    const tile = document.createElement('article');
    tile.className = 'outlet-tile';
    const marker = document.createElement('span');
    marker.className = 'outlet-marker';
    const details = document.createElement('span');
    details.className = 'outlet-details';
    const title = document.createElement('strong');
    const ids = document.createElement('small');
    const chargingIndicator = document.createElement('span');
    chargingIndicator.className = 'charging-indicator';
    chargingIndicator.setAttribute('role', 'status');
    const chargingLabel = document.createElement('span');
    chargingLabel.className = 'charging-label';
    chargingLabel.textContent = 'CHARGING';
    const chargingMeter = document.createElement('span');
    chargingMeter.className = 'charging-meter';
    chargingMeter.setAttribute('aria-hidden', 'true');
    const chargingFill = document.createElement('span');
    chargingFill.className = 'charging-meter-fill';
    chargingMeter.append(chargingFill);
    chargingIndicator.append(chargingLabel, chargingMeter);
    details.append(title, ids, chargingIndicator);
    const badge = document.createElement('span');
    const action = document.createElement('button');
    action.className = 'button button-quiet outlet-action';
    action.type = 'button';
    const chargeControls = document.createElement('div');
    chargeControls.className = 'outlet-charge-controls';
    for (const [actionName, label] of [
        ['suspend-ev', 'Suspend EV'],
        ['suspend-evse', 'Suspend EVSE'],
        ['resume-charging', 'Resume'],
    ]) {
        const control = document.createElement('button');
        control.className = 'button button-quiet button-small';
        control.type = 'button';
        control.dataset.action = actionName;
        control.textContent = label;
        chargeControls.append(control);
    }
    tile.append(marker, details, badge, action, chargeControls);
    updateOutletTile(tile, outlet, isConnected);

    return tile;
}

function updateOutletTile(tile, outlet, isConnected) {
    const connectorStatus = outlet.connector_status || 'Available';
    const remoteStartPending = outlet.remote_start_pending === true;
    const authorizationPending = outlet.authorization_pending === true;
    const availabilityScheduled = connectorStatus === 'Available' && outlet.can_start_transaction === false;
    const isCharging = outlet.is_charging === true;
    const isSuspended = outlet.suspended_by === 'EV' || outlet.suspended_by === 'EVSE';
    tile.dataset.outletNumber = String(outlet.number);
    setTextContent(tile.querySelector('.outlet-marker'), String(outlet.number).padStart(2, '0'));
    setTextContent(tile.querySelector('.outlet-details strong'), `Outlet ${outlet.number}`);
    setTextContent(tile.querySelector('.outlet-details small'), `EVSE ${outlet.evse_id} · Connector ${outlet.connector_id}`);
    tile.querySelector('.charging-indicator').hidden = !isCharging;
    const badge = tile.querySelector('.outlet-tag') || tile.children[2];
    const badgeClass = `outlet-tag outlet-status-${remoteStartPending || authorizationPending || availabilityScheduled ? 'waiting' : connectorStatus.toLowerCase()}`;
    if (badge.className !== badgeClass) {
        badge.className = badgeClass;
    }
    setTextContent(badge, remoteStartPending || authorizationPending
        ? 'WAITING FOR VEHICLE'
        : (availabilityScheduled
            ? 'CHANGE SCHEDULED'
            : (isSuspended ? `SUSPENDED BY ${outlet.suspended_by}` : connectorStatus.toUpperCase())));
    const action = tile.querySelector('.outlet-action');
    const actionName = connectorStatus === 'Occupied' && !remoteStartPending ? 'unplug' : 'plug-in';
    const actionText = remoteStartPending || authorizationPending
        ? 'Connect vehicle'
        : (connectorStatus === 'Occupied' ? 'Unplug' : 'Plug in');
    if (action.dataset.action !== actionName) {
        action.dataset.action = actionName;
    }
    action.dataset.outletNumber = String(outlet.number);
    const shouldDisableAction = !isConnected
        || connectorStatus === 'Unavailable'
        || (connectorStatus === 'Available' && outlet.can_start_transaction === false && !authorizationPending);
    if (action.disabled !== shouldDisableAction) {
        action.disabled = shouldDisableAction;
    }
    setTextContent(action, actionText);
    const chargeControls = tile.querySelector('.outlet-charge-controls');
    chargeControls.hidden = outlet.can_suspend_charging !== true;
    for (const control of chargeControls.querySelectorAll('button')) {
        const isResume = control.dataset.action === 'resume-charging';
        control.hidden = isResume ? !isSuspended : isSuspended;
        control.disabled = !isConnected;
        control.dataset.outletNumber = String(outlet.number);
    }
}

function renderOutlets(workspace, outlets, topology, isConnected) {
    const list = workspace.querySelector('[data-role="outlet-list"]');
    const card = workspace.querySelector('[data-role="charger-card"]');
    const count = workspace.querySelector('[data-role="outlet-count"]');
    const deviceType = workspace.querySelector('[data-role="device-type"]');
    const topologyHint = workspace.querySelector('[data-role="topology-hint"]');
    if (card.dataset.outletCount !== String(outlets.length)) {
        card.dataset.outletCount = String(outlets.length);
    }
    setTextContent(count, `${outlets.length} OUTLET${outlets.length === 1 ? '' : 'S'}`);
    setTextContent(deviceType, `AC · ${outlets.length} OUTLET${outlets.length === 1 ? '' : 'S'}`);
    setTextContent(topologyHint, topology === 'shared'
        ? 'One EVSE with shared charging hardware.'
        : 'Separate EVSE IDs for each outlet.');

    const existingTiles = new Map(Array.from(list.children, (tile) => [tile.dataset.outletNumber, tile]));
    const tiles = outlets.map((outlet) => {
        const outletNumber = String(outlet.number);
        const tile = existingTiles.get(outletNumber) || createOutletTile(outlet, isConnected);
        updateOutletTile(tile, outlet, isConnected);

        return tile;
    });
    reconcileChildren(list, tiles);
}

function updateChargerConfigForm(workspace, charger) {
    const configForm = workspace.querySelector('[data-action="save"]');
    for (const [name, value] of Object.entries(charger)) {
        const field = configForm.elements.namedItem(name);
        if (field && field.value !== String(value)) {
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
}

function updateOutletAuthorizationSelect(workspace, outlets) {
    const outletSelect = workspace.querySelector('[data-action="authorize"] select[name="outlet_number"]');
    const selectableOutlets = outlets.filter((outlet) => outlet.connector_status === 'Occupied'
        || (outlet.connector_status === 'Available' && outlet.can_start_transaction === true));
    const currentValue = outletSelect.value;
    const existingOptions = new Map(Array.from(outletSelect.options, (option) => [option.value, option]));
    const options = [
        existingOptions.get('') || outletSelect.options[0],
        existingOptions.get('authorization-only') || outletSelect.options[1],
    ];
    for (const outlet of selectableOutlets) {
        const value = String(outlet.number);
        const option = existingOptions.get(value) || document.createElement('option');
        option.value = value;
        const state = outlet.connector_status === 'Occupied' ? 'Occupied' : 'Available';
        setTextContent(option, `Outlet ${outlet.number} · EVSE ${outlet.evse_id} · ${state}`);
        options.push(option);
    }
    reconcileChildren(outletSelect, options);
    if (currentValue === 'authorization-only') {
        outletSelect.value = currentValue;
    } else if (selectableOutlets.length === 1) {
        outletSelect.value = String(selectableOutlets[0].number);
    } else if (!selectableOutlets.some((outlet) => String(outlet.number) === currentValue)) {
        outletSelect.value = '';
    }
}

function renderCharger(charger, workspace = null, preserveFormInput = false) {
    const isNew = workspace === null;
    if (isNew) {
        workspace = chargerTemplate.content.firstElementChild.cloneNode(true);
    }
    workspace.dataset.chargerId = String(charger.id);
    if (!preserveFormInput || isNew) {
        updateChargerConfigForm(workspace, charger);
    }

    setTextContent(workspace.querySelector('[data-role="station-identity"]'), charger.station_identity);
    setTextContent(workspace.querySelector('[data-role="vendor-name"]'), charger.vendor_name);
    setTextContent(workspace.querySelector('[data-role="model-name"]'), charger.model);
    const connectionStatus = charger.connection_status;
    setTextContent(
        workspace.querySelector('[data-role="boot-status-text"]'),
        connectionStatus === 'Connected' ? `Connected · Boot ${charger.boot_status}` : connectionStatus,
    );
    const status = workspace.querySelector('[data-role="boot-status"]');
    status.classList.toggle('status-online', connectionStatus === 'Connected');
    status.classList.toggle('status-error', connectionStatus === 'Connection failed');
    status.classList.toggle('status-idle', connectionStatus === 'Not connected');
    workspace.querySelector('[data-action="cold-boot"]').disabled = charger.boot_status !== 'Accepted';
    renderOutlets(workspace, charger.outlets, charger.outlet_topology, connectionStatus === 'Connected');
    updateOutletAuthorizationSelect(workspace, charger.outlets);
    renderLog(workspace, charger.entries);

    return workspace;
}

function captureLogScrollPositions() {
    const positions = new Map();
    for (const workspace of chargerList.querySelectorAll('.charger-workspace')) {
        const content = workspace.querySelector('[data-role="log-content"]');
        const containerTop = content.getBoundingClientRect().top;
        const anchor = Array.from(content.querySelectorAll('[data-log-entry-id]'))
            .find((entry) => entry.getBoundingClientRect().bottom > containerTop);
        positions.set(workspace.dataset.chargerId, {
            scrollTop: content.scrollTop,
            followsNewest: content.scrollTop === 0,
            anchorId: anchor?.dataset.logEntryId ?? null,
            anchorTop: anchor === undefined
                ? null
                : anchor.getBoundingClientRect().top - containerTop,
        });
    }

    return positions;
}

function restoreLogScrollPositions(positions) {
    for (const workspace of chargerList.querySelectorAll('.charger-workspace')) {
        const position = positions.get(workspace.dataset.chargerId);
        if (position === undefined) {
            continue;
        }

        const content = workspace.querySelector('[data-role="log-content"]');
        if (position.followsNewest) {
            content.scrollTop = 0;
            continue;
        }

        const anchor = Array.from(content.querySelectorAll('[data-log-entry-id]'))
            .find((entry) => entry.dataset.logEntryId === position.anchorId);
        if (anchor !== undefined && position.anchorTop !== null) {
            const currentTop = anchor.getBoundingClientRect().top - content.getBoundingClientRect().top;
            content.scrollTop += currentTop - position.anchorTop;
        } else {
            content.scrollTop = position.scrollTop;
        }
    }
}

function renderState(state, preserveFormInput = false) {
    const logScrollPositions = captureLogScrollPositions();
    if (!preserveFormInput) {
        const workspaceCsmsForm = document.querySelector('#workspace-csms-form');
        const defaultCsmsUrl = workspaceCsmsForm.elements.namedItem('default_csms_url');
        if (defaultCsmsUrl.value !== state.settings.default_csms_url) {
            defaultCsmsUrl.value = state.settings.default_csms_url;
        }
        const tlsForm = document.querySelector('#workspace-tls-form');
        const tlsServerName = tlsForm.elements.namedItem('tls_server_name');
        if (tlsServerName.value !== state.settings.tls_server_name) {
            tlsServerName.value = state.settings.tls_server_name;
        }
        const certificateField = tlsForm.elements.namedItem('tls_ca_certificate');
        if (certificateField.value !== '') {
            certificateField.value = '';
        }
        const certificatePlaceholder = state.settings.has_tls_ca_certificate
            ? 'Leave blank to keep the saved CA certificate'
            : 'System trust store is used when blank';
        if (certificateField.placeholder !== certificatePlaceholder) {
            certificateField.placeholder = certificatePlaceholder;
        }
        const clearCertificate = tlsForm.elements.namedItem('clear_tls_ca_certificate');
        clearCertificate.disabled = !state.settings.has_tls_ca_certificate;
    }

    const existingWorkspaces = new Map(Array.from(
        chargerList.children,
        (workspace) => [workspace.dataset.chargerId, workspace],
    ));
    const workspaces = state.chargers.map((charger) => renderCharger(
        charger,
        existingWorkspaces.get(String(charger.id)) || null,
        preserveFormInput,
    ));
    reconcileChildren(chargerList, workspaces);
    restoreLogScrollPositions(logScrollPositions);
    setTextContent(document.querySelector('#charger-count'), String(state.chargers.length).padStart(2, '0'));
    setTextContent(document.querySelector('[data-role="test-bench-charger-count"]'), String(state.chargers.length).padStart(2, '0'));
    const chargerSelect = networkTestForm.elements.namedItem('network_test_charger_id');
    const selectedChargerId = chargerSelect.value;
    const existingOptions = new Map(Array.from(chargerSelect.options, (option) => [option.value, option]));
    const chargerOptions = state.chargers.map((charger) => {
        const value = String(charger.id);
        const option = existingOptions.get(value) || document.createElement('option');
        option.value = value;
        setTextContent(option, charger.station_identity);

        return option;
    });
    reconcileChildren(chargerSelect, chargerOptions);
    if (state.chargers.some((charger) => String(charger.id) === selectedChargerId)) {
        chargerSelect.value = selectedChargerId;
    }
}

function formFieldKey(field) {
    const workspace = field.closest('.charger-workspace');
    if (workspace) {
        return `${workspace.dataset.chargerId}:${field.form?.dataset.action || ''}:${field.name}`;
    }

    return `${field.form?.id || ''}:${field.name}`;
}

function captureFormState() {
    const fields = [...document.querySelectorAll('input[name], select[name], textarea[name]')];
    const activeField = fields.includes(document.activeElement) ? document.activeElement : null;
    let selectionStart = null;
    let selectionEnd = null;
    if (activeField && typeof activeField.setSelectionRange === 'function') {
        try {
            selectionStart = activeField.selectionStart;
            selectionEnd = activeField.selectionEnd;
        } catch {
            selectionStart = null;
            selectionEnd = null;
        }
    }

    return {
        activeKey: activeField ? formFieldKey(activeField) : null,
        selectionStart,
        selectionEnd,
        values: fields.map((field) => ({
            key: formFieldKey(field),
            value: field.value,
            checked: 'checked' in field ? field.checked : null,
        })),
    };
}

function restoreFormState(formState) {
    const values = new Map(formState.values.map((field) => [field.key, field]));
    const fields = [...document.querySelectorAll('input[name], select[name], textarea[name]')];
    for (const field of fields) {
        const saved = values.get(formFieldKey(field));
        if (!saved) {
            continue;
        }

        if (field.value !== saved.value) {
            field.value = saved.value;
        }

        if (saved.checked !== null) {
            if (field.checked !== saved.checked) {
                field.checked = saved.checked;
            }
        }
    }

    if (formState.activeKey !== null) {
        const activeField = fields.find((field) => formFieldKey(field) === formState.activeKey);
        if (activeField) {
            if (document.activeElement !== activeField) {
                activeField.focus({ preventScroll: true });
            }
            if (formState.selectionStart !== null && formState.selectionEnd !== null
                && typeof activeField.setSelectionRange === 'function') {
                try {
                    activeField.setSelectionRange(formState.selectionStart, formState.selectionEnd);
                } catch {
                }
            }
        }
    }
}

function showScreen(screenId) {
    const selectedScreen = screenId === 'setup-screen' ? setupScreen : testingScreen;
    const listSlot = selectedScreen.querySelector('.charger-list-slot');
    listSlot.append(chargerList);
    setupScreen.hidden = selectedScreen !== setupScreen;
    testingScreen.hidden = selectedScreen !== testingScreen;
    document.body.dataset.activeScreen = selectedScreen.id;
    for (const button of screenNavigation.querySelectorAll('[data-screen-target]')) {
        button.setAttribute('aria-pressed', String(button.dataset.screenTarget === selectedScreen.id));
    }
}

function revealNewCharger(chargerId) {
    const workspace = Array.from(chargerList.children)
        .find((charger) => charger.dataset.chargerId === String(chargerId));
    if (workspace === undefined) {
        return;
    }

    workspace.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const stationIdentity = workspace.querySelector('[data-action="save"] [name="station_identity"]');
    if (stationIdentity !== null) {
        stationIdentity.focus({ preventScroll: true });
    }
}

let stateRefreshPromise = null;

async function refreshState(preserveFormInput = false) {
    if (stateRefreshPromise !== null) {
        await stateRefreshPromise.catch(() => {});
        if (preserveFormInput) {
            return;
        }
    }

    const formState = preserveFormInput ? captureFormState() : null;
    const refreshTask = (async () => {
        const state = await api('/api/state');
        renderState(state, preserveFormInput);
        if (formState !== null) {
            restoreFormState(formState);
        }
    })();
    stateRefreshPromise = refreshTask;
    try {
        await refreshTask;
    } finally {
        if (stateRefreshPromise === refreshTask) {
            stateRefreshPromise = null;
        }
    }
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
            const selectedOutlet = form.elements.namedItem('outlet_number').value;
            await postAction('/api/authorize', {
                rfid_token: formValues(form).rfid_token,
                outlet_number: selectedOutlet === 'authorization-only' ? undefined : selectedOutlet,
                charger_id: chargerId,
            });
        }
    } catch (error) {
        await refreshState().catch(() => {});
        showNotice(error.message, true);
    }
});

screenNavigation.addEventListener('click', (event) => {
    const button = event.target.closest('[data-screen-target]');
    if (button) {
        showScreen(button.dataset.screenTarget);
    }
});

networkTestForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = networkTestForm.querySelector('[data-action="test-connection"]');
    button.disabled = true;
    networkTestResult.hidden = false;
    networkTestResult.classList.remove('result-error');
    networkTestResult.textContent = 'Opening a WebSocket and checking TLS and OCPP negotiation…';
    try {
        const result = await api('/api/connection/test', {
            method: 'POST',
            body: JSON.stringify({ charger_id: Number(networkTestForm.elements.namedItem('network_test_charger_id').value) }),
        });
        networkTestResult.textContent = result.notice;
    } catch (error) {
        networkTestResult.classList.add('result-error');
        networkTestResult.textContent = error.message;
    } finally {
        button.disabled = false;
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
        } else if (button.dataset.action === 'cold-boot') {
            await postAction('/api/cold-boot', { charger_id: chargerId });
        } else if (button.dataset.action === 'plug-in' || button.dataset.action === 'unplug') {
            const path = button.dataset.action === 'plug-in' ? '/api/outlet/plug-in' : '/api/outlet/unplug';
            await postAction(path, { charger_id: chargerId, outlet_number: Number(button.dataset.outletNumber) });
        } else if (['suspend-ev', 'suspend-evse', 'resume-charging'].includes(button.dataset.action)) {
            const chargingState = button.dataset.action === 'suspend-ev'
                ? 'SuspendedEV'
                : (button.dataset.action === 'suspend-evse' ? 'SuspendedEVSE' : 'Charging');
            await postAction('/api/outlet/charging-state', {
                charger_id: chargerId,
                outlet_number: Number(button.dataset.outletNumber),
                charging_state: chargingState,
            });
        } else if (button.dataset.action === 'delete') {
            const stationIdentity = workspace.querySelector('[data-role="station-identity"]').textContent.trim();
            if (!window.confirm(`Delete charger “${stationIdentity}” and its saved configuration and logs?`)) {
                return;
            }

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

document.querySelector('#workspace-tls-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        await postAction('/api/settings/tls', formValues(event.currentTarget));
    } catch (error) {
        showNotice(error.message, true);
    }
});

document.querySelector('#add-charger').addEventListener('click', async () => {
    try {
        const result = await api('/api/charger/create', { method: 'POST', body: '{}' });
        await refreshState();
        revealNewCharger(result.charger_id);
        showNotice(result.notice);
    } catch (error) {
        showNotice(error.message, true);
    }
});

showScreen('testing-screen');
refreshState().catch((error) => showNotice(error.message, true));
setInterval(() => {
    if (!document.hidden) {
        refreshState(true).catch(() => {});
    }
}, 1000);
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        refreshState(true).catch(() => {});
    }
});
