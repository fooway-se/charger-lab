# OCPP 2.0.1 Core conformance notes

Charger Lab emulates the **Charging Station** side of OCPP 2.0.1 for developers testing CSMS implementations. Its target is the Core certification profile. It is not OCA-certified, and the repository's tests are not a substitute for OCTT or laboratory testing.

## Reference versions

These references were checked on 2026-11-16:

- OCPP 2.0.1 Edition 4 (2025-12-03) and the separate Errata 2026-09.
- Charging Station PICS 3.0.5, listed by OCA's OCTT Toolbox as the latest Charging Station PICS for OCPP 2.0.1.
- OCPP 2.0.1 Test Procedure & Test Plans v3.2.1 Final (2026-07-09).

OCA's updated Core scope includes boot, authorization, configuration, transactions, remote control, secure firmware updates, and Security Profile 2. Reservation, Local Authorization List Management, Advanced Device Management, and Advanced User Interface are optional profiles/features under the updated program.

The test procedure points to OCPP Specification Part 5 for mandatory and conditional test cases and Part 6 for their details. A PICS declares the implementation's supported features and settings; it does not, by itself, establish one fixed list of applicable cases. The table below is this repository's tracked case map, not a complete or authoritative Core test inventory. Its original claim of 102 mandatory cases did not match the IDs in the table, and the table did not include an Authorization row. Authorization coverage is present in the application, but its applicable OCTT cases still need to be reconciled against the current PICS.

## Repository case map

| Area | Case IDs tracked in this repository | Local implementation audit |
| --- | --- | --- |
| Security and authentication | `TC_A_01`, `TC_A_04`, `TC_A_05`, `TC_A_06`, `TC_A_09`, `TC_A_10`, `TC_A_22` | TLS 1.2 minimum and TLS 1.3 support, password validation/rotation, reconnect, log redaction, and security-profile downgrade rejection have unit and integration coverage. OCTT verification remains. |
| Boot and offline status | `TC_B_01`, `TC_B_02`, `TC_B_03`, `TC_B_30`, `TC_B_51`, `TC_B_52` | Accepted, Pending, and Rejected boot flows and reconnect are implemented. OfflineThreshold is persisted; integration tests cover full resync above the threshold and changed-only reporting below it. OCTT verification remains. |
| Device Model read/write and inventory | `TC_B_06`, `TC_B_07`, `TC_B_09`, `TC_B_10`, `TC_B_11`, `TC_B_12`, `TC_B_13`, `TC_B_32`, `TC_B_33`, `TC_B_34`, `TC_B_35`, `TC_B_36`, `TC_B_37`, `TC_B_39`, `TC_B_53` | The implemented Core controller variables, inventory properties, values/limits, runtime security/network values, and persistent writable settings have unit coverage. OCTT verification remains. |
| Reset and connection profiles | `TC_B_20`, `TC_B_21`, `TC_B_22`, `TC_B_23`, `TC_B_43`, `TC_B_45`, `TC_B_46`, `TC_B_50`, `TC_B_57` | Network-priority writes reject missing slots and prohibited downgrades; security upgrades require a configured trust root. Reset migration, profile fallback, and WSS reconnect using an OCPP-installed CSMS root have integration coverage. OCTT verification remains. |
| Authorization | Not mapped to case IDs in this checklist | The test bench supports RFID authorization and authorization-first sessions. Reconcile the applicable authorization cases against the current PICS and OCTT before making a conformance claim. |
| Transaction reliability and status | `TC_E_16`, `TC_E_28`, `TC_E_29`, `TC_E_30`, `TC_E_32`, `TC_E_33`, `TC_E_34`, `TC_E_40`, `TC_E_41`, `TC_E_42`, `TC_E_50`, `TC_E_51` | The ordered outbox replays events with the same uniqueId on retry. Unit and integration coverage exercises offline flags, retry timing, retry exhaustion, FIFO blocking, queue status, and non-Accepted TransactionEvent IdToken responses under both StopTxOnInvalidId settings. OCTT verification remains. |
| Remote control | `TC_F_04`, `TC_F_08`, `TC_F_09`, `TC_F_20` | Remote-start timeout, remote stop, and TriggerMessage Heartbeat behavior have CSMS integration coverage. OCTT verification remains. |
| Connector state and availability | `TC_G_01`, `TC_G_02`, `TC_G_03`, `TC_G_04`, `TC_G_05`, `TC_G_06`, `TC_G_07`, `TC_G_08`, `TC_G_09`, `TC_G_10`, `TC_G_11`, `TC_G_12`, `TC_G_13`, `TC_G_14`, `TC_G_15`, `TC_G_16`, `TC_G_17`, `TC_G_18`, `TC_G_19`, `TC_G_21` | Unit and integration tests cover connector status, station/EVSE/connector availability, scheduled changes during active transactions, persistence, and reset behavior. OCTT verification remains. |
| Meter values | `TC_J_01`, `TC_J_02`, `TC_J_03`, `TC_J_07`, `TC_J_09`, `TC_J_10` | Integration tests cover clock-aligned MeterValues, MeterValueClock TransactionEvents, and Started, Updated, and Ended samples. Measurand declarations and full interval behavior remain for OCTT verification. |
| Secure firmware and certificate management | `TC_L_01`, `TC_L_02`, `TC_L_03`, `TC_L_05`, `TC_L_06`, `TC_L_07`, `TC_L_08`, `TC_L_12`, `TC_L_13`, `TC_L_18`, `TC_M_01`, `TC_M_02`, `TC_M_07`, `TC_M_12`, `TC_M_13`, `TC_M_17`, `TC_M_18`, `TC_M_19`, `TC_M_20`, `TC_M_22` | Unit and integration tests cover signed firmware downloads, verification, scheduling, failure paths, reconnect, and simulated certificate install/query/delete. Firmware packages are verified and stored but never executed. OCTT verification remains. |
| Diagnostics, customer information, and custom data | `TC_N_25`, `TC_N_26`, `TC_N_27`, `TC_N_28`, `TC_N_30`, `TC_N_31`, `TC_N_32`, `TC_N_35`, `TC_N_36`, `TC_P_01`, `TC_P_03` | Unit and integration tests cover diagnostics/security log uploads, retries, cancellation, IdToken redaction, CustomerInformation behavior, and CustomData validation. Optional customerIdentifier and customerCertificate selectors are unsupported. OCTT verification remains. |

