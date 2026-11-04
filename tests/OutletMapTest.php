<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use ChargerEmulator\Domain\OutletMap;

$independent = new OutletMap(2, 'independent');
if ($independent->outlets() !== [
    ['number' => 1, 'evse_id' => 1, 'connector_id' => 1],
    ['number' => 2, 'evse_id' => 2, 'connector_id' => 1],
]) {
    throw new RuntimeException('Independent outlets should each have their own EVSE and connector 1.');
}

$shared = new OutletMap(2, 'shared');
if ($shared->outlets() !== [
    ['number' => 1, 'evse_id' => 1, 'connector_id' => 1],
    ['number' => 2, 'evse_id' => 1, 'connector_id' => 2],
]) {
    throw new RuntimeException('Shared-power outlets should use one EVSE and distinct connector IDs.');
}

try {
    new OutletMap(0, 'independent');
    throw new RuntimeException('Expected zero outlets to be rejected.');
} catch (InvalidArgumentException) {
}

echo "Outlet topology mapping: PASS\n";
