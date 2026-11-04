<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use ChargerEmulator\Protocol\OcppConnectionUrl;

$connectionUrl = OcppConnectionUrl::fromEndpoint('wss://csms.example/ocpp?tenant=dev', 'Station 01');
$endpointUrl = OcppConnectionUrl::endpointFromConnectionUrl($connectionUrl, 'Station 01');
if ($connectionUrl !== 'wss://csms.example/ocpp/Station%2001?tenant=dev'
    || $endpointUrl !== 'wss://csms.example/ocpp?tenant=dev') {
    throw new RuntimeException('OCPP-J connection URLs should append the encoded station identity and retain endpoint query parameters.');
}

echo "OCPP connection URL derivation: PASS\n";
