# OCPP 2.0.1 Core compliance work

This emulator represents the **Charging Station** side of OCPP 2.0.1 and is used to exercise CSMS implementations. The target is the Core certification profile in OCPP 2.0.1 Edition 4, with optional profiles and optional feature IDs excluded from new work unless a feature is already needed by a mandatory Core test.

This checklist is an implementation plan, not a claim of OCA certification. A passing repository test suite does not substitute for running the current OCTT/PICS test plan.

## Compliance basis

- OCPP 2.0.1 Edition 4, including Errata 2026-09.
- Charging Station PICS 3.0.5, which selects Core and excludes optional profiles/features from this target.
- OCPP 2.0.1 Test Procedure & Test Plans v3.2.1 (2026-07-09).
- Core now includes basic boot, authorization, configuration, transactions, remote control, secure firmware updates, and Security Profile 2.
- The software-stack test setup requires simulated/emulated station actions for plugging/unplugging, authorization with at least two tokens, suspending charging, cold booting, meter values during a transaction, firmware update and reconnect. The OCTT-specific API is provided separately by OCA, so repository integration tests are useful preparation but are not an OCTT result.

## Core test inventory

The current CS PICS lists the following 102 tests as mandatory for Core. Conditional tests tied only to optional feature IDs are excluded. A test listed here is not considered complete until its protocol behavior and its station-side test controls have been verified.

| Area | Mandatory Core test cases | Current audit |
| --- | --- | --- |
| Security and authentication | `TC_A_01`, `TC_A_04`, `TC_A_05`, `TC_A_06`, `TC_A_09`, `TC_A_10`, `TC_A_22` | `TC_A_09`/`TC_A_10` now have unit and end-to-end coverage, including password validation, rotation, reconnect, and log redaction. TLS minimum-version and downgrade behavior still need PICS verification. |
| Boot and offline status | `TC_B_01`, `TC_B_02`, `TC_B_03`, `TC_B_30`, `TC_B_51`, `TC_B_52` | Boot accepted/pending/rejected and reconnect are implemented. `OfflineThreshold` is exposed and persisted; offline connector changes survive worker restarts. Integration tests cover full resync above threshold and changed-only reporting below it (`TC_B_51`/`TC_B_52`). OCTT verification remains. |
| Device Model read/write and inventory | `TC_B_06`, `TC_B_07`, `TC_B_09`, `TC_B_10`, `TC_B_11`, `TC_B_12`, `TC_B_13`, `TC_B_32`, `TC_B_33`, `TC_B_34`, `TC_B_35`, `TC_B_36`, `TC_B_37`, `TC_B_39`, `TC_B_53` | Added mandatory Core controller variables, full-inventory physical properties, values/limits, runtime security/network values, and persistent writable settings. Repository unit coverage passes; the focused remote-stop integration rerun also passes. OCTT remains outstanding. |
| Reset and connection profiles | `TC_B_20`, `TC_B_21`, `TC_B_22`, `TC_B_23`, `TC_B_43`, `TC_B_45`, `TC_B_46`, `TC_B_50`, `TC_B_57` | Network-priority writes now reject missing slots and prohibited downgrades/profile upgrades without a trust root. OnIdle migration, failed-profile fallback, and WSS reconnect using an OCPP-installed CSMS root have integration coverage. OCTT verification remains. |
| Transaction reliability and status | `TC_E_28`, `TC_E_29`, `TC_E_30`, `TC_E_32`, `TC_E_33`, `TC_E_34`, `TC_E_40`, `TC_E_41`, `TC_E_42`, `TC_E_50`, `TC_E_51` | Outbox replay is sequence ordered and persists the same uniqueId across retry. GetTransactionStatus reports ongoing and queued state for active, ended, unknown, and unfiltered requests. On reconnect, already-arrived CSMS calls are answered before queued transaction replay. Unit and integration coverage verifies offline flags, interval scheduling, CALLERROR retries, retry exhaustion, and stopping after success. OCTT verification remains. |
| Remote control | `TC_F_04`, `TC_F_08`, `TC_F_09`, `TC_F_20` | CSMS-requested remote starts now end unconnected transactions with `EVConnectTimeout`; integration verifies the required Ended/Timeout event. The test CSMS verifies unknown remote-stop rejection and successful remote stop of an active session. TriggerMessage Heartbeat is accepted and the Heartbeat follows the response. OCTT verification remains. |
| Connector state and availability | `TC_G_01`, `TC_G_02`, `TC_G_03`, `TC_G_04`, `TC_G_05`, `TC_G_06`, `TC_G_07`, `TC_G_08`, `TC_G_09`, `TC_G_10`, `TC_G_11`, `TC_G_12`, `TC_G_13`, `TC_G_14`, `TC_G_15`, `TC_G_16`, `TC_G_17`, `TC_G_18`, `TC_G_19`, `TC_G_21` | Plug/unplug status and station/EVSE/connector availability are implemented. Full mandatory scenarios, including persistence and active transactions, remain to be checked against Edition 4. |
| Meter values | `TC_J_01`, `TC_J_02`, `TC_J_03`, `TC_J_07`, `TC_J_09`, `TC_J_10` | Clock-aligned and transaction samples exist for the supported measurands. The declared measurands and all boundary/event cases need PICS verification. |
| Secure firmware and certificate management | `TC_L_01`, `TC_L_02`, `TC_L_03`, `TC_L_05`, `TC_L_06`, `TC_L_07`, `TC_L_08`, `TC_L_18`, `TC_M_01`, `TC_M_02`, `TC_M_07`, `TC_M_12`, `TC_M_13`, `TC_M_17`, `TC_M_18`, `TC_M_19`, `TC_M_20`, `TC_M_22` | Signed firmware update and simulated CA certificate management exist. Failure, persistence, trust, and deletion behavior need complete PICS-level verification. |
| Diagnostics, customer information, and custom data | `TC_N_25`, `TC_N_26`, `TC_N_32`, `TC_N_35`, `TC_P_01`, `TC_P_03` | Unknown DataTransfer rejection exists. Log retrieval, customer-information clearing, and complete CustomData handling are missing. |

