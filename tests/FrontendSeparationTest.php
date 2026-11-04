<?php

declare(strict_types=1);

it('serves a standalone frontend with strict JavaScript', function (): void {
    $htmlPath = __DIR__ . '/../public/index.html';

    expect(!is_file($htmlPath))->toBeFalse('The station GUI should be a standalone HTML file.');

    $html = file_get_contents($htmlPath);

    $scriptPath = __DIR__ . '/../public/app.js';

    expect(!is_string($html) || str_contains($html, '<?php') || !str_contains($html, '<script src="/app.js" defer></script>'))->toBeFalse('The GUI should be static HTML with a separate browser script.');

    foreach (['name="csms_password"', 'name="clear_csms_password"', 'name="outlet_count"', 'name="outlet_topology"', 'name="uses_default_csms"', 'name="outlet_number"', 'id="workspace-csms-form"', 'id="workspace-tls-form"', 'name="tls_server_name"', 'name="tls_ca_certificate"', 'id="network-test-form"', 'data-role="outlet-list"', 'id="charger-template"', 'id="add-charger"'] as $requiredElement) {
        expect(!str_contains($html, $requiredElement))->toBeFalse("The GUI should expose setup and charger controls: {$requiredElement}");
    }

    expect(!is_file($scriptPath))->toBeFalse('The static GUI should load its separate browser script.');

    $script = file_get_contents($scriptPath);

    expect(!is_string($script) || !str_starts_with($script, "'use strict';"))->toBeFalse('The browser script should enable strict mode.');

    expect(!str_contains($script, 'state.chargers') || !str_contains($script, 'charger.entries'))->toBeFalse('The browser should render multiple chargers with separate outlet and log state.');

    expect(!str_contains($script, 'connector_status')
        || !str_contains($script, '/api/outlet/plug-in')
        || !str_contains($script, '/api/outlet/unplug'))->toBeFalse('The outlet UI should display connector state and expose plug-in and unplug controls.');
    expect(!str_contains($script, 'remote_start_pending')
        || !str_contains($script, 'WAITING FOR VEHICLE')
        || !str_contains($script, 'Connect vehicle'))->toBeFalse('The outlet UI should distinguish an accepted remote start from a physically connected vehicle.');

});

it('separates network setup and connection testing from the OCPP test bench', function (): void {
    $html = file_get_contents(__DIR__ . '/../public/index.html');
    $script = file_get_contents(__DIR__ . '/../public/app.js');

    expect(!is_string($html))->toBeFalse()
        ->and($html)->toContain('id="screen-navigation"')
        ->and($html)->toContain('id="setup-screen"')
        ->and($html)->toContain('id="testing-screen"')
        ->and($html)->toContain('id="network-test-form"')
        ->and($html)->toContain('name="network_test_charger_id"')
        ->and($html)->toContain('data-action="test-connection"')
        ->and($html)->toContain('id="setup-charger-list"')
        ->and($html)->toContain('id="test-bench-charger-list"')
        ->and($html)->toContain('data-screen-target="setup-screen"')
        ->and($html)->toContain('data-screen-target="testing-screen"')
        ->and(!is_string($script))->toBeFalse()
        ->and($script)->toContain('/api/connection/test')
        ->and($script)->toContain('/api/settings/tls')
        ->and($script)->toContain('data-screen-target');
});

it('refreshes CSMS initiated state without losing in-progress form input', function (): void {
    $script = file_get_contents(__DIR__ . '/../public/app.js');

    expect(!is_string($script))->toBeFalse()
        ->and($script)->toContain('setInterval')
        ->and($script)->toContain('refreshState(true)')
        ->and($script)->toContain('captureFormState');
});

it('keeps charger connection status text on one line', function (): void {
    $styles = file_get_contents(__DIR__ . '/../public/style.css');

    expect(!is_string($styles))->toBeFalse()
        ->and(preg_match('/\.status-chip\s*\{[^}]*white-space:\s*nowrap/s', $styles))->toBe(1);
});

it('keeps workspace notices on one line without applying icon sizing to the message', function (): void {
    $styles = file_get_contents(__DIR__ . '/../public/style.css');

    expect(!is_string($styles))->toBeFalse()
        ->and(preg_match('/\.notice\s*>\s*span:first-child\s*\{/', $styles))->toBe(1)
        ->and(preg_match('/\.notice\s*>\s*span:last-child\s*\{[^}]*white-space:\s*nowrap/s', $styles))->toBe(1);
});

