# Charger Lab

A standalone local emulator for developer testing against OCPP 2.0.1 CSMS implementations. The current slice supports BootNotification, StatusNotification, CSMS ChangeAvailability, RFID Authorize, and a plug-first TransactionEvent lifecycle with configurable outlet topology. This is a developer testing aid, not a certification tool.

## Run it

```sh
docker compose up --build
```

Open [http://localhost:8080](http://localhost:8080). The app starts with one charger configured to connect to the bundled fake CSMS. Use **Setup** to edit the workspace CSMS URL and each charger's identity, password, endpoint, and outlets. **Test CSMS connection** checks the saved charger's WebSocket, TLS, Basic Authentication, and OCPP subprotocol without sending BootNotification. The **Test bench** keeps the charger cards, Connect & Boot, RFID controls, and communication logs together. Add as many charger cards as you need.

Charger settings, the workspace CSMS default, and per-charger exchange logs persist in the `charger-data` Docker volume. Enter the OCPP-J endpoint URL without a station identity; the emulator appends each charger's percent-encoded identity when connecting. The app supports `ws://` and `wss://` endpoints that negotiate the `ocpp2.0.1` subprotocol. Existing databases migrate in place, preserving effective connection URLs; different endpoints become per-charger overrides.

### Connecting to another CSMS in Docker

The web app and station worker run in containers. `localhost` therefore refers to the current container, not another service or the host. For a CSMS in a separate Compose project, put both emulator containers on its Docker network with the optional overlay:

```sh
CSMS_DOCKER_NETWORK=allmeter-mono_default docker compose -f compose.yaml -f compose.external-csms.yaml up --build -d
```

Use the CSMS service name and its container port in **Setup** (for AllMeter, `wss://ocpp-gateway:8080/ocpp`). For a CSMS running directly on the host, use `host.docker.internal` and make sure its port is reachable from containers. TLS verification stays enabled: the certificate must match the URL host and the issuing CA must be trusted by the PHP containers.

Set the outlet count from 1 to 16. **Independent EVSEs** assign one EVSE ID per outlet, each with connector ID 1. **Shared EVSE** assigns all outlets to EVSE 1 with a distinct connector ID for each outlet. Existing databases migrate to one independent outlet by default.

The GUI is static HTML/CSS/JavaScript. Plug in a vehicle on a connected outlet to report `Occupied` and send `TransactionEvent Started` with `CablePluggedIn` and `EVConnected`; when `SampledDataCtrlr.TxStartedMeasurands` includes `Energy.Active.Import.Register`, it adds a `Transaction.Begin` energy register sample. Present an RFID token to send `Authorize`, then `TransactionEvent Updated` events for `Authorized` and `ChargingStateChanged`; unplug to end with `EVCommunicationLost` and `Available` status. When `SampledDataCtrlr.TxEndedMeasurands` includes `Energy.Active.Import.Register`, the `Ended` event also includes a `Transaction.End` sample. The station accepts CSMS `RequestStartTransaction` calls for an available outlet, reports the `Started` event with `RemoteStart` and the matching `remoteStartId`, then waits for the developer to connect a simulated vehicle. While waiting, the OCPP connector status is `Occupied` as required by the remote-start-first flow, but the UI shows `WAITING FOR VEHICLE`; it does not report `EVConnected` or `Charging` before the simulated plug-in. When configured, the `Started` event still includes the initial cumulative meter reading as `Transaction.Begin`; that boundary reading does not indicate energy transfer. `TxCtrlr.EVConnectionTimeOut` controls this wait (default 30 seconds); on expiry, the station reports the connector as `Available` and ends the transaction with stopped reason `Timeout`. A `RequestStopTransaction` while still waiting for a vehicle ends the transaction immediately with stopped reason `Remote` and reports the outlet as `Available`; after a vehicle connects, it reports an `Updated` event with `RemoteStop` and ends the transaction when the simulated vehicle is unplugged. It answers `GetTransactionStatus` from persisted transaction state; when the request omits `transactionId`, it also omits `ongoingIndicator` as required by OCPP. Transaction events are persisted in an ordered SQLite outbox and use the same OCPP message ID when retried. When the station detects a lost connection, all still pending transaction events are marked `offline: true` and replayed in FIFO order after reconnection; delivered events are left unchanged. `OCPPCommCtrlr.MessageAttemptsTransactionEvent` and `MessageAttemptIntervalTransactionEvent` control retry attempts and backoff; defaults are 3 attempts and 60 seconds, so retries wait 60 then 120 seconds. `GetTransactionStatus.messagesInQueue` is true while any transaction event is waiting to be delivered. After a failed connection, the station reconnects automatically using `RetryBackOffWaitMinimum`, `RetryBackOffRandomRange`, and `RetryBackOffRepeatTimes`; defaults are 5 seconds minimum, 5 seconds random range, and 3 exponential doublings. After a worker restart, previously accepted stations reconnect without another BootNotification. The accepted heartbeat interval is persisted and used after reconnect. Starting a new locally authorized transaction while offline is not supported yet. The simulated meter adds a deterministic 1 kWh to that outlet's cumulative Wh register when a session ends. Outlet state, meter registers, and unique transaction IDs persist in SQLite. It sends Heartbeat at the accepted BootNotification interval and retries `Pending` or `Rejected` BootNotification responses at the saved interval, including after a worker restart. Until BootNotification is Accepted, it sends no Heartbeat, status, authorization, or simulated vehicle events, and the UI rejects those actions. Heartbeat uses the required empty JSON object payload. Unsupported CSMS-initiated actions receive `NotImplemented`; while BootNotification is `Rejected`, incoming CSMS calls receive `SecurityError`.

The Device Model currently supports `GetVariables` and `SetVariables` for station, EVSE, and connector availability, `AuthCtrlr.AuthorizeRemoteStart` (false; remote start authorization is reported in the `TransactionEvent` response instead of a separate `Authorize` exchange), writable `TxCtrlr.EVConnectionTimeOut`, `AlignedDataCtrlr.Measurands`, and `SampledDataCtrlr.TxStartedMeasurands`, `TxEndedInterval`, `TxEndedMeasurands`, `TxUpdatedInterval`, and `TxUpdatedMeasurands` settings. `TxStartedMeasurands` controls the optional beginning sample. `TxEndedMeasurands` and `TxEndedInterval` control samples collected during the transaction and included in its final `Ended` event; samples persist across worker restarts. Setting `TxUpdatedInterval` to a positive number of seconds and `TxUpdatedMeasurands` to `Energy.Active.Import.Register` enables periodic `TransactionEvent Updated` messages with `MeterValuePeriodic` and `Sample.Periodic`. Each simulated register sample advances that outlet's cumulative meter by 100 Wh. An interval of `0` or an empty measurand disables the corresponding samples. Unknown components, unknown variables, unsupported attribute types, and attempts to write read-only values receive their OCPP result statuses. This remains a deliberately partial Core Profile slice: other sampled measurands and Core messages are not implemented. OCPP 2.0.1 flows are checked incrementally against its schemas and use cases; this does not provide certification or full-profile conformance.

CSMS `ChangeAvailability` requests support station-wide, EVSE, and connector targets. Availability settings persist in SQLite, and inoperative connectors report `Unavailable` and reject new local plug-in or remote-start actions. A request targeting an EVSE with an active transaction responds `Scheduled`; its state change and corresponding connector `StatusNotification` messages are applied after the transaction ends. Connector-specific settings remain in force when a parent EVSE or station is restored to Operative.

Station-wide CSMS `Reset` requests support `Immediate` and `OnIdle`. `OnIdle` responds `Scheduled` while transactions are active, blocks new transactions, and resets after the active `Ended` events have been delivered. `Immediate` ends active transactions with stopped reason `ImmediateReset`. Both reset modes simulate a station reboot by reconnecting and sending BootNotification with reason `RemoteReset`. EVSE-specific resets are rejected because independent EVSE reset is not implemented.

CSMS `TriggerMessage` requests support `StatusNotification`, `Heartbeat`, `TransactionEvent`, and `MeterValues`. With no EVSE target, a status request reports every configured connector, a transaction request reports every active transaction, and a MeterValues request reports each configured EVSE; targets narrow the response. A heartbeat request sends the required empty payload and does not reset the periodic heartbeat timer. Transaction triggers send current state and configured updated measurands using the `Trigger` context. MeterValues triggers send the current cumulative `Energy.Active.Import.Register` when `AlignedDataCtrlr.Measurands` includes it; the default setting is that register, and an empty setting or target with no matching EVSE is rejected. Shared EVSE readings combine the simulated connector registers at EVSE level. A transaction trigger with no active transaction is rejected. Unsupported message types receive `NotImplemented`, and a request to trigger BootNotification after the station has already been accepted receives `Rejected`.

## Tests

Pest is a development-only dependency. Install it and run unit checks before integration checks:

```sh
composer install
composer test:unit
composer test:integration
```

`composer test` runs both groups in order. The integration tests start local TCP services, so run them in an environment that permits localhost sockets.

```sh
composer test
```
