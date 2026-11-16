# ACS inventory — safe deployment to customized panel

The PR #182 adds the **backend** and opt-in API. It does not install files on the
customized production instance. Use this installer after merge, on the ACS host.

The installation script refuses unknown live API markers, customized module files,
symlinked destinations, failed PHP lint or concurrent changes. It creates a secure
backup and rolls back file writes if installation fails.

## Phase A: update checkout and inspect plan (no production changes)

```bash
cd /tmp/jrc-acs-firmware-ui && git pull --ff-only origin main && python3 scripts/deploy-acs-inventory.py plan
```

The checkout path above was previously used for ACS firmware deployment. If this
checkout does not exist, use an existing verified checkout of the repository.
**Do not run `git pull` inside `/var/www/gacs` unless you have confirmed it is a clean checkout.**

Proceed only on `PLAN_OK`, or `JA_INSTALADO`. Stop and inspect if the
script refuses a live customization. Never replace `assets/js/devices.js`.

## Phase B: install module files and API cache conditional

```bash
cd /tmp/jrc-acs-firmware-ui && sudo python3 scripts/deploy-acs-inventory.py apply
```

This does **not** run the collector, change MySQL tables, enable the cache or
schedule a recurring job. No device commands are sent.

## Phase C: single first collection, *without enabling cache*

Back up the database before the first run (the collector creates two tables).
Run as the service account with database privileges, after verifying PHP config:

```bash
cd /var/www/gacs && php cron/collect-device-inventory.php
```

Expected: `COLETA_OK total=N ixc_matched=M snapshot=...`.
Check `/api/device-inventory-status.php` as an authenticated user.
Compare inventory size against GenieACS (the operator's earlier screenshot had
757 devices) and test data completeness.

To include IXC signal and PPPoE, configure `JRC_IXC_URL` and `JRC_IXC_TOKEN`
for the private collector process, not in the repository or public command line.
The existing production IXC integration is not automatically used by this collector.

## Phase D: activation (separate, only after validation)

In the database `configurations`, set `jrc_inventory_cache_enabled=1` through
an authorized administration process. Start a periodic CLI job (suggest 60 seconds)
only if a full collection finishes comfortably within that interval. The
collector's file lock avoids parallel runs. Observe last collection time and
staleness. Rolling back the flag to `0` restores the legacy live GenieACS API.

**Security note:** audit/rotate any IXC token previously stored in source files.
