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
 *   BACKUP_SLACK_WEBHOOK_URL   — overrides 'notifications.slack.webhook_url'
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
         *   delete_oldest_backups_when_using_more_megabytes_than
         *                           Optional hard size cap, in megabytes,
         *                           applied AFTER the GFS rules above. If the
         *                           total size of the kept backups exceeds
         *                           this value, the oldest are removed one by
         *                           one until the total fits. The newest
         *                           backup is always retained, even if it
         *                           alone exceeds the cap. Set to null to
         *                           disable the cap.
         */
        'retention' => [
            'keep_all_for_days' => 7,
            'keep_daily_for_days' => 16,
            'keep_weekly_for_weeks' => 8,
            'keep_monthly_for_months' => 4,
            'keep_yearly_for_years' => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],

        'logging' => [
            'channel' => 'craft-backup',
            'level' => 'info',
        ],

        /**
         * Notifications.
         *
         * Three events are reported:
         *   on_failure         A run failed or finished with at least one failed target.
         *   on_success         A run completed successfully (useful for confirming scheduled backups run).
         *   on_low_disk_space  After a run, a target's free disk space dropped below its
         *                      'warn_when_disk_space_is_lower_than' threshold (see monitor_backups
         *                      below). Only fires for targets whose driver can report disk usage
         *                      (currently 'local').
         *
         * Each channel decides per-event whether to fire.
         */
        'notifications' => [
            /**
             * Mail. Each event holds a list of recipient addresses; an empty array
             * disables that event. Examples:
             *   'on_failure' => ['ops@example.com', 'devops@example.com'],
             */
            'mail' => [
                'on_failure' => [],
                'on_success' => [],
                'on_low_disk_space' => [],
            ],

            /**
             * Slack. Posts to an Incoming Webhook (https://api.slack.com/messaging/webhooks).
             * Set 'webhook_url' to null (or leave BACKUP_SLACK_WEBHOOK_URL unset) to disable
             * Slack entirely; per-event flags then have no effect.
             */
            'slack' => [
                // Incoming Webhook URL. Override via BACKUP_SLACK_WEBHOOK_URL in .env.
                'webhook_url' => App::env('BACKUP_SLACK_WEBHOOK_URL') ?: null,
                // Optional channel override (e.g. '#ops'). Null uses the webhook's default channel.
                'channel' => null,
                // Display name. Null uses the webhook's default name.
                'username' => 'Craft Backup',
                // Emoji shortcode (':floppy_disk:') or full image URL. Null uses the webhook's default icon.
                'icon' => ':floppy_disk:',
                // Per-event toggles.
                'on_failure' => true,
                'on_success' => false,
                'on_low_disk_space' => true,
            ],
        ],

        /**
         * Per-target health rules.
         *
         * Each rule supports:
         *
         *   target                                    The target name from 'targets' (required).
         *   min_number_of_backups                     Optional. Minimum count expected on the target.
         *   youngest_backup_should_be_within_the_last Optional. Max age of the newest backup, e.g. '6h', '2d'.
         *   warn_when_disk_space_is_lower_than        Optional. Free-disk warning threshold for the
         *                                             volume backing the target. Accepts:
         *                                               - byte counts ('524288000')
         *                                               - shorthand ('500MB', '5GB', '1TB')
         *                                               - percentage of total disk ('20%', '12.5%') —
         *                                                 e.g. '20%' on a 1TB volume warns when free
         *                                                 drops below 200GB
         *                                             When set, the "Backups by target" card draws a
         *                                             warn marker on the disk usage bar; falling
         *                                             below the threshold turns the bar red and
         *                                             triggers a notification on
         *                                             notifications.*.on_low_disk_space after the
         *                                             next backup run. Default: null. Only honored
         *                                             for targets whose driver can report disk usage
         *                                             (currently 'local').
         */
        'monitor_backups' => [
            [
                'target' => 'local',
                'min_number_of_backups' => 1,
                'youngest_backup_should_be_within_the_last' => '6h',
                'warn_when_disk_space_is_lower_than' => null,
            ],
            // [
            //     'target' => 'offsite',
            //     'min_number_of_backups' => 1,
            //     'youngest_backup_should_be_within_the_last' => '6h'
            // ],
        ],

        /**
         * Optional PHP `date()` format applied to the timestamps shown in the
         * "Backup health" and "Backups by target" cards on the Backup utility.
         * Leave unset to use the default ('D, M j, Y g:i A').
         *
         * Example: 'D, d.m.y, H:i:s' for 'Mo., 27.04.26, 12:47:29'.
         */
         'date_time_format' => null,
    ],
];
