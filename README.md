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

`publish-config` drops a commented default file at `config/backup.php`. Edit it, then generate an encryption key and put it in your `.env`:

```
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

```
# .env
CRAFT_BACKUP_ENCRYPTION_KEY=<paste base64 key here>
```

Store that same key in your password manager — you need it to decrypt any backup.

## Configuration

`config/backup.php` is multi-environment aware (`'*'`, `'dev'`, `'production'` keys), just like `general.php`. See the published file for every option; the shape is:

```php
return [
    '*' => [
        'name' => 'my-site',
        'source' => [
            'databases' => ['db'],
            'include' => ['@root/config', '@webroot/uploads'],
            'exclude' => ['@storage/runtime', '*.DS_Store'],
        ],
        'encryption' => [
            'enabled' => true,
            'key' => App::env('CRAFT_BACKUP_ENCRYPTION_KEY'),
        ],
        'throttle' => ['enabled' => false, 'bytes_per_second' => 5_000_000],
        'targets' => [
            'local' => ['driver' => 'local', 'root' => '@storage/backups'],
            'offsite' => [
                'driver' => 'sftp',
                'host' => App::env('BACKUP_SFTP_HOST'),
                'username' => App::env('BACKUP_SFTP_USER'),
                'private_key' => App::env('BACKUP_SFTP_KEY_PATH'),
                'root' => '/backups/my-site',
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

| Format | Output filename | Encryption option | Restore |
|---|---|---|---|
| `tar.gz` (default) | `<name>-<ts>-<runid>.tar.gz[.enc]` | `encryption` block, AES-256-CBC + HMAC-SHA256 | `./craft backup/decrypt` or bundled `scripts/decrypt.php`, then `tar -xzf` |
| `zip` | `<name>-<ts>-<runid>.zip` | `compression.password`, AES-256 | `unzip -P <password>`, 7-Zip, macOS Archive Utility, etc. |

`tar.gz` compresses better and is the default. `zip` is useful when operators or clients need to open backups without the plugin.

## Restoring a backup

```
# tar.gz.enc — on a machine that has Craft + the plugin + the key in config/.env:
./craft backup/decrypt my-site-2026-04-24_03-00-00-abc123.tar.gz.enc
tar -xzf my-site-2026-04-24_03-00-00-abc123.tar.gz

# tar.gz.enc — disaster recovery on any machine with PHP (no Craft required):
php vendor/webhubworks/craft-backup/scripts/decrypt.php archive.tar.gz.enc archive.tar.gz "$KEY"

# zip (password-protected):
unzip -P "$PASSWORD" my-site-2026-04-24_03-00-00-abc123.zip
```

The standalone `scripts/decrypt.php` is intentionally dependency-free — copy it onto any recovery machine alongside the archive and the base64 key.

## License

MIT.
