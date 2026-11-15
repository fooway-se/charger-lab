<?php

declare(strict_types=1);

use ChargerEmulator\Domain\CustomerInformationRequestHandler;
use ChargerEmulator\Protocol\OcppCallErrorException;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\LocalAuthorizationListRepository;

function createCustomerInformationHandler(): array
{
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $authorizationLists = new LocalAuthorizationListRepository($database);

    return [$authorizationLists, new CustomerInformationRequestHandler($chargerId, $authorizationLists)];
}

it('reports matching local authorization data as one customer information message', function (): void {
    [$authorizationLists, $handler] = createCustomerInformationHandler();
    $entry = [
        'idToken' => ['idToken' => 'known-rfid', 'type' => 'ISO14443'],
        'idTokenInfo' => ['status' => 'Accepted', 'language1' => 'en'],
    ];
    $authorizationLists->apply(1, 1, 'Full', [$entry]);

    expect($handler->request([
        'requestId' => 7,
        'report' => true,
        'clear' => false,
        'idToken' => ['idToken' => 'known-rfid', 'type' => 'ISO14443'],
    ]))->toBe(['status' => 'Accepted']);

    $notifications = $handler->takeNotifications();
    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['requestId'] ?? null)->toBe(7)
        ->and($notifications[0]['seqNo'] ?? null)->toBe(0)
        ->and($notifications[0]['tbc'] ?? null)->toBeFalse()
        ->and($notifications[0]['data'] ?? '')->toContain('known-rfid', 'Accepted');
});

it('sends an empty final message when the requested customer information is absent', function (): void {
    [, $handler] = createCustomerInformationHandler();

    expect($handler->request([
        'requestId' => 8,
        'report' => true,
        'clear' => false,
        'idToken' => ['idToken' => 'unknown-rfid', 'type' => 'ISO14443'],
    ]))->toBe(['status' => 'Accepted']);

    $notification = $handler->takeNotifications()[0] ?? [];
    expect($notification['requestId'] ?? null)->toBe(8)
        ->and($notification['seqNo'] ?? null)->toBe(0)
        ->and($notification['tbc'] ?? null)->toBeFalse()
        ->and($notification['data'] ?? null)->toBe('')
        ->and($notification['generatedAt'] ?? null)->toBeString();
});

it('reports customer information before clearing matching local authorization data', function (): void {
    [$authorizationLists, $handler] = createCustomerInformationHandler();
    $entry = ['idToken' => ['idToken' => 'known-rfid', 'type' => 'ISO14443'], 'idTokenInfo' => ['status' => 'Accepted']];
    $authorizationLists->apply(1, 1, 'Full', [$entry]);

    expect($handler->request([
        'requestId' => 9,
        'report' => true,
        'clear' => true,
        'idToken' => ['idToken' => 'known-rfid', 'type' => 'ISO14443'],
    ]))->toBe(['status' => 'Accepted'])
        ->and($authorizationLists->entries(1))->toBe([$entry]);

    $notification = $handler->takeNotifications()[0] ?? [];
    expect($notification['requestId'] ?? null)->toBe(9)
        ->and($notification['seqNo'] ?? null)->toBe(0)
        ->and($notification['tbc'] ?? null)->toBeFalse()
        ->and($notification['data'] ?? '')->toContain('known-rfid');

    $handler->notificationsSent();
    expect($authorizationLists->entries(1))->toBe([]);
});

it('clears customer information without reporting or notifying', function (): void {
    [$authorizationLists, $handler] = createCustomerInformationHandler();
    $entry = ['idToken' => ['idToken' => 'known-rfid', 'type' => 'ISO14443'], 'idTokenInfo' => ['status' => 'Accepted']];
    $authorizationLists->apply(1, 1, 'Full', [$entry]);

    expect($handler->request([
        'requestId' => 11,
        'report' => false,
        'clear' => true,
        'idToken' => ['idToken' => 'known-rfid', 'type' => 'ISO14443'],
    ]))->toBe(['status' => 'Accepted'])
        ->and($handler->takeNotifications())->toBe([]);

    $handler->responseSent();
    expect($authorizationLists->entries(1))->toBe([]);
});

it('rejects unsupported customer information selectors', function (): void {
    [, $handler] = createCustomerInformationHandler();

    expect($handler->request([
        'requestId' => 10,
        'report' => true,
        'clear' => false,
        'customerIdentifier' => 'customer-1',
    ]))->toBe(['status' => 'Rejected']);
});

it('returns FormationViolation for malformed customer information requests', function (array $payload): void {
    [, $handler] = createCustomerInformationHandler();

    expect(fn () => $handler->request($payload))
        ->toThrow(OcppCallErrorException::class, 'CustomerInformation requires a valid request ID, report and clear flags.');
})->with([
    'missing request ID' => [['report' => true, 'clear' => false]],
    'report is not boolean' => [['requestId' => 1, 'report' => 'yes', 'clear' => false]],
    'unknown property' => [['requestId' => 1, 'report' => true, 'clear' => false, 'unexpected' => true]],
]);
