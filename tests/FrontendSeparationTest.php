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
foreach (['name="outlet_count"', 'name="outlet_topology"', 'id="outlet-list"'] as $requiredElement) {
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
if (!str_contains($script, 'state.outlets')) {
    throw new RuntimeException('The browser should render OCPP outlet identifiers from API state.');
}

echo "Standalone frontend separation: PASS\n";