it('groups each charger and communication log in one aligned panel with a single log scroll area', function (): void {
    $html = file_get_contents(__DIR__ . '/../public/index.html');
    $styles = file_get_contents(__DIR__ . '/../public/style.css');
    $workspaceStyles = is_string($styles) && preg_match('/^\.charger-workspace\s*\{[^}]*\}/ms', $styles, $matches) === 1
        ? $matches[0]
        : '';
    $logCardPosition = is_string($html) ? strpos($html, '<div class="log-card">') : false;
    $logToolbarPosition = is_string($html) && is_int($logCardPosition)
        ? strpos($html, 'class="log-toolbar"', $logCardPosition)
        : false;

    expect(!is_string($html) || !str_contains($html, 'class="charger-workspace"'))->toBeFalse()
        ->and(!str_contains($html, 'class="charger-card"') || !str_contains($html, 'class="log-card"'))->toBeFalse()
        ->and(!is_int($logCardPosition) || !is_int($logToolbarPosition) || $logToolbarPosition < $logCardPosition)->toBeFalse('The log toolbar should sit inside the same panel as its messages.')
        ->and(!is_string($styles))->toBeFalse()
        ->and($workspaceStyles)->toContain('border: 1px solid')
        ->toContain('background: #fff')
        ->toContain('overflow: hidden')
        ->toContain('gap: 0')
        ->toContain('align-items: stretch')
        ->and(preg_match('/\.log-card\s*\{[^}]*height:\s*min\([^}]*display:\s*flex[^}]*flex-direction:\s*column/s', $styles))->toBe(1)
        ->and(preg_match('/\.log-content\s*\{[^}]*min-height:\s*0[^}]*overflow-y:\s*auto/s', $styles))->toBe(1)
        ->and(preg_match('/\.log-entry\s+pre\s*\{[^}]*overflow:\s*auto/s', $styles))->toBe(0);
});

it('shows the newest exchange first and preserves the log position during polling', function (): void {
    $html = file_get_contents(__DIR__ . '/../public/index.html');
    $script = file_get_contents(__DIR__ . '/../public/app.js');

    expect(!is_string($html) || !str_contains($html, 'NEWEST AT TOP'))->toBeFalse()
        ->and($script)->toContain('[...entries].reverse()')
        ->and(!is_string($script) || !str_contains($script, 'captureLogScrollPositions'))->toBeFalse()
        ->and(!is_string($script) || !str_contains($script, 'restoreLogScrollPositions'))->toBeFalse();
});

it('shows an animated charging indicator only for outlets that are actively charging', function (): void {
    $script = file_get_contents(__DIR__ . '/../public/app.js');
    $styles = file_get_contents(__DIR__ . '/../public/style.css');

    expect(!is_string($script))->toBeFalse()
        ->and($script)->toContain('outlet.is_charging === true')
        ->toContain("chargingIndicator.className = 'charging-indicator'")
        ->toContain("tile.querySelector('.charging-indicator').hidden = !isCharging")
        ->toContain("chargingMeter.className = 'charging-meter'")
        ->and(!is_string($styles))->toBeFalse()
        ->and($styles)->toContain('.charging-indicator')
        ->toContain('.charging-meter-fill')
        ->toContain('@keyframes charging-meter-flow');
});

it('keeps the log count and clear control together in one responsive toolbar', function (): void {
    $html = file_get_contents(__DIR__ . '/../public/index.html');
    $styles = file_get_contents(__DIR__ . '/../public/style.css');

    expect(!is_string($html))->toBeFalse()
        ->and($html)->not->toContain('Communication log')
        ->not->toContain('LIVE INSPECTION')
        ->toContain('class="log-toolbar-info"')
        ->toContain('class="log-toolbar-actions"')
        ->toContain('data-role="log-count"')
        ->toContain('data-action="clear-log"')
        ->toContain('NEWEST AT TOP')
        ->and(!is_string($styles))->toBeFalse()
        ->and(preg_match('/\.log-toolbar\s*\{[^}]*min-height:[^}]*flex-wrap:\s*wrap/s', $styles))->toBe(1);
});

it('updates charger panels in place during polling instead of rebuilding their contents', function (): void {
    $script = file_get_contents(__DIR__ . '/../public/app.js');

    expect(!is_string($script))->toBeFalse()
        ->and($script)->not->toContain('chargerList.replaceChildren(...state.chargers.map(renderCharger))')
        ->not->toContain('list.replaceChildren()')
        ->not->toContain('content.replaceChildren()')
        ->toContain('function reconcileChildren(')
        ->toContain('function updateOutletTile(')
        ->toContain('function updateLogEntry(');
});
