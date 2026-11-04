# Charger Lab

A standalone local emulator for developer testing against OCPP 2.0.1 CSMS implementations. The current slice supports BootNotification, connector availability StatusNotification, RFID Authorize, and a plug-first TransactionEvent lifecycle with configurable outlet topology. This is a developer testing aid, not a certification tool.

## Run it

```sh
docker compose up --build
```

Open [http://localhost:8080](http://localhost:8080). The app starts with one charger configured to connect to the bundled fake CSMS. Set the workspace CSMS URL once to use it for chargers that inherit the default, or give an individual card an override. Add as many charger cards as you need; each card has its own station identity, status, outlet map, RFID token form, and communication log. Press **Connect & Boot** to send a BootNotification; the Accepted response and both OCPP-J frames appear in that charger's log. The RFID form sends an Authorize request for that charger.

Charger settings, the workspace CSMS default, and per-charger exchange logs persist in the `charger-data` Docker volume. Enter the OCPP-J endpoint URL without a station identity; the emulator appends each charger's percent-encoded identity when connecting. The app supports `ws://` and `wss://` endpoints that negotiate the `ocpp2.0.1` subprotocol. Existing databases migrate in place, preserving effective connection URLs; different endpoints become per-charger overrides.

Set the outlet count from 1 to 16. **Independent EVSEs** assign one EVSE ID per outlet, each with connector ID 1. **Shared EVSE** assigns all outlets to EVSE 1 with a distinct connector ID for each outlet. Existing databases migrate to one independent outlet by default.

The GUI is static HTML/CSS/JavaScript. Plug in a vehicle on a connected outlet to report `Occupied` and send `TransactionEvent Started` with `CablePluggedIn` and `EVConnected`; present an RFID token to send `Authorize`, then `TransactionEvent Updated` events for `Authorized` and `ChargingStateChanged`; unplug to end with `EVDisconnected` and report `Available`. The worker persists outlet state and unique transaction IDs in SQLite. It sends Heartbeat at the accepted BootNotification interval and keeps the retry deadline for `Pending` or `Rejected` boot responses across worker restarts. Until BootNotification is Accepted, it sends no Heartbeat, status, authorization, or simulated vehicle events, and the UI rejects those actions. Heartbeat uses the required empty JSON object payload. CSMS-initiated actions the emulator does not implement receive `NotImplemented`.

This remains a deliberately partial Core Profile slice: meter values, device model configuration calls, remote start/stop, and other Core messages are not implemented. OCPP 2.0.1 flows are checked incrementally against its schemas and use cases; this does not provide certification or full-profile conformance.

## Tests

Run unit tests with PHP 8.5:

```sh
php tests/BootNotificationExchangeTest.php
php tests/OcppClientCallErrorTest.php
php tests/OcppConnectionUrlTest.php
php tests/OcppCommandQueueTest.php
php tests/OcppCommandMigrationTest.php
php tests/StoragePersistenceTest.php
php tests/MultiChargerPersistenceTest.php
php tests/MultiChargerMigrationTest.php
php tests/WorkspaceCsmsSettingsTest.php
php tests/HttpRoutingTest.php
php tests/StationControllerValidationTest.php
php tests/FrontendSeparationTest.php
php tests/OutletMapTest.php
php tests/OutletTopologyMigrationTest.php
```

The WebSocket and local UI integration tests start local TCP services, so run them in an environment that permits localhost sockets:

```sh
php tests/WebSocketBootExchangeIntegrationTest.php
php tests/LocalUiBootFlowIntegrationTest.php
```
