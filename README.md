# Craft Backup

Encrypted, compressed, off-site backups for Craft CMS — database and files — with SFTP targets, GFS retention, and a CLI modelled after `spatie/laravel-backup`.

No control-panel UI, no licensing fees.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later

## Installation

```
composer require webhubworks/craft-backup
./craft plugin/install backup
./craft backup/publish-config
```

`publish-config` drops a commented default file at `config/backup.php`. Review the defaults, add your targets, and you're ready to run. Encryption is off by default — see [Encryption & restore](#encryption--restore) below for how to turn it on and how to recover an archive.

## Configuration

`config/backup.php` is multi-environment aware (`'*'`, `'dev'`, `'production'` keys), just like `general.php`. See the published file for every option; the shape is:

```php
return [
    '*' => [
        'name' => App::env('CRAFT_BACKUP_NAME') ?: 'my-site',
        'source' => [
            'databases' => ['db'],
            'include' => ['@root/config', '@webroot/uploads'],
            'exclude' => ['@storage/runtime', '*.DS_Store'],
        ],
        'compression' => [
            'format' => 'zip',
            'password' => App::env('CRAFT_BACKUP_ARCHIVE_PASSWORD') ?: null,
        ],
        'encryption' => [
            'enabled' => filter_var(App::env('CRAFT_BACKUP_ENCRYPTION_ENABLED'), FILTER_VALIDATE_BOOLEAN),
            'key' => App::env('CRAFT_BACKUP_ENCRYPTION_KEY') ?: null,
        ],
        'throttle' => ['enabled' => false, 'bytes_per_second' => 5_000_000],
        'targets' => [
            'local' => ['driver' => 'local', 'root' => '@storage/backups'],
            'offsite' => [
                'driver' => 'sftp',
                'host' => App::env('CRAFT_BACKUP_SFTP_HOST'),
                'username' => App::env('CRAFT_BACKUP_SFTP_USERNAME'),
                'private_key' => App::env('CRAFT_BACKUP_SFTP_PRIVATE_KEY') ?: null,
                'root' => App::env('CRAFT_BACKUP_SFTP_ROOT') ?: '/backups/my-site',
            ],
        ],
        'retention' => [
            'keep_all_for_days' => 7,
            'keep_daily_for_days' => 30,
            'keep_weekly_for_weeks' => 8,
            'keep_monthly_for_months' => 12,
            'keep_yearly_for_years' => 3,
        ],
    ],
];
```

### Environment variables

The published `config/backup.php` reads these out of `.env` via `App::env()` so secrets stay out of version control. Override any or none — unset values fall back to the defaults in the config file.

| Variable | Maps to | Purpose |
|---|---|---|
| `CRAFT_BACKUP_NAME` | `name` | Prefix for generated archive filenames |
| `CRAFT_BACKUP_ARCHIVE_PASSWORD` | `compression.password` | AES-256 password when `compression.format = 'zip'` |
| `CRAFT_BACKUP_ENCRYPTION_ENABLED` | `encryption.enabled` | `true`/`false`/`1`/`0` — enables the `.tar.gz.enc` envelope |
| `CRAFT_BACKUP_ENCRYPTION_KEY` | `encryption.key` | Base64-encoded 32 bytes, required when encryption is enabled |
| `CRAFT_BACKUP_SFTP_HOST` | `targets.<name>.host` | SFTP hostname |
| `CRAFT_BACKUP_SFTP_PORT` | `targets.<name>.port` | SFTP port (defaults to `22`) |
| `CRAFT_BACKUP_SFTP_USERNAME` | `targets.<name>.username` | SFTP username |
| `CRAFT_BACKUP_SFTP_PASSWORD` | `targets.<name>.password` | SFTP password (use this or the private key) |
| `CRAFT_BACKUP_SFTP_PRIVATE_KEY` | `targets.<name>.private_key` | Path to the SSH private key file |
| `CRAFT_BACKUP_SFTP_PASSPHRASE` | `targets.<name>.passphrase` | Passphrase for the private key |
| `CRAFT_BACKUP_SFTP_ROOT` | `targets.<name>.root` | Remote directory backups are written into |

