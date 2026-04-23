<?php

/**
 * Default Craft Backup configuration.
 *
 * Copy this file to your project's config/ directory as backup.php and
 * override the values you care about. The file is multi-environment aware in
 * the same way general.php is.
 */

return [
    '*' => [
        // Used as prefix for generated archive filenames.
        'name' => 'craft-backup',

        'source' => [
            // Craft DB connection component IDs to dump. Use ['db'] for the default connection.
            'databases' => ['db'],

            // Paths (absolute or Yii aliases) to include in the files portion.
            'include' => [
                '@root/config',
                '@storage/rebrand',
                '@webroot/uploads',
            ],

            // Paths or glob patterns to exclude (applied relative to each included root).
            'exclude' => [
                '@storage/runtime',
                '@storage/logs',
                '*.DS_Store',
            ],

            'follow_symlinks' => false,
        ],

        'compression' => [
            'format' => 'tar.gz',
            'level' => 6,
        ],

        'encryption' => [
            'enabled' => false,
            'cipher' => 'aes-256-cbc',
            // Base64-encoded 32-byte key. Store in a password manager and inject via env.
            'key' => null,
        ],

        'throttle' => [
            'enabled' => false,
            'bytes_per_second' => 5 * 1024 * 1024,
        ],

        // Each target maps a name to a driver config. Uploads run against every enabled target.
        'targets' => [
            'local' => [
                'driver' => 'local',
                'root' => '@storage/backups',
            ],
            // 'offsite' => [
            //     'driver' => 'sftp',
            //     'host' => null,
            //     'port' => 22,
            //     'username' => null,
            //     'password' => null,
            //     'private_key' => null,
            //     'passphrase' => null,
            //     'root' => '/backups',
            //     'timeout' => 30,
            // ],
        ],

        'retention' => [
            'keep_all_for_days' => 7,
            'keep_daily_for_days' => 30,
            'keep_weekly_for_weeks' => 8,
            'keep_monthly_for_months' => 12,
            'keep_yearly_for_years' => 3,
            'delete_oldest_when_larger_than_mb' => null,
        ],

        'logging' => [
            'channel' => 'craft-backup',
            'level' => 'info',
            'notify_on_failure' => [],
        ],
    ],
];
