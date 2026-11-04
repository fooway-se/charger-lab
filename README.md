# Charger Lab

A standalone local emulator for developer testing against OCPP 2.0.1 CSMS implementations. The current slice supports BootNotification and Authorize exchanges and configurable outlet topology. This is a developer testing aid, not a certification tool.

## Run it

```sh
docker compose up --build
```

Open [http://localhost:8080](http://localhost:8080). The app starts with one charger configured to connect to the bundled fake CSMS. Press **Connect & Boot** to send a BootNotification; the Accepted response and both OCPP-J frames appear in the local log. The RFID form sends an Authorize request.

Charger settings and the exchange log persist in the `charger-data` Docker volume. To use a different CSMS, edit the WebSocket URL in the charger card. The app supports `ws://` and `wss://` endpoints that negotiate the `ocpp2.0.1` subprotocol.

Set the outlet count from 1 to 16. **Independent EVSEs** assign one EVSE ID per outlet, each with connector ID 1. **Shared EVSE** assigns all outlets to EVSE 1 with a distinct connector ID for each outlet. Existing databases migrate to one independent outlet by default.

The GUI is static HTML/CSS/JavaScript. PHP serves the JSON API, connects to the CSMS with native streams, and stores state in SQLite. No Composer packages or external font assets are required.

## Tests

Run unit tests with PHP 8.5:

```sh
php tests/BootNotificationExchangeTest.php
php tests/OcppClientCallErrorTest.php
php tests/StoragePersistenceTest.php
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