## Charging Station software-stack test controls

The OCA test procedure requires a software stack under test to let the tester perform station actions. Charger Lab provides UI controls for:

- Plugging in and unplugging every configured virtual connector.
- Authorizing and deauthorizing with at least two different IdTokens.
- Suspending and resuming charging from the EV and EVSE sides.
- Cold booting the station software.
- Sending meter values during a charging session.
- Exercising firmware update notifications, security checks, and reconnect.
- Uploading diagnostic and security logs.

The OCA-specific OCTT API is distributed separately for certification testing. The test bench is a UI for development and testing; it does not produce an OCTT result.

## Verification status

- `composer test` passed on 2026-11-16: 223 unit tests and 84 integration tests.
- No OCTT run or laboratory certification has been performed for this emulator.
- The case map above is not exhaustive. In particular, authorization case applicability still needs to be reconciled against the current PICS and OCTT before any conformance statement.

## References

- [OCA OCPP 2.0.1 certification scope](https://openchargealliance.org/certificationocpp/certification-ocpp-2-0-1/)
- [OCA updated OCPP 2.0.1 certification program](https://openchargealliance.org/updated-certification-program-2-0-1/)
- [OCA OCPP downloads: Edition 4 and Errata 2026-09](https://openchargealliance.org/my-oca/ocpp/)
- [OCA OCTT Toolbox and current PICS versions](https://toolbox.octt.openchargealliance.org/)
- [OCPP 2.0.1 Test Procedure & Test Plans v3.2.1 Final](https://openchargealliance.org/wp-content/uploads/2026/07/02.-Test-Procedure-Test-Plans_OCPP2.0.1_v3.2.1_final.pdf)
- [OCPP 2.0.1 Charging Station PICS v3.0.5](https://openchargealliance.org/wp-content/uploads/2026/10/OCPP-2.0.1-PICS-CS-3.0.5.xlsx)
