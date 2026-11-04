# Charger Lab

A standalone local emulator for developer testing against OCPP 2.0.1 CSMS implementations. The current slice supports BootNotification and Authorize exchanges and configurable outlet topology. This is a developer testing aid, not a certification tool.

## Run it

```sh
docker compose up --build
```

Open [http://localhost:8080](http://localhost:8080). The app starts with one charger configured to connect to the bundled fake CSMS. Set the workspace CSMS URL once to use it for chargers that inherit the default, or give an individual card an override. Add as many charger cards as you need; each card has its own station identity, status, outlet map, RFID token form, and communication log. Press **Connect & Boot** to send a BootNotification; the Accepted response and both OCPP-J frames appear in that charger's log. The RFID form sends an Authorize request for that charger.

Charger settings, the workspace CSMS default, and per-charger exchange logs persist in the `charger-data` Docker volume. Enter the OCPP-J endpoint URL without a station identity; the emulator appends each charger's percent-encoded identity when connecting. The app supports `ws://` and `wss://` endpoints that negotiate the `ocpp2.0.1` subprotocol. Existing databases migrate in place, preserving effective connection URLs; different endpoints become per-charger overrides.

Set the outlet count from 1 to 16. **Independent EVSEs** assign one EVSE ID per outlet, each with connector ID 1. **Shared EVSE** assigns all outlets to EVSE 1 with a distinct connector ID for each outlet. Existing databases migrate to one independent outlet by default.

The GUI is static HTML/CSS/JavaScript. PHP serves the JSON API and queues OCPP actions in SQLite. A long-running PHP worker owns one open WebSocket session per charger and reuses it for later actions. It sends Heartbeat at the interval returned by BootNotification and responds with `NotImplemented` to CSMS-initiated calls the emulator does not support. The worker updates the displayed session status, recovers interrupted BootNotification requests after restart, and closes a station session when its card is removed. No Composer packages or external font assets are required.

## Tests

Run unit tests with PHP 8.5:

```sh
php tests/BootNotificationExchangeTest.php
php tests/OcppClientCallErrorTest.php
php tests/OcppConnectionUrlTest.php
php tests/OcppCommandQueueTest.php
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
