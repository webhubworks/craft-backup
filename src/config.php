<?php

use craft\helpers\App;

/**
 * Default Craft Backup configuration.
 *
 * Copy this file to your project's config/ directory as backup.php and
 * override the values you care about. The file is multi-environment aware in
 * the same way general.php is.
 *
 * Several values fall back to environment variables so that secrets (keys,
 * passwords) stay out of version control:
 *
 *   BACKUP_NAME                — overrides 'name'
 *   BACKUP_ARCHIVE_PASSWORD    — overrides 'compression.password'
 *   BACKUP_ENCRYPTION_ENABLED  — overrides 'encryption.enabled' (true/false/1/0)
 *   BACKUP_ENCRYPTION_KEY      — overrides 'encryption.key'
 *
 * SFTP target credentials (only used when the commented 'offsite' block is active):
 *
 *   BACKUP_SFTP_HOST           — SFTP hostname
 *   BACKUP_SFTP_PORT           — SFTP port (defaults to 22)
 *   BACKUP_SFTP_USERNAME       — SFTP username
 *   BACKUP_SFTP_PASSWORD       — SFTP password (use this or PRIVATE_KEY)
 *   BACKUP_SFTP_PRIVATE_KEY    — path to the private key file
 *   BACKUP_SFTP_PASSPHRASE     — passphrase for the private key
 *   BACKUP_SFTP_ROOT           — remote directory backups are written into
 */

