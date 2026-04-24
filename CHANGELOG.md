# Release Notes for Craft Backup

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
