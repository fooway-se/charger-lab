# Charger Emulator

A standalone, dependency-free PHP emulator for developer testing against OCPP 2.0.1 CSMS implementations. It is a testing aid, not a certification tool.

## Current slice

The protocol client can send an OCPP-J CALL and return a correlated CALLRESULT. The first test exercises a `BootNotification` exchange with a fake CSMS.

Run the test with PHP 8.5:

```sh
php tests/BootNotificationExchangeTest.php
```