## Implementation sequence

Each item is a separate TDD step and gets its own commit after its focused tests pass.

1. [x] Implement `SecurityCtrlr.BasicAuthPassword` set, validation, persistence, and authenticated reconnect (`TC_A_09`, `TC_A_10`).
2. [x] Preserve changed connector state across disconnects and implement `OCPPCommCtrlr.OfflineThreshold` (`TC_B_51`, `TC_B_52`). The emulator default is 300 seconds; offline plug/unplug simulation queues TransactionEvents for reconnect delivery. The exact ongoing-transaction meter/update requirements in `TC_E_40` remain in step 5.
3. [x] Complete mandatory Core Device Model components/variables and inventory/report behavior (`TC_B_06`–`TC_B_53`). Added the mandatory Core variable inventory, including ClockCtrlr and DeviceDataCtrlr, security/network runtime values, transaction/authentication settings, and full-inventory connector/EVSE properties. Read/write settings are persisted and covered independently for aligned and sampled data. Unit tests pass; one full-suite remote-stop integration timeout passed on isolated rerun. OCTT verification remains.
4. [x] Complete Core network profile rejection and migration including certificate-root changes (`TC_B_43`, `TC_B_45`, `TC_B_46`, `TC_B_50`, `TC_B_57`). Network priority rejects missing configuration slots and security downgrades; security upgrades require a configured trust root. After reset the station uses the selected profile, trusts OCPP-installed CSMS roots together with workspace trust roots, and reports the active slot. Integration tests cover same-CSMS fallback and WSS migration to a new CSMS root. OCTT verification remains.
5. [x] Close transaction outbox, offline, retry timing, and CallError cases (`TC_E_28`–`TC_E_51`). Added a CSMS reconnect scenario that requests transaction status before receiving the queued offline event, verifies FIFO replay and its `offline` flag, then rejects another event once with `InternalError` and confirms same-uniqueId retry and success on the same connection. Unit tests cover active/ended/unknown queue status, configured maximum attempts, backoff scheduling, and FIFO blocking. OCTT verification remains.
6. [x] Verify required remote start/stop and Heartbeat trigger behavior (`TC_F_04`, `TC_F_08`, `TC_F_09`, `TC_F_20`). Fixed the remote-start cable timeout to report `EVConnectTimeout`; the CSMS integration verifies the `Ended` event with `stoppedReason: Timeout`, rejects an unknown `RequestStopTransaction`, exercises accepted remote stop, and confirms TriggerMessage Heartbeat response-before-request ordering. OCTT verification remains.
7. Verify connector status and availability behavior across all required targets and reboot states (`TC_G_01`–`TC_G_21`).
8. Complete mandatory meter measurands and Started/Updated/Ended/clock-aligned cases (`TC_J_01`–`TC_J_10`).
9. Complete secure firmware update and certificate management edge cases (`TC_L_01`–`TC_L_18`, `TC_M_01`–`TC_M_22`).
10. Implement diagnostics log retrieval, customer-information handling, and CustomData behavior (`TC_N_25`, `TC_N_26`, `TC_N_32`, `TC_N_35`, `TC_P_01`, `TC_P_03`).
11. Run the complete repository test suite, then execute every applicable Core case with OCTT and reconcile this checklist with the final PICS declaration.

## References

- [OCA OCPP 2.0.1 certification scope](https://openchargealliance.org/certificationocpp/certification-ocpp-2-0-1/)
- [OCA updated OCPP 2.0.1 certification program](https://openchargealliance.org/updated-certification-program-2-0-1/)
- [OCA current OCPP downloads (Edition 4 and Errata 2026-09)](https://openchargealliance.org/my-oca/ocpp/)
- [OCA Test Procedure & Test Plans v3.2.1](https://openchargealliance.org/wp-content/uploads/2026/07/02.-Test-Procedure-Test-Plans_OCPP2.0.1_v3.2.1_final.pdf)
- [OCA Charging Station PICS 3.0.5](https://openchargealliance.org/wp-content/uploads/2026/10/OCPP-2.0.1-PICS-CS-3.0.5.xlsx)
