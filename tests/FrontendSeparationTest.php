<?php

declare(strict_types=1);

it('serves a standalone frontend with strict JavaScript', function (): void {
    $htmlPath = __DIR__ . '/../public/index.html';

    expect(!is_file($htmlPath))->toBeFalse('The station GUI should be a standalone HTML file.');

    $html = file_get_contents($htmlPath);

    $scriptPath = __DIR__ . '/../public/app.js';

    expect(!is_string($html) || str_contains($html, '<?php') || !str_contains($html, '<script src="/app.js" defer></script>'))->toBeFalse('The GUI should be static HTML with a separate browser script.');

    foreach (['name="csms_password"', 'name="clear_csms_password"', 'name="outlet_count"', 'name="outlet_topology"', 'name="uses_default_csms"', 'name="outlet_number"', 'id="workspace-csms-form"', 'data-role="outlet-list"', 'id="charger-template"', 'id="add-charger"'] as $requiredElement) {
        expect(!str_contains($html, $requiredElement))->toBeFalse("The GUI should expose setup and charger controls: {$requiredElement}");
    }

    expect(!is_file($scriptPath))->toBeFalse('The static GUI should load its separate browser script.');

    $script = file_get_contents($scriptPath);

    expect(!is_string($script) || !str_starts_with($script, "'use strict';"))->toBeFalse('The browser script should enable strict mode.');

    expect(!str_contains($script, 'state.chargers') || !str_contains($script, 'charger.entries'))->toBeFalse('The browser should render multiple chargers with separate outlet and log state.');

    expect(!str_contains($script, 'connector_status')
        || !str_contains($script, '/api/outlet/plug-in')
        || !str_contains($script, '/api/outlet/unplug'))->toBeFalse('The outlet UI should display connector state and expose plug-in and unplug controls.');

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
        ->and($script)->toContain('data-screen-target');
});
