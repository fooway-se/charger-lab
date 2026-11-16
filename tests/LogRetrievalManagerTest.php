<?php

declare(strict_types=1);

use ChargerEmulator\Domain\LogRetrievalManager;
use ChargerEmulator\Domain\LogUploader;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ExchangeRepository;

final class RecordingLogFileUploader implements LogUploader
{
    public array $uploads = [];

    public function __construct(private array $results)
    {
    }

    public function upload(string $remoteLocation, string $filename, string $contents): bool
    {
        $this->uploads[] = compact('remoteLocation', 'filename', 'contents');

        return array_shift($this->results) ?? false;
    }
}

function createLogRetrievalManager(array $uploadResults = []): array
{
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $logs = new ExchangeRepository($database);
    $uploader = new RecordingLogFileUploader($uploadResults);

    return [$chargerId, $logs, $uploader, new LogRetrievalManager($logs, $uploader)];
}

function getLogRequest(int $requestId, string $logType = 'DiagnosticsLog', array $overrides = []): array
{
    return array_replace([
        'requestId' => $requestId,
        'logType' => $logType,
        'log' => ['remoteLocation' => 'http://127.0.0.1:8080/logs/'],
    ], $overrides);
}

it('uploads diagnostics and reports Uploading followed by Uploaded', function (): void {
    [$chargerId, $logs, $uploader, $manager] = createLogRetrievalManager([true]);
    $logs->record($chargerId, 'outgoing', 'Heartbeat', '[2,"id","Heartbeat",{}]');

    expect($manager->request($chargerId, getLogRequest(25)))->toBe([
        'status' => 'Accepted',
        'filename' => 'diagnostics-25.log',
    ]);
    $manager->acceptedResponse($chargerId, 25);
    expect($manager->takeNextNotification($chargerId))->toBe(['requestId' => 25, 'status' => 'Uploading'])
        ->and($manager->uploadWhenDue($chargerId))->toBeTrue()
        ->and($manager->takeNextNotification($chargerId))->toBe(['requestId' => 25, 'status' => 'Uploaded'])
        ->and($uploader->uploads[0]['contents'])->toContain('Heartbeat');
});

it('routes loopback log upload locations through the active CSMS hostname', function (): void {
    [$chargerId, , $uploader, $manager] = createLogRetrievalManager([true]);
    $request = getLogRequest(27);
    $request['log']['remoteLocation'] = 'http://localhost:8090/logs/?token=abc';

    expect($manager->request($chargerId, $request, 'wss://ocpp-gateway:8080/ocpp/station-1')['status'])->toBe('Accepted');
    $manager->acceptedResponse($chargerId, 27);
    $manager->takeNextNotification($chargerId);
    $manager->uploadWhenDue($chargerId);

    expect($uploader->uploads[0]['remoteLocation'])->toBe('http://ocpp-gateway:8090/logs/?token=abc');
});

it('rewrites loopback and keeps non-loopback log upload locations unchanged', function (): void {
    [$chargerId, , $uploader, $manager] = createLogRetrievalManager([true, true]);
    $manager->request($chargerId, getLogRequest(28), 'wss://ocpp-gateway:8080/ocpp/station-1');
    $manager->acceptedResponse($chargerId, 28);
    $manager->takeNextNotification($chargerId);
    $manager->uploadWhenDue($chargerId);

    $request = getLogRequest(29);
    $request['log']['remoteLocation'] = 'https://logs.allmeter.example/upload';
    $manager->request($chargerId, $request, 'wss://ocpp-gateway:8080/ocpp/station-1');
    $manager->acceptedResponse($chargerId, 29);
    $manager->takeNextNotification($chargerId);
    $manager->uploadWhenDue($chargerId);

    expect($uploader->uploads[0]['remoteLocation'])->toBe('http://ocpp-gateway:8080/logs/')
        ->and($uploader->uploads[1]['remoteLocation'])->toBe('https://logs.allmeter.example/upload');
});

