# Charger Lab

A standalone local emulator for developer testing against OCPP 2.0.1 CSMS implementations. The current slice supports BootNotification, connector availability StatusNotification, RFID Authorize, and a plug-first TransactionEvent lifecycle with configurable outlet topology. This is a developer testing aid, not a certification tool.

## Run it

```sh
docker compose up --build
```

Open [http://localhost:8080](http://localhost:8080). The app starts with one charger configured to connect to the bundled fake CSMS. Set the workspace CSMS URL once to use it for chargers that inherit the default, or give an individual card an override. Add as many charger cards as you need; each card has its own station identity, status, outlet map, RFID token form, and communication log. Press **Connect & Boot** to send a BootNotification; the Accepted response and both OCPP-J frames appear in that charger's log. The RFID form sends an Authorize request for that charger.

Charger settings, the workspace CSMS default, and per-charger exchange logs persist in the `charger-data` Docker volume. Enter the OCPP-J endpoint URL without a station identity; the emulator appends each charger's percent-encoded identity when connecting. The app supports `ws://` and `wss://` endpoints that negotiate the `ocpp2.0.1` subprotocol. Existing databases migrate in place, preserving effective connection URLs; different endpoints become per-charger overrides.

Set the outlet count from 1 to 16. **Independent EVSEs** assign one EVSE ID per outlet, each with connector ID 1. **Shared EVSE** assigns all outlets to EVSE 1 with a distinct connector ID for each outlet. Existing databases migrate to one independent outlet by default.

The GUI is static HTML/CSS/JavaScript. Plug in a vehicle on a connected outlet to report `Occupied` and send `TransactionEvent Started` with `CablePluggedIn` and `EVConnected`; when `SampledDataCtrlr.TxStartedMeasurands` includes `Energy.Active.Import.Register`, it adds a `Transaction.Begin` energy register sample. Present an RFID token to send `Authorize`, then `TransactionEvent Updated` events for `Authorized` and `ChargingStateChanged`; unplug to end with `EVCommunicationLost` and `Available` status. When `SampledDataCtrlr.TxEndedMeasurands` includes `Energy.Active.Import.Register`, the `Ended` event also includes a `Transaction.End` sample. The station accepts CSMS `RequestStartTransaction` calls for an available outlet and reports a `Started` event with `RemoteStart` and the matching `remoteStartId`. On `RequestStopTransaction`, it reports an `Updated` event with `RemoteStop`, then ends the transaction with stopped reason `Remote` when the simulated vehicle is unplugged. It answers `GetTransactionStatus` from persisted transaction state; when the request omits `transactionId`, it also omits `ongoingIndicator` as required by OCPP. Transaction events are persisted in an ordered SQLite outbox and use the same OCPP message ID when retried. `OCPPCommCtrlr.MessageAttemptsTransactionEvent` and `MessageAttemptIntervalTransactionEvent` control retry attempts and backoff; defaults are 3 attempts and 60 seconds, so retries wait 60 then 120 seconds. `GetTransactionStatus.messagesInQueue` is true while any transaction event is waiting to be delivered. After a failed connection, the station reconnects automatically using `RetryBackOffWaitMinimum`, `RetryBackOffRandomRange`, and `RetryBackOffRepeatTimes`; defaults are 5 seconds minimum, 5 seconds random range, and 3 exponential doublings. After a worker restart, previously accepted stations reconnect without another BootNotification. The accepted heartbeat interval is persisted and used after reconnect. The simulated meter adds a deterministic 1 kWh to that outlet's cumulative Wh register when a session ends. Outlet state, meter registers, and unique transaction IDs persist in SQLite. It sends Heartbeat at the accepted BootNotification interval and retries `Pending` or `Rejected` BootNotification responses at the saved interval, including after a worker restart. Until BootNotification is Accepted, it sends no Heartbeat, status, authorization, or simulated vehicle events, and the UI rejects those actions. Heartbeat uses the required empty JSON object payload. Unsupported CSMS-initiated actions receive `NotImplemented`; while BootNotification is `Rejected`, incoming CSMS calls receive `SecurityError`.

The Device Model currently supports `GetVariables` and `SetVariables` for station, EVSE, and connector availability, `AuthCtrlr.AuthorizeRemoteStart` (false; remote start authorization is reported in the `TransactionEvent` response instead of a separate `Authorize` exchange), and writable `SampledDataCtrlr.TxStartedMeasurands`, `TxEndedInterval`, `TxEndedMeasurands`, `TxUpdatedInterval`, and `TxUpdatedMeasurands` settings. `TxStartedMeasurands` controls the optional beginning sample. `TxEndedMeasurands` and `TxEndedInterval` control samples collected during the transaction and included in its final `Ended` event; samples persist across worker restarts. Setting `TxUpdatedInterval` to a positive number of seconds and `TxUpdatedMeasurands` to `Energy.Active.Import.Register` enables periodic `TransactionEvent Updated` messages with `MeterValuePeriodic` and `Sample.Periodic`. Each simulated register sample advances that outlet's cumulative meter by 100 Wh. An interval of `0` or an empty measurand disables the corresponding samples. Unknown components, unknown variables, unsupported attribute types, and attempts to write read-only values receive their OCPP result statuses. This remains a deliberately partial Core Profile slice: other sampled measurands and Core messages are not implemented. OCPP 2.0.1 flows are checked incrementally against its schemas and use cases; this does not provide certification or full-profile conformance.

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
