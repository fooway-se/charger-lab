<?php

declare(strict_types=1);

use ChargerEmulator\Domain\SendLocalListRequestHandler;
use ChargerEmulator\Domain\GetLocalListVersionRequestHandler;
use ChargerEmulator\Protocol\OcppCallErrorException;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\LocalAuthorizationListRepository;

function createLocalAuthorizationListHandler(): array
{
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $lists = new LocalAuthorizationListRepository($database);

    return [$database, $chargerId, $lists, new SendLocalListRequestHandler($chargerId, $lists)];
}

it('replaces the local authorization list and sets its full update version', function (): void {
    [, $chargerId, $lists, $handler] = createLocalAuthorizationListHandler();
    $first = ['idToken' => ['idToken' => 'FIRST', 'type' => 'ISO14443'], 'idTokenInfo' => ['status' => 'Accepted']];
    $second = ['idToken' => ['idToken' => 'SECOND', 'type' => 'ISO14443'], 'idTokenInfo' => ['status' => 'Blocked']];

    expect($handler->send(['versionNumber' => 2, 'updateType' => 'Full', 'localAuthorizationList' => [$first, $second]]))
        ->toBe(['status' => 'Accepted'])
        ->and($lists->version($chargerId))->toBe(2)
        ->and($lists->entries($chargerId))->toBe([$first, $second]);
});

it('applies differential additions, replacements and removals atomically', function (): void {
    [, $chargerId, $lists, $handler] = createLocalAuthorizationListHandler();
    $kept = ['idToken' => ['idToken' => 'KEEP', 'type' => 'ISO14443'], 'idTokenInfo' => ['status' => 'Accepted']];
    $replaced = ['idToken' => ['idToken' => 'CHANGE', 'type' => 'ISO14443'], 'idTokenInfo' => ['status' => 'Accepted']];
    $added = ['idToken' => ['idToken' => 'ADD', 'type' => 'ISO14443'], 'idTokenInfo' => ['status' => 'Blocked']];
    $handler->send(['versionNumber' => 1, 'updateType' => 'Full', 'localAuthorizationList' => [$kept, $replaced]]);

    $response = $handler->send(['versionNumber' => 4, 'updateType' => 'Differential', 'localAuthorizationList' => [
        ['idToken' => $replaced['idToken'], 'idTokenInfo' => ['status' => 'Expired']],
        $added,
        ['idToken' => $kept['idToken']],
    ]]);

    expect($response)->toBe(['status' => 'Accepted'])
        ->and($lists->version($chargerId))->toBe(4)
        ->and($lists->entries($chargerId))->toBe([
            ['idToken' => $replaced['idToken'], 'idTokenInfo' => ['status' => 'Expired']],
            $added,
        ]);
});

it('returns VersionMismatch without changing the list for stale differential updates', function (): void {
    [, $chargerId, $lists, $handler] = createLocalAuthorizationListHandler();
    $entry = ['idToken' => ['idToken' => 'TAG-1', 'type' => 'ISO14443'], 'idTokenInfo' => ['status' => 'Accepted']];
    $handler->send(['versionNumber' => 5, 'updateType' => 'Full', 'localAuthorizationList' => [$entry]]);

    expect($handler->send(['versionNumber' => 5, 'updateType' => 'Differential', 'localAuthorizationList' => []]))
        ->toBe(['status' => 'VersionMismatch'])
        ->and($lists->version($chargerId))->toBe(5)
        ->and($lists->entries($chargerId))->toBe([$entry]);
});

it('clears the list when a full update omits localAuthorizationList', function (): void {
    [, $chargerId, $lists, $handler] = createLocalAuthorizationListHandler();
    $entry = ['idToken' => ['idToken' => 'TAG-1', 'type' => 'ISO14443'], 'idTokenInfo' => ['status' => 'Accepted']];
    $handler->send(['versionNumber' => 1, 'updateType' => 'Full', 'localAuthorizationList' => [$entry]]);

    expect($handler->send(['versionNumber' => 2, 'updateType' => 'Full']))
        ->toBe(['status' => 'Accepted'])
        ->and($lists->version($chargerId))->toBe(2)
        ->and($lists->entries($chargerId))->toBe([]);
});

it('reports the installed version through GetLocalListVersion', function (): void {
    [, $chargerId, $lists, $handler] = createLocalAuthorizationListHandler();
    $handler->send(['versionNumber' => 9, 'updateType' => 'Full']);

    expect((new GetLocalListVersionRequestHandler($chargerId, $lists))->getVersion([]))
        ->toBe(['versionNumber' => 9]);
});

it('accepts an OCPP personal message without its optional language', function (): void {
    [, , , $handler] = createLocalAuthorizationListHandler();

    expect($handler->send(['versionNumber' => 1, 'updateType' => 'Full', 'localAuthorizationList' => [[
        'idToken' => ['idToken' => 'TAG', 'type' => 'ISO14443'],
        'idTokenInfo' => ['status' => 'Accepted', 'personalMessage' => ['format' => 'UTF8', 'content' => 'Welcome']],
    ]]]))->toBe(['status' => 'Accepted']);
});

it('returns FormationViolation for malformed SendLocalList payloads', function (array $payload): void {
    [, , , $handler] = createLocalAuthorizationListHandler();

    expect(fn () => $handler->send($payload))
        ->toThrow(OcppCallErrorException::class, 'SendLocalList requires a valid version, update type and authorization list.');
})->with([
    'version must be positive' => [['versionNumber' => 0, 'updateType' => 'Full']],
    'local authorization list must be an array' => [['versionNumber' => 1, 'updateType' => 'Full', 'localAuthorizationList' => null]],
    'update type must be supported' => [['versionNumber' => 1, 'updateType' => 'Replace']],
    'list entries need idTokenInfo for full update' => [['versionNumber' => 1, 'updateType' => 'Full', 'localAuthorizationList' => [['idToken' => ['idToken' => 'TAG', 'type' => 'ISO14443']]]]],
    'duplicate tokens are invalid' => [['versionNumber' => 1, 'updateType' => 'Full', 'localAuthorizationList' => [
        ['idToken' => ['idToken' => 'TAG', 'type' => 'ISO14443'], 'idTokenInfo' => ['status' => 'Accepted']],
        ['idToken' => ['idToken' => 'TAG', 'type' => 'ISO14443'], 'idTokenInfo' => ['status' => 'Blocked']],
    ]]],
]);
