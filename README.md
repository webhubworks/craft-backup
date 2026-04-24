# Craft Backup

**The go-to backup package for Craft CMS**, heavily inspired by `spatie/laravel-backup`. ([Read why](#yet-another-backup-plugin-heres-why))\
Command-line only, database and files, compressed, encrypted, shipped off-site over SFTP, and pruned by a GFS retention policy ([GFS what?](#what-is-gfs-retention-and-why-should-i-care)).

If you've used `laravel-backup` on a Laravel project, you already know how this one feels. See [Compared to spatie/laravel-backup](#compared-to-spatielaravel-backup) for the side-by-side command and feature map.

No control-panel UI, no licensing fees. Schedule it from cron, forget about it.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- A database supported by Craft itself (we delegate to `Craft::$app->db->backup()`):

| Engine | Versions | Dump tool required on PATH |
|---|---|---|
| MySQL | 5.7.8+ / 8.0.5+ | `mysqldump` |
| MariaDB | 10.4.6+ | `mysqldump` |
| PostgreSQL | 13+ | `pg_dump` |

MongoDB is **not** supported (Craft itself runs on SQL). For everything else — encryption, SFTP, archive handling — we use only PHP extensions that ship with Craft's own requirements.

## Installation

```bash
composer require webhubworks/craft-backup
php craft plugin/install backup

# Copy our `src/config.php` into your project or use:
php craft backup/publish-config
```

## Configuration

`config/backup.php` is multi-environment aware (`'*'`, `'dev'`, `'production'` keys), just like `general.php`.

Review the defaults, add your targets, and you're ready to run.\
Encryption is off by default — see [Encryption & restore](#encryption--restore) below for how to turn it on and how to recover an archive.

### Targets

Each entry under `targets` maps a name (freely chosen) to a driver config. Uploads run against every target; retention prunes each one independently. Use `--only-to=<name>` on `backup/run` or `backup/clean` to restrict to a single target.

| Driver | Description | Required keys | Optional keys |
|---|---|---|---|
| `local` | Writes into a directory on the same host | `root` (path or `@alias`) | — |
| `sftp` | Uploads via SSH to a remote server | `host`, `username`, and either `password` or `private_key` | `port` (22), `passphrase`, `root` (`/backups`), `timeout` (30) |

### Environment variables

The published `config/backup.php` reads these out of `.env` via `App::env()` so secrets stay out of version control. Override any or none — unset values fall back to the defaults in the config file.

| Variable | Maps to | Purpose |
|---|---|---|
| `BACKUP_NAME` | `name` | Prefix for generated archive filenames |
| `BACKUP_ARCHIVE_PASSWORD` | `compression.password` | AES-256 password when `compression.format = 'zip'` |
| `BACKUP_ENCRYPTION_ENABLED` | `encryption.enabled` | `true`/`false`/`1`/`0` — enables the `.tar.gz.enc` envelope |
| `BACKUP_ENCRYPTION_KEY` | `encryption.key` | Base64-encoded 32 bytes, required when encryption is enabled |
| `BACKUP_SFTP_HOST` | `targets.<name>.host` | SFTP hostname |
| `BACKUP_SFTP_PORT` | `targets.<name>.port` | SFTP port (defaults to `22`) |
| `BACKUP_SFTP_USERNAME` | `targets.<name>.username` | SFTP username |
| `BACKUP_SFTP_PASSWORD` | `targets.<name>.password` | SFTP password (use this or the private key) |
| `BACKUP_SFTP_PRIVATE_KEY` | `targets.<name>.private_key` | Path to the SSH private key file |
| `BACKUP_SFTP_PASSPHRASE` | `targets.<name>.passphrase` | Passphrase for the private key |
| `BACKUP_SFTP_ROOT` | `targets.<name>.root` | Remote directory backups are written into |

## Commands

```bash
# Quickly test your configuration with:
php craft backup/run --only-db

# All available commands
php craft backup/run                     # DB + files → compress → encrypt → upload → cleanup
php craft backup/run --only-db           # skip file sources
php craft backup/run --only-files        # skip DB dump
php craft backup/run --only-to=offsite   # restrict to the target called "offsite" in our example config
php craft backup/run --disable-cleanup   # skip retention stage
php craft backup/run --dry-run           # plan only
php craft backup/list                    # list backups on each target
php craft backup/clean                   # apply retention without backing up
php craft backup/clean --only-to=offsite # retention on the target called "offsite" in our example config
php craft backup/clean --dry-run         # plan only, don't delete
php craft backup/monitor                 # evaluate monitor_backups rules, print JSON, exit non-zero on failure
php craft backup/publish-config          # copy default config into project
php craft backup/decrypt <in> [out]      # reverse an .enc archive to .tar.gz
```

## Cron

Schedule `backup/run` from your crontab. The example below runs it every night at 3 AM — use [crontab.guru](https://crontab.guru/#0_3_*_*_*) to tweak the cadence if you want a different schedule.

```
0 3 * * * cd /path/to/site && php craft backup/run >> storage/logs/backup.log 2>&1
```

## Monitoring

Taking a backup doesn't mean much if nobody notices when it silently stops working. `backup/monitor` evaluates the rules under `monitor_backups` in `config/backup.php` and prints a JSON report, exiting non-zero on failure so it's easy to wire into external health checks.

Each rule asserts one or more conditions against a named target:

```php
'monitor_backups' => [
    [
        'target' => 'local',
        'min_number_of_backups' => 1,
        'youngest_backup_should_be_within_the_last' => '6h', // supports s/m/h/d
    ],
],
```

Both `min_number_of_backups` and `youngest_backup_should_be_within_the_last` are optional; omit either to skip that assertion. The `target` key is required and must match an entry under `targets`. You can repeat the same target across multiple rules if you want each assertion reported separately.

Sample output on success:

```json
{"status":"ok","checks":[{"target":"local","status":"ok"}]}
```

And on failure:

```json
{"status":"failure","checks":[{"target":"local","status":"failure","reason":"Youngest backup on 'local' is 2.3d old; max allowed is 6h."}]}
```

### With Oh Dear

If you already use [Oh Dear](https://ohdear.app/) for uptime monitoring, our [craft-ohdear](https://github.com/webhubworks/craft-ohdear) plugin exposes a `Check::backupHealth()` check that wraps `backup/monitor` — Oh Dear will then alert you (email, Slack, etc.) the moment a backup assertion fails, without you needing a separate cron-to-pager pipeline.

## Yet another backup plugin? Here's why:

At webhub we've been building on Craft CMS and Laravel for years, and we want backups to work the same way across every project — whether the site runs on Craft or Laravel. This plugin closes one more part of the gap between the two stacks, following our [Craft Oh Dear](https://plugins.craftcms.com/ohdear) and [Flare](https://plugins.craftcms.com/craft-flare) plugins. On Laravel projects we reach for Spatie's excellent `laravel-backup` without a second thought; we wanted the same reliable, boring-in-a-good-way experience on Craft. And with Craft 5 (Yii-based) and Craft 6 (Laravel-based) both in the community's future, every Craft developer will soon be maintaining projects on both stacks — a backup tool that behaves consistently across all of them matters.

## Compared to spatie/laravel-backup

This plugin deliberately mirrors the CLI surface and config shape of [spatie/laravel-backup](https://github.com/spatie/laravel-backup) so Laravel developers coming to Craft land on familiar ground.

| `webhubworks/craft-backup`                      | `spatie/laravel-backup`                 |
|-------------------------------------------------|-----------------------------------------|
| `craft backup/run`                              | `artisan backup:run`                    |
| `craft backup/run --only-db`                    | `artisan backup:run --only-db`          |
| `craft backup/run --only-files`                 | `artisan backup:run --only-files`       |
| `craft backup/run --only-to=offsite`            | `artisan backup:run --only-to-disk=s3`  |
| `craft backup/list`                             | `artisan backup:list`                   |
| `craft backup/clean`                            | `artisan backup:clean`                  |
| `config/backup.php`                             | `config/backup.php`                     |
| `local` + `sftp` targets (more drivers planned) | Filesystem "disks" as targets           |
| ✅                                              | Database and file backup support        |
| ✅                                              | Encryption support                      |
| ✅                                              | GFS retention                           |
| ✅                                              | Mail notifications                      |
| ✅ (via `backup/monitor` + Oh Dear integration) | Monitor the health of your backups      |
| _Coming soon_                                   | Slack & Discord notifications, Webhooks |

## What is GFS retention? And why should I care?

**GFS** stands for **Grandfather-Father-Son**, a classic rotation scheme from the days of tape backups. The idea: you keep **lots** of very recent backups, **some** weekly ones, and **a few** monthly and yearly ones. Older backups automatically get thinner on the ground instead of piling up forever.

Why bother? Two very practical reasons:

1. **Disk/SFTP quota.** Running a nightly backup and keeping them all means a year's worth of full-site archives sitting on your storage. GFS caps that without you having to remember to clean up.
2. **Recovery granularity that matches how bugs are actually discovered.** You almost always need "yesterday" or "last week" — fine-grained, recent snapshots. Occasionally you need "last month" when a slow data corruption finally surfaces. Very rarely you need "a year ago" for a long-running legal/accounting question. GFS gives you all three without storing hundreds of archives.

## Advanced configuration

### Archive format

Pick the container via `compression.format`:

| Format | Output filename | Encryption option |
|---|---|---|
| `zip` (default) | `<name>-<ts>-<runid>.zip` | `compression.password`, AES-256 |
| `tar.gz` | `<name>-<ts>-<runid>.tar.gz[.enc]` | `encryption` block, AES-256-CBC + HMAC-SHA256 |

`zip` is the default because it can be opened with any standard archive tool. `tar.gz` compresses better and pairs with the bundled authenticated-envelope encryption if you want integrity verification on top.

### Encryption & restore

Encryption is **off** by default — a plain `.zip` comes out of a run until you configure a password (for `zip`) or enable the custom envelope (for `tar.gz`). Two modes:

#### zip + password (default format)

Set a password in `.env`:

```
BACKUP_ARCHIVE_PASSWORD=<choose a strong password>
```

The shipped `config/backup.php` already reads this via `App::env()`. Decrypt:

#### tar.gz + encryption block

Use this when you want **authenticated** encryption (tampering/truncation fails closed on decrypt) or better compression. The file extension becomes `.tar.gz.enc` and standard tools like `openssl enc` or `gpg` **cannot** open it — you decrypt with the bundled command or the standalone script.

Generate a 32-byte base64 key once and store it somewhere you will not lose it (password manager, sealed envelope, team vault):

```
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Switch formats and enable encryption in `.env`:

```
BACKUP_ENCRYPTION_ENABLED=true
BACKUP_ENCRYPTION_KEY=<paste base64 key here>
```

Also set `compression.format` to `tar.gz` in `config/backup.php` (or leave it default once you flip the plugin default). Lose the key and the archive is unrecoverable — by design.

Decrypt:

```
# On a machine with Craft + the plugin + the key in .env:
php craft backup/decrypt my-site-2026-04-24_03-00-00-abc123.tar.gz.enc
tar -xzf my-site-2026-04-24_03-00-00-abc123.tar.gz

# Disaster recovery on any machine with PHP (no Craft required):
php vendor/webhubworks/craft-backup/scripts/decrypt.php archive.tar.gz.enc archive.tar.gz "$KEY"
```

`scripts/decrypt.php` is intentionally dependency-free — copy it onto any recovery machine alongside the archive and the base64 key.

#### Restoring the contents

Both formats, once extracted, produce a `db-db.sql` at the root plus a `files/` tree mirroring your configured `source.include` paths (relative to `@root`). Import the SQL with your DB's native tool (`mysql < db-db.sql`, `psql -f db-db.sql`, etc.) and drop the files back into place.

## Changelog
See the [changelog](CHANGELOG.md) for release history.

## Security
See [SECURITY.md](SECURITY.md) for our security policy and instructions on how to report a vulnerability.

## Credits
- [Spatie](https://spatie.be/) for the inspiration and the Laravel version of this plugin.
- [Marven Thieme](https://github.com/marventhieme)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
