# Release Notes for Craft Backup

## 1.2.7 - 2026-04-28

### Changed
- Backups-by-target table now also shows a grey open-lock icon for `.zip` files that are not password protected. On local target tabs the legend explains both lock states; non-local tabs only show the "zip detection is available for local targets only" note, so a missing icon no longer reads as "encryption unknown".

## 1.2.6 - 2026-04-28

### Added
- Per-target checks card now expands to reveal each individual check (target reachable, minimum backup count, youngest backup age) with pass/fail/skipped state. Failing targets auto-expand.
- "Show checks" toggle on the Backup health card surfaces high-level signals: monitoring configured, last run recorded, last run succeeded, a successful backup exists, all targets healthy.
- German translations (`src/translations/de/backup.php`) covering the full string catalog.

### Changed
- `BackupMonitor` now evaluates every configured check independently instead of bailing on first failure, and routes user-facing labels and reasons through `Craft::t('backup', …)` so the UI and `./craft backup/monitor` JSON output respect the active language.
- Duration formatting in monitor results uses integer-only composites (`3h 12min`, `2d 4h`) instead of decimal hours/days like `2.1h`.
- Backups-by-target table capped at 6.5 visible rows so the half-cut row hints at scrollability.
- Renamed the table column "Modified" to "Created" — backup files are written once, so the file's mtime is effectively its creation time.

## 1.2.5 - 2026-04-27

### Added
- Green lock icon next to password-protected `.zip` files in the "Backups by target" card, with a legend explaining that detection is available for local targets only.

## 1.2.4 - 2026-04-27

### Added
- Optional `date_time_format` config value applied to the timestamps shown in the "Backup health" and "Backups by target" cards.

### Changed
- Tightened status badge layout in the Backup utility cards so badges no longer hang-indent when wrapping and per-target check badges only take the width they need.

## 1.2.3 - 2026-04-26

### Changed
- Deferred per-card data loading on the status page so it now renders immediately with skeleton placeholders and isolates failures to individual cards.

## 1.2.2 - 2026-04-26

### Changed
- Renamed plugin to "Backup".
- Updated plugin icon.
- Moved backup health overview from a dedicated control panel section into a utility under Utilities.
- Reworked the status page layout into single cards and adjusted styling.

## 1.2.1 - 2026-04-25

### Security
- Pinned `phpseclib/phpseclib` to `^3.0.51` to pick up upstream security fixes.

## 1.2.0 - 2026-04-25

### Added
- Control panel status page showing last/next run, recent results, and per-target health.
- `BackupMonitor` health checks surfaced in the UI (translations, templates, asset bundle).
- `RunStateStore` to persist run state for the status page.

## 1.1.1 - 2026-04-24

### Added
- Health check documentation in the README.

## 1.1.0 - 2026-04-24

### Added
- `backup/monitor` console command for verifying that recent backups exist and meet freshness/size thresholds, including notifications on failure.

## 1.0.0 - 2026-04-24

Initial release.

### Added
- Console commands modelled after `spatie/laravel-backup`:
  - `backup/run` with `--only-db`, `--only-files`, `--only-to`, `--disable-cleanup`, `--dry-run`
  - `backup/list`
  - `backup/clean` with `--only-to`, `--dry-run`
  - `backup/publish-config`
  - `backup/decrypt`
- Target drivers: `local` and `sftp` (via `league/flysystem-sftp-v3`). Multiple targets per run, retention applied per target independently.
- Archive containers: `zip` (default, optional AES-256 password) and `tar.gz` (with optional custom AES-256-CBC + HMAC-SHA256 envelope).
- Dependency-free `scripts/decrypt.php` recovery script.
- Grandfather-Father-Son retention policy with configurable daily/weekly/monthly/yearly buckets.
- Mail notifications on success and failure via Craft's mailer.
- Optional upload throttling via streaming stream filter.
- Env-var overrides for all sensitive config keys (`BACKUP_NAME`, `BACKUP_ARCHIVE_PASSWORD`, `BACKUP_ENCRYPTION_ENABLED`, `BACKUP_ENCRYPTION_KEY`, `BACKUP_SFTP_*`).
