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
craft plugin/install backup

# Copy our `src/config.php` into your project or use:
craft backup/publish-config
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
craft backup/run --only-db

# All available commands
craft backup/run                     # DB + files → compress → encrypt → upload → cleanup
craft backup/run --only-db           # skip file sources
craft backup/run --only-files        # skip DB dump
craft backup/run --only-to=offsite   # restrict to one target
craft backup/run --disable-cleanup   # skip retention stage
craft backup/run --dry-run           # plan only
craft backup/list                    # list backups on each target
craft backup/clean                   # apply retention without backing up
craft backup/clean --only-to=offsite # retention on one target
craft backup/clean --dry-run         # plan only, don't delete
craft backup/publish-config          # copy default config into project
craft backup/decrypt <in> [out]      # reverse an .enc archive to .tar.gz
```

## Cron

```
0 3 * * * cd /path/to/site && craft backup/run >> storage/logs/backup.log 2>&1
```

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
| _Coming soon_                                   | Slack & Discord notifications, Webhooks |
| _Coming soon_                                   | Monitor the health of your backups      |

## What is GFS retention? And why should I care?

**GFS** stands for **Grandfather-Father-Son**, a classic rotation scheme from the days of tape backups. The idea: you keep **lots** of very recent backups, **some** weekly ones, and **a few** monthly and yearly ones. Older backups automatically get thinner on the ground instead of piling up forever.

Why bother? Two very practical reasons:

1. **Disk/SFTP quota.** Running a nightly backup and keeping them all means a year's worth of full-site archives sitting on your storage. GFS caps that without you having to remember to clean up.
2. **Recovery granularity that matches how bugs are actually discovered.** You almost always need "yesterday" or "last week" — fine-grained, recent snapshots. Occasionally you need "last month" when a slow data corruption finally surfaces. Very rarely you need "a year ago" for a long-running legal/accounting question. GFS gives you all three without storing hundreds of archives.

### A short example

Imagine you run `craft backup/run` every night at 3 AM with the default retention policy. After two years of running, here's what's on your storage:

| Age | What's kept | How many files |
|---|---|---|
| 0–7 days | **Every run** (safety net) | 7 |
| 8–23 days | One per day | 16 |
| 4–12 weeks | One per week | 8 |
| 4–8 months | One per month | 4 |
| 1–2 years | One per year | 2 |

Total: **~37 archives**. If those were ~500 MB each, you're holding ~18 GB of backup history covering ~2.5 years. Without GFS, two years of nightly backups = 730 archives ≈ 365 GB for the same coverage — over 20x more storage.

If your site rarely changes, crank `keep_yearly_for_years` up and the rest down. If it changes constantly and you want more recovery points, bump `keep_daily_for_days`. Every bucket is independent — see the comments above `retention` in the published `config/backup.php` for the full mechanics.

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
craft backup/decrypt my-site-2026-04-24_03-00-00-abc123.tar.gz.enc
tar -xzf my-site-2026-04-24_03-00-00-abc123.tar.gz

# Disaster recovery on any machine with PHP (no Craft required):
php vendor/webhubworks/craft-backup/scripts/decrypt.php archive.tar.gz.enc archive.tar.gz "$KEY"
```

`scripts/decrypt.php` is intentionally dependency-free — copy it onto any recovery machine alongside the archive and the base64 key.

#### Restoring the contents

Both formats, once extracted, produce a `db-db.sql` at the root plus a `files/` tree mirroring your configured `source.include` paths (relative to `@root`). Import the SQL with your DB's native tool (`mysql < db-db.sql`, `psql -f db-db.sql`, etc.) and drop the files back into place.


## License

MIT.
