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
if (!is_file($scriptPath)) {
    throw new RuntimeException('The static GUI should load its separate browser script.');
}
$script = file_get_contents($scriptPath);
if (!is_string($script) || !str_starts_with($script, "'use strict';")) {
    throw new RuntimeException('The browser script should enable strict mode.');
}

echo "Standalone frontend separation: PASS\n";
