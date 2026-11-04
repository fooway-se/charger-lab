<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\OcppCommandRepository;
use RuntimeException;
use Throwable;

/**
 * Owns open OCPP connections and executes queued actions outside HTTP requests.
 */
final class StationSessionWorker
{
    /**
     * @var array<int, array{connection:WebSocketConnection, url:string}>
     */
    private array $sessions = [];

    public function __construct(
        private readonly ChargerRepository $chargers,
        private readonly ExchangeRepository $logs,
        private readonly OcppCommandRepository $commands,
    ) {
    }

    public function run(int $pollMicroseconds = 20_000): never
    {
        $this->commands->recoverInterrupted();
        $this->chargers->resetConnectionStatuses();
        while (true) {
            if (!$this->runOne()) {
                usleep($pollMicroseconds);
            }
        }
    }

    public function runOne(): bool
    {
        $command = $this->commands->claimNext();
        if ($command === null) {
            return false;
        }

        $chargerId = $command['charger_id'];
        $hadSession = isset($this->sessions[$chargerId]);
        try {
            $charger = $this->chargers->get($chargerId);
            if ($charger === null) {
                throw new RuntimeException('The requested charger no longer exists.');
            }

            if ($command['action'] === 'Disconnect') {
                unset($this->sessions[$chargerId]);
                $this->chargers->setConnectionStatus($chargerId, 'Not connected');
                $this->commands->complete($command['id'], ['status' => 'Disconnected']);

                return true;
            }

            $connectionUrl = OcppConnectionUrl::fromEndpoint($charger['csms_url'], $charger['station_identity']);
            if (!isset($this->sessions[$chargerId]) || $this->sessions[$chargerId]['url'] !== $connectionUrl) {
                unset($this->sessions[$chargerId]);
                if ($command['action'] !== 'BootNotification') {
                    throw new RuntimeException('Connect the charger with BootNotification before sending other actions.');
                }

                $this->sessions[$chargerId] = [
                    'connection' => new WebSocketConnection($connectionUrl),
                    'url' => $connectionUrl,
                ];
            }

            $connection = $this->sessions[$chargerId]['connection'];
            $client = new OcppClient(
                $connection,
                function (string $direction, string $message) use ($chargerId, $command): void {
                    $this->logs->record($chargerId, $direction, $command['action'], $message);
                },
            );
            $result = $client->request($command['action'], $command['payload']);
            if ($command['action'] === 'BootNotification') {
                $this->chargers->setBootStatus($chargerId, (string) ($result['status'] ?? 'Unknown response'));
            }

            $this->chargers->setConnectionStatus($chargerId, 'Connected');
            $this->commands->complete($command['id'], $result);
        } catch (Throwable $exception) {
            unset($this->sessions[$chargerId]);
            $this->logs->record($chargerId, 'system', $command['action'], $exception->getMessage());
            if ($command['action'] === 'BootNotification') {
                $this->chargers->setBootStatus($chargerId, 'Connection failed');
            }
            if ($command['action'] === 'BootNotification' || $hadSession) {
                $this->chargers->setConnectionStatus($chargerId, 'Connection failed');
            }

            $this->commands->fail($command['id'], $exception->getMessage());
        }

        return true;
    }
}
