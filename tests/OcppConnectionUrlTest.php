<?php

declare(strict_types=1);

use ChargerEmulator\Protocol\OcppConnectionUrl;

it('derives encoded station connection URLs', function (): void {
    $connectionUrl = OcppConnectionUrl::fromEndpoint('wss://csms.example/ocpp?tenant=dev', 'Station 01');

    $endpointUrl = OcppConnectionUrl::endpointFromConnectionUrl($connectionUrl, 'Station 01');

    expect($connectionUrl !== 'wss://csms.example/ocpp/Station%2001?tenant=dev'
        || $endpointUrl !== 'wss://csms.example/ocpp?tenant=dev')->toBeFalse('OCPP-J connection URLs should append the encoded station identity and retain endpoint query parameters.');

});