return [
    '*' => [
        // Used as prefix for generated archive filenames.
        'name' => App::env('BACKUP_NAME') ?: 'craft-backup',

        'source' => [
            // Craft DB connection component IDs to dump. Use ['db'] for the default connection.
            'databases' => ['db'],

            // Paths (absolute or Yii aliases) to include in the files portion.
            'include' => [
                '@webroot/media',
                '@webroot/uploads',
            ],

            // Paths or glob patterns to exclude (applied relative to each included root).
            'exclude' => [
                '*.DS_Store',
                '*.tmp',
            ],

            'follow_symlinks' => false,
        ],

        /**
         * Archive container.
         *
         *   'tar.gz' — Smaller files, better compression, but the
         *              output can only be encrypted via the 'encryption' block
         *              below (producing a custom .tar.gz.enc that needs the
         *              bundled decrypter).
         *   'zip'    — universally readable. Set 'password' below and the
         *              archive is AES-256 encrypted and extractable with any
         *              zip tool (`unzip -P`, 7-Zip, macOS Archive Utility).
         *              Incompatible with the 'encryption' block: if you want
         *              a password-protected archive, use this and leave
         *              encryption.enabled = false.
         */
        'compression' => [
            'format' => 'zip',
            /**
             * Deflate compression level, 0–9.
             *   0 — no compression (fastest, largest output)
             *   1 — fastest meaningful compression
             *   6 — balanced default; what `gzip` uses without flags
             *   9 — maximum compression (slowest, smallest output)
             *
             * Higher levels save bytes but burn more CPU on the backup host —
             * relevant when throttling isn't enough and the live site is
             * sensitive to load during backup windows.
             */
            'level' => 6,
            /**
             * Only used when format = 'zip'. If set, every entry is encrypted
             * with AES-256 using this password. Store it in a password manager;
             * lose it and the archive is unrecoverable.
             *
             * Override via BACKUP_ARCHIVE_PASSWORD in .env.
             */
            'password' => App::env('BACKUP_ARCHIVE_PASSWORD') ?: null,
        ],

        /**
         * Archive encryption.
         *
         * When enabled, the finished .tar.gz is wrapped in an authenticated
         * envelope (AES-256-CBC + HMAC-SHA256) and written as .tar.gz.enc.
         * Tampering or truncation fails closed on decrypt.
         *
         * The output is a custom format — standard tools like `openssl enc` or
         * `gpg` CANNOT decrypt it. Use one of:
         *   - ./craft backup/decrypt <archive.enc>       (on a Craft host)
         *   - php vendor/webhubworks/craft-backup/scripts/decrypt.php <archive.enc> <out.tar.gz> <key>
         *     (stand-alone, zero dependencies, for disaster recovery)
         *
         * Generate a key once and store it somewhere you will not lose it
         * (password manager, sealed envelope, team vault). Without the key the
         * archive is unrecoverable — by design.
         *
         *     php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
         *
         * Paste the result into your .env as BACKUP_ENCRYPTION_KEY and
         * reference it here via App::env(). Rotating the key only affects new
         * backups; existing archives must still be decrypted with the old key.
         */
        'encryption' => [
            'enabled' => filter_var(App::env('BACKUP_ENCRYPTION_ENABLED'), FILTER_VALIDATE_BOOLEAN),
            'cipher' => 'aes-256-cbc',
            // Base64-encoded 32 random bytes. Required when 'enabled' is true.
            // Override via BACKUP_ENCRYPTION_KEY in .env.
            'key' => App::env('BACKUP_ENCRYPTION_KEY') ?: null,
        ],

        'throttle' => [
            'enabled' => false,
            'bytes_per_second' => 5 * 1024 * 1024,
        ],

        /**
         * Each target maps a name (freely chosen) to a driver config. Uploads
         * run against every configured target; retention prunes each one
         * independently. Use `--only-to=<name>` on backup/run or backup/clean
         * to restrict to a single target.
         *
         * Available drivers:
         *   'local' — writes into a directory on the same host
         *               required: root (path or @alias)
         *   'sftp'  — uploads via SSH to a remote server
         *               required: host, username, and either password or private_key
         *               optional: port (22), passphrase, root (/backups), timeout (30)
         */
        'targets' => [
            'local' => [
                'driver' => 'local',
                'root' => '@storage/backups',
            ],
            // 'offsite' => [
            //     'driver' => 'sftp',
            //     'host' => App::env('BACKUP_SFTP_HOST'),
            //     'port' => (int) (App::env('BACKUP_SFTP_PORT') ?: 22),
            //     'username' => App::env('BACKUP_SFTP_USERNAME'),
            //     'password' => App::env('BACKUP_SFTP_PASSWORD') ?: null,
            //     'private_key' => App::env('BACKUP_SFTP_PRIVATE_KEY') ?: null,
            //     'passphrase' => App::env('BACKUP_SFTP_PASSPHRASE') ?: null,
            //     'root' => App::env('BACKUP_SFTP_ROOT') ?: '/backups',
            //     'timeout' => 30,
            // ],
        ],

        /**
         * Retention policy — which existing backups to keep, which to prune.
         * Runs after every successful upload (and on backup/clean), applied to
         * each target independently.
         *
         * Implements a GFS (grandfather-father-son) strategy: backups are
         * processed newest-first and each one falls into the first bucket below
         * that still has room. Anything that doesn't fit any bucket is deleted.
         *
         *   keep_all_for_days       Unconditional safety net. EVERY backup
         *                           taken within the last N days is kept —
         *                           however many that is. Run backup/run four
         *                           times a day for the last three days with
         *                           keep_all_for_days = 3 and all 12 are kept.
         *                           This is what makes the policy safe for
         *                           frequent runs; the buckets below never
         *                           deduplicate anything that's still "recent".
         *
         *   keep_daily_for_days     Past the safety net, keep the newest backup
         *                           PER CALENDAR DAY for N more days. Multiple
         *                           runs on the same day collapse to one.
         *
         *   keep_weekly_for_weeks   Past that, keep one per ISO week for N more
         *                           weeks.
         *
         *   keep_monthly_for_months Then one per calendar month for N more
         *                           months.
         *
         *   keep_yearly_for_years   Then one per calendar year for N more
         *                           years.
         *
         * Defaults below give ~2.5 years of history: every run for the last
         * week, one per day for the next ~2 weeks, one per week for the 2
         * months after that, one per month for the following 4 months, and
         * finally one per year for 2 years. Set any bucket to 0 to disable it.
         *
         * TODO: Implement delete_oldest_when_larger_than_mb
         */
        'retention' => [
            'keep_all_for_days' => 7,
            'keep_daily_for_days' => 16,
            'keep_weekly_for_weeks' => 8,
            'keep_monthly_for_months' => 4,
            'keep_yearly_for_years' => 2,
        ],

        'logging' => [
            'channel' => 'craft-backup',
            'level' => 'info',
            /**
             * Email addresses to notify when a run fails or finishes with at least one failed target.
             * Example: ['ops@example.com', 'devops@example.com']. Empty array disables notifications.
             */
            'notify_on_failure' => [],
            /**
             * Email addresses to notify when a run completes successfully.
             * Useful for confirming scheduled backups are actually running. Empty array disables notifications.
             */
            'notify_on_success' => [],
        ],

        'monitor_backups' => [
            [
                'target' => 'local',
                'min_number_of_backups' => 1,
                'youngest_backup_should_be_within_the_last' => '6h'
            ],
            // [
            //     'target' => 'offsite',
            //     'min_number_of_backups' => 1,
            //     'youngest_backup_should_be_within_the_last' => '6h'
            // ],
        ]
    ],
];
