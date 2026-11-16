# ACS optical readings and report layout — guarded rollout

## Current findings (16 Nov 2026)
- A Huawei HG8546M pilot with RX path `InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower` received HTTP 202 for `refreshObject`, but no fresh RX reading was confirmed.
- A read-only task inspection later found **two pending** `refreshObject` tasks for the same optical object. Do not enqueue additional refreshes or delete these tasks automatically.
- Some FiberHome and Huawei devices have no stored optical paths, while others have stale RX timestamps; a recent Inform does **not** certify a recent optical reading.
- The live `/var/www/gacs/view-logs.php` is customized and differs from the version stored in this repository. Never replace it with the repository copy.

## Changes in this PR
1. `scripts/pilot-acs-onu-optical-refresh.php` checks the local GenieACS task queue before submitting any refresh. An existing overlapping optical `refreshObject` blocks execution and is reported without deletion or retries. If the queue cannot be verified (HTTP error, invalid response or pagination limit), fail closed.
2. `assets/css/jrc-signal-report-blue.css` gains strictly report-scoped table spacing, focus visibility and narrow-screen overflow rules. It is presentation-only and preserves RX status classes.
3. No automatic fleet refresh, no CPE changes, no firmware, no preset updates and no changes to the live personalized page.

## Required acceptance checks before production deployment
- PHP lint and read-only pilot plan; with outstanding tasks, expect `REFRESH_OPTICO_PENDENTE` and no POST.
- Verify tasks are not duplicated by a pilot; **do not repeat `--execute`** while any matching task remains pending.
- Independently confirm RX value, unit (dBm) and parameter timestamp against a trusted source (OLT/IXC) before exposing stale raw values as current.
- Verify browser layout at desktop/mobile widths against the customized live report. Integrate the CSS as a scoped addition only, not a wholesale replacement of the customized live stylesheet.
- Classify report gaps separately: missing TR-069 optical path, stale RX, missing value, old Inform and actual optical threshold violations. Do not label unavailable measurements as healthy or critical.
- Keep all ACS services and equipment management untouched. Take backups of live CSS before applying any scoped change.

## Explicitly out of scope / unresolved
- Clearing the existing two pending tasks: requires investigation into CWMP processing, and human review, not bulk deletion.
- Finding paths for ONUs without previously discovered optical parameters; validate one model-specific ONU pilot first.
- The current customized PHP table markup and JS have not been safely fetched into the repository; therefore no change to those production files is proposed in this PR.
