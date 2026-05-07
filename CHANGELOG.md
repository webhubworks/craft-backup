# Release Notes for Craft Backup

## 2.3.1 - 2026-05-07

### Fixed
- Project config now overrides bundled defaults predictably for list-shaped values. Previously `yii\helpers\ArrayHelper::merge` appended numeric-indexed lists, so a project `monitor_backups` rule was added on top of the bundled default rule (causing duplicate per-target check rows) and project `source.include`/`exclude` paths inherited the bundled paths alongside their own. Lists now replace wholesale; maps still merge by key.

## 2.3.0 - 2026-05-07

### Added
- New `source.split_db_and_files` config option. When enabled, each run produces two archives — one containing the database dumps, one containing the included files — instead of a single combined archive. Both archives share a runId in their filenames (e.g. `…-a1b2c3d4-db.zip` and `…-a1b2c3d4-files.zip`) and are treated as one logical backup by retention (GFS bucketing, size cap) and health checks (`min_number_of_backups`).

### Changed
- `BackupResult::$archivePath` (single, nullable) replaced by `BackupResult::$archivePaths` (list). One entry for combined runs, two for split runs.

## 2.2.0 - 2026-05-07

### Added
- Craft CMS 4 support. The plugin now installs on Craft 4.4+ and Craft 5, on PHP 8.1+.

## 2.1.0 - 2026-05-01

### Added
- Per-row "Download" button on the "Backups by target" card for `local` targets. Clicking streams the backup file to the browser via a CSRF-protected POST. Gated by a new `backup:download` user permission (admins pass automatically); the button is hidden for users without the permission and for non-`local` targets.
- New `download` config block with `max_bytes` (default `'500MB'`), `x_send_file`, and `x_send_file_uri_prefix`. Files larger than `max_bytes` render the download control disabled with a tooltip showing the absolute on-server path so admins can fetch via SCP/SFTP. Setting `x_send_file` to `'X-Sendfile'` (Apache) or `'X-Accel-Redirect'` (nginx) hands the response off to the web server, bypasses the size cap, and frees the PHP-FPM worker immediately.

## 2.0.2 - 2026-04-29

### Changed
- Timestamps in the "Backup health" and "Backups by target" cards now render as a localized relative time (e.g. "2 hours ago") via Carbon's `diffForHumans()`, with the previously-shown absolute date surfaced as an instant tooltip on hover.

## 2.0.1 - 2026-04-29

### Added
- "Notifications" card on the Backup utility, collapsed by default. Lists each configured channel (mail, Slack) with the events it fires on — recipients per event for mail, enabled/disabled per event for Slack — plus the Slack channel override when set. The card hides itself entirely when no notification channels are configured.
- "Send test notification" link in the Slack row that posts a test message to the configured webhook, ignoring per-event flags. Used to confirm setup or debug silence.

## 2.0.0 - 2026-04-29

> ⚠️ **Breaking change.** Notification config has moved out of `logging` into a new top-level `notifications` block. Update your `config/backup.php`:
>
> - `logging.notify_on_failure` → `notifications.mail.on_failure`
> - `logging.notify_on_success` → `notifications.mail.on_success`
> - `logging.notify_on_low_disk_space` → `notifications.mail.on_low_disk_space`

### Added
- Slack notifications via Incoming Webhooks. Configure under `notifications.slack` with `webhook_url` (also overridable via `BACKUP_SLACK_WEBHOOK_URL`), optional `channel`/`username`/`icon`, and per-event `on_failure`/`on_success`/`on_low_disk_space` toggles. Slack receives the same content as the corresponding mail notification.

### Changed
- Notification config moved from `logging.notify_on_*` to `notifications.mail.on_*` (see breaking-change note above).

## 1.2.12 - 2026-04-28

### Added
- "Backups by target" disk usage bar now overlays a darker first segment showing how much of the volume's used space is taken up by the listed backup files, with a matching size in the legend (e.g. `8.42 GB backups`).

## 1.2.11 - 2026-04-28

### Added
- Optional `retention.delete_oldest_backups_when_using_more_megabytes_than`: hard size cap, in megabytes, applied after the GFS rules. If the total size of the kept backups exceeds the cap, the oldest are pruned one by one until the total fits. The newest backup is always retained, even if it alone exceeds the cap. Set to `null` to disable.

## 1.2.10 - 2026-04-28

### Changed
- Moved the per-target disk usage row to the bottom of the "Backups by target" card, separated from the file table and lock legend by a hairline divider so it reads as card metadata rather than a header.

## 1.2.9 - 2026-04-28

### Added
- `warn_when_disk_space_is_lower_than` now also accepts a percentage of total disk (e.g. `'20%'`, `'12.5%'`) in addition to absolute byte counts and shorthand. The "Backups by target" card surfaces the percent next to the resolved bytes in the legend and threshold marker tooltip (e.g. `warn below 11.22 GB free (20%)`).

## 1.2.8 - 2026-04-28

### Added
- Per-target disk usage bar above each tab in the "Backups by target" card, showing used/free space. Optional `warn_when_disk_space_is_lower_than` per `monitor_backups` rule (e.g. `'5GB'`, `'500MB'`, or a percentage like `'20%'`) draws a warn marker on the bar and turns it red once free space drops below the threshold. Only the `local` driver reports disk usage.
- `logging.notify_on_low_disk_space`: email recipients alerted after a backup run if any target's free space fell below its configured warn threshold.

### Changed
- Renamed the Utilities entry from "Backup" to "Backups".

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
