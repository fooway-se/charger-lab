<?php

declare(strict_types=1);

$htmlPath = __DIR__ . '/../public/index.html';
if (!is_file($htmlPath)) {
    throw new RuntimeException('The station GUI should be a standalone HTML file.');
}

$html = file_get_contents($htmlPath);
$scriptPath = __DIR__ . '/../public/app.js';
if (!is_string($html) || str_contains($html, '<?php') || !str_contains($html, '<script src="/app.js" defer></script>')) {
    throw new RuntimeException('The GUI should be static HTML with a separate browser script.');
}
foreach (['name="outlet_count"', 'name="outlet_topology"', 'name="uses_default_csms"', 'name="outlet_number"', 'id="workspace-csms-form"', 'data-role="outlet-list"', 'id="charger-template"', 'id="add-charger"'] as $requiredElement) {
    if (!str_contains($html, $requiredElement)) {
        throw new RuntimeException("The GUI should expose configurable outlet topology: {$requiredElement}");
    }
}
if (!is_file($scriptPath)) {
    throw new RuntimeException('The static GUI should load its separate browser script.');
}
$script = file_get_contents($scriptPath);
if (!is_string($script) || !str_starts_with($script, "'use strict';")) {
    throw new RuntimeException('The browser script should enable strict mode.');
}
if (!str_contains($script, 'state.chargers') || !str_contains($script, 'charger.entries')) {
    throw new RuntimeException('The browser should render multiple chargers with separate outlet and log state.');
}
if (!str_contains($script, 'connector_status')
    || !str_contains($script, '/api/outlet/plug-in')
    || !str_contains($script, '/api/outlet/unplug')) {
    throw new RuntimeException('The outlet UI should display connector state and expose plug-in and unplug controls.');
}

echo "Standalone frontend separation: PASS\n";
