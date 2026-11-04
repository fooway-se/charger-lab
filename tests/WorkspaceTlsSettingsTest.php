<?php

declare(strict_types=1);

use ChargerEmulator\Controller\StationController;
use ChargerEmulator\Http\Request;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;

it('persists a custom TLS trust certificate without returning its PEM in workspace state', function (): void {
    $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    expect($privateKey === false)->toBeFalse();
    $request = openssl_csr_new(['commonName' => 'Test CSMS CA'], $privateKey);
    expect($request === false)->toBeFalse();
    $certificate = openssl_csr_sign($request, null, $privateKey, 1);
    expect($certificate === false)->toBeFalse();
    $certificatePem = '';
    expect(openssl_x509_export($certificate, $certificatePem))->toBeTrue();

    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->createDefault();
    $settings = new WorkspaceSettingsRepository($database);
    $controller = new StationController(
        $chargers,
        new ExchangeRepository($database),
        $settings,
        new OcppCommandRepository($database),
        new OutletSessionRepository($database),
    );
    $saveResponse = $controller->saveTlsSettings(new Request('POST', '/api/settings/tls', [], [], [
        'tls_server_name' => 'localhost',
        'tls_ca_certificate' => $certificatePem,
        'clear_tls_ca_certificate' => false,
    ]));
    $stateResponse = $controller->state(new Request('GET', '/api/state', [], [], []));
    $state = json_decode($stateResponse->body, true, flags: JSON_THROW_ON_ERROR);
    $savedTlsSettings = $settings->getTlsSettings();

    expect($saveResponse->status)->toBe(200)
        ->and($savedTlsSettings['tls_server_name'])->toBe('localhost')
        ->and($savedTlsSettings['tls_ca_certificate'])->toBe($certificatePem)
        ->and($state['settings']['tls_server_name'])->toBe('localhost')
        ->and($state['settings']['has_tls_ca_certificate'])->toBeTrue()
        ->and($stateResponse->body)->not->toContain($certificatePem);

    $clearResponse = $controller->saveTlsSettings(new Request('POST', '/api/settings/tls', [], [], [
        'tls_server_name' => 'localhost',
        'clear_tls_ca_certificate' => true,
    ]));
    expect($clearResponse->status)->toBe(200)
        ->and($settings->getTlsSettings()['tls_ca_certificate'])->toBe('');
});

it('rejects invalid TLS hostnames and non-PEM trust certificates', function (): void {
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargers->createDefault();
    $controller = new StationController(
        $chargers,
        new ExchangeRepository($database),
        new WorkspaceSettingsRepository($database),
        new OcppCommandRepository($database),
        new OutletSessionRepository($database),
    );

    $invalidHostResponse = $controller->saveTlsSettings(new Request('POST', '/api/settings/tls', [], [], [
        'tls_server_name' => 'localhost/ocpp',
        'tls_ca_certificate' => '',
    ]));
    $invalidCertificateResponse = $controller->saveTlsSettings(new Request('POST', '/api/settings/tls', [], [], [
        'tls_server_name' => 'localhost',
        'tls_ca_certificate' => 'not-a-certificate',
    ]));

    expect($invalidHostResponse->status)->toBe(422)
        ->and($invalidHostResponse->body)->toContain('hostname or IP address')
        ->and($invalidCertificateResponse->status)->toBe(422)
        ->and($invalidCertificateResponse->body)->toContain('valid PEM X.509');
});
