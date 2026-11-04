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
     * @var array<int, array{connection:WebSocketConnection,url:string,heartbeat_interval:int,next_heartbeat_at:?float}>
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
            if (!$this->runOne() && !$this->pollSessions()) {
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
                    'heartbeat_interval' => 300,
                    'next_heartbeat_at' => null,
                ];
            }

            $client = $this->createClient($chargerId);
            $result = $client->request($command['action'], $command['payload']);
            if ($command['action'] === 'BootNotification') {
                $this->chargers->setBootStatus($chargerId, (string) ($result['status'] ?? 'Unknown response'));
                $interval = $result['interval'] ?? 300;
                $interval = is_int($interval) && $interval > 0 ? $interval : 300;
                $this->sessions[$chargerId]['heartbeat_interval'] = $interval;
                $this->sessions[$chargerId]['next_heartbeat_at'] = microtime(true) + $interval;
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

    private function pollSessions(): bool
    {
        foreach ($this->sessions as $chargerId => $session) {
            try {
                if ($session['next_heartbeat_at'] !== null && microtime(true) >= $session['next_heartbeat_at']) {
                    $result = $this->createClient($chargerId)->request('Heartbeat', []);
                    $interval = $this->sessions[$chargerId]['heartbeat_interval'];
                    $this->sessions[$chargerId]['next_heartbeat_at'] = microtime(true) + $interval;
                    $this->chargers->setConnectionStatus($chargerId, 'Connected');
                    if (!isset($result['currentTime']) || !is_string($result['currentTime'])) {
                        throw new OcppProtocolException('The CSMS Heartbeat response did not include currentTime.');
                    }

                    return true;
                }

                $message = $session['connection']->receiveAvailable();
                if ($message !== null) {
                    $this->createClient($chargerId)->answerIncomingCallMessage($message);

                    return true;
                }
            } catch (Throwable $exception) {
                unset($this->sessions[$chargerId]);
                $this->logs->record($chargerId, 'system', 'Session', $exception->getMessage());
                $this->chargers->setConnectionStatus($chargerId, 'Connection failed');

                return true;
            }
        }

        return false;
    }

    private function createClient(int $chargerId): OcppClient
    {
        return new OcppClient(
            $this->sessions[$chargerId]['connection'],
            function (string $direction, string $action, string $message) use ($chargerId): void {
                $this->logs->record($chargerId, $direction, $action, $message);
            },
        );
    }
}