## Commands

```
./craft backup/run                     # DB + files → compress → encrypt → upload → prune
./craft backup/run --only-db           # skip file sources
./craft backup/run --only-files        # skip DB dump
./craft backup/run --only-to=offsite   # restrict to one target
./craft backup/run --disable-cleanup   # skip retention stage
./craft backup/run --dry-run           # plan only
./craft backup/list                    # list backups on each target
./craft backup/clean                   # apply retention without backing up
./craft backup/publish-config          # copy default config into project
./craft backup/decrypt <in> [out]      # reverse an .enc archive to .tar.gz
```

Exit codes: `0` success, `1` partial (archive built, at least one target failed), `2` failure (no archive produced).

## Cron

```
0 3 * * * cd /path/to/site && ./craft backup/run >> storage/logs/backup.log 2>&1
```

## Archive format

Pick the container via `compression.format`:

| Format | Output filename | Encryption option |
|---|---|---|
| `zip` (default) | `<name>-<ts>-<runid>.zip` | `compression.password`, AES-256 |
| `tar.gz` | `<name>-<ts>-<runid>.tar.gz[.enc]` | `encryption` block, AES-256-CBC + HMAC-SHA256 |

`zip` is the default because it can be opened with any standard archive tool. `tar.gz` compresses better and pairs with the bundled authenticated-envelope encryption if you want integrity verification on top.

## Encryption & restore

Encryption is **off** by default — a plain `.zip` comes out of a run until you configure a password (for `zip`) or enable the custom envelope (for `tar.gz`). Two modes:

### zip + password (default format)

AES-256 encryption per entry, readable by any standard zip tool. The password is a plain string that operators can hand to clients or partners without them needing the plugin.

Set a password in `.env`:

```
CRAFT_BACKUP_ARCHIVE_PASSWORD=<choose a strong password>
```

The shipped `config/backup.php` already reads this via `App::env()`. Decrypt:

```
unzip -P "$PASSWORD" my-site-2026-04-24_03-00-00-abc123.zip
```

Also works with 7-Zip, macOS Archive Utility, Keka, etc.

### tar.gz + encryption block

Use this when you want **authenticated** encryption (tampering/truncation fails closed on decrypt) or better compression. The file extension becomes `.tar.gz.enc` and standard tools like `openssl enc` or `gpg` **cannot** open it — you decrypt with the bundled command or the standalone script.

Generate a 32-byte base64 key once and store it somewhere you will not lose it (password manager, sealed envelope, team vault):

```
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Switch formats and enable encryption in `.env`:

```
CRAFT_BACKUP_ENCRYPTION_ENABLED=true
CRAFT_BACKUP_ENCRYPTION_KEY=<paste base64 key here>
```

Also set `compression.format` to `tar.gz` in `config/backup.php` (or leave it default once you flip the plugin default). Lose the key and the archive is unrecoverable — by design.

Decrypt:

```
# On a machine with Craft + the plugin + the key in .env:
./craft backup/decrypt my-site-2026-04-24_03-00-00-abc123.tar.gz.enc
tar -xzf my-site-2026-04-24_03-00-00-abc123.tar.gz

# Disaster recovery on any machine with PHP (no Craft required):
php vendor/webhubworks/craft-backup/scripts/decrypt.php archive.tar.gz.enc archive.tar.gz "$KEY"
```

`scripts/decrypt.php` is intentionally dependency-free — copy it onto any recovery machine alongside the archive and the base64 key.

### Restoring the contents

Both formats, once extracted, produce a `db-db.sql` at the root plus a `files/` tree mirroring your configured `source.include` paths (relative to `@root`). Import the SQL with your DB's native tool (`mysql < db-db.sql`, `psql -f db-db.sql`, etc.) and drop the files back into place.

## License

MIT.
