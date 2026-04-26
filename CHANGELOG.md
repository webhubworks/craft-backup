# Release Notes for Craft Backup

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