it('does not rewrite loopback uploads when the CSMS endpoint is also loopback', function (): void {
    [$chargerId, , $uploader, $manager] = createLogRetrievalManager([true]);
    $manager->request($chargerId, getLogRequest(30), 'ws://127.0.0.1:8080/ocpp/station-1');
    $manager->acceptedResponse($chargerId, 30);
    $manager->takeNextNotification($chargerId);
    $manager->uploadWhenDue($chargerId);

    expect($uploader->uploads[0]['remoteLocation'])->toBe('http://127.0.0.1:8080/logs/');
});

it('retries failed log uploads and reports one terminal UploadFailure', function (): void {
    [$chargerId, , $uploader, $manager] = createLogRetrievalManager([false, false, false, false]);
    expect($manager->request($chargerId, getLogRequest(26, overrides: [
        'retries' => 3,
        'retryInterval' => 0,
    ]))['status'])->toBe('Accepted');
    $manager->acceptedResponse($chargerId, 26);
    $manager->takeNextNotification($chargerId);

    for ($attempt = 0; $attempt < 4; $attempt++) {
        expect($manager->uploadWhenDue($chargerId))->toBeTrue();
    }

    expect(count($uploader->uploads))->toBe(4)
        ->and($manager->takeNextNotification($chargerId))->toBe(['requestId' => 26, 'status' => 'UploadFailure'])
        ->and($manager->uploadWhenDue($chargerId))->toBeFalse();
});

it('cancels an active log request when a second GetLog request arrives', function (): void {
    [$chargerId, , , $manager] = createLogRetrievalManager([true]);
    $manager->request($chargerId, getLogRequest(35));
    $manager->acceptedResponse($chargerId, 35);
    expect($manager->takeNextNotification($chargerId))->toBe(['requestId' => 35, 'status' => 'Uploading']);

    expect($manager->request($chargerId, getLogRequest(36, 'SecurityLog')))->toBe([
        'status' => 'AcceptedCanceled',
        'filename' => 'security-36.log',
    ]);
    expect($manager->takeNextNotification($chargerId))->toBe(['requestId' => 35, 'status' => 'AcceptedCanceled']);
    $manager->acceptedResponse($chargerId, 36);
    expect($manager->takeNextNotification($chargerId))->toBe(['requestId' => 36, 'status' => 'Uploading']);
});

it('filters security logs and redacts id tokens from uploaded data', function (): void {
    [$chargerId, $logs, $uploader, $manager] = createLogRetrievalManager([true]);
    $logs->record($chargerId, 'outgoing', 'TransactionEvent', '[2,"id","TransactionEvent",{"idToken":{"idToken":"private-rfid"}}]');
    $logs->record($chargerId, 'outgoing', 'SecurityEventNotification', '[2,"id","SecurityEventNotification",{"type":"StartupOfTheDevice"}]');
    $logs->record($chargerId, 'outgoing', 'NotifyCustomerInformation', '[2,"id","NotifyCustomerInformation",{"requestId":8,"data":"{\\"idToken\\":{\\"idToken\\":\\"private-rfid\\"}}"}]');

    $manager->request($chargerId, getLogRequest(37, 'SecurityLog'));
    $manager->acceptedResponse($chargerId, 37);
    $manager->takeNextNotification($chargerId);
    $manager->uploadWhenDue($chargerId);
    $manager->request($chargerId, getLogRequest(38));
    $manager->acceptedResponse($chargerId, 38);
    $manager->takeNextNotification($chargerId);
    $manager->uploadWhenDue($chargerId);

    expect($uploader->uploads[0]['contents'])->toContain('SecurityEventNotification')
        ->not->toContain('private-rfid')
        ->not->toContain('TransactionEvent')
        ->and($uploader->uploads[1]['contents'])->toContain('NotifyCustomerInformation')
        ->not->toContain('private-rfid');
});
