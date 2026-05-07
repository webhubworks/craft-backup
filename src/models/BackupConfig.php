<?php

namespace webhubworks\backup\models;

use Craft;
use InvalidArgumentException;
use webhubworks\backup\services\Bytes;

/**
 * Immutable, validated snapshot of the plugin config at the start of a run.
 */
final class BackupConfig
{
    public function __construct(
        public readonly string $name,
        public readonly array $databases,
        public readonly array $includePaths,
        public readonly array $excludePaths,
        public readonly bool $followSymlinks,
        public readonly bool $splitDbAndFiles,
        public readonly string $compressionFormat,
        public readonly int $compressionLevel,
        public readonly ?string $archivePassword,
        public readonly bool $encryptionEnabled,
        public readonly string $encryptionCipher,
        public readonly ?string $encryptionKey,
        public readonly bool $throttleEnabled,
        public readonly int $throttleBytesPerSecond,
        public readonly array $targets,
        public readonly array $retention,
        public readonly array $logging,
        public readonly array $notifications,
        public readonly array $monitorBackups,
        public readonly ?string $dateTimeFormat,
        public readonly ?int $downloadMaxBytes,
        public readonly ?string $xSendFileHeader,
        public readonly ?string $xSendFileUriPrefix,
    ) {
    }

    public static function load(): self
    {
        $bundled = require dirname(__DIR__) . '/config.php';
        $defaults = $bundled['*'] ?? [];
        $project = Craft::$app->config->getConfigFromFile('backup');

        return self::fromArray(self::deepMerge($defaults, $project));
    }

    /**
     * Recursively merges the project config on top of the bundled defaults.
     *
     * Maps (string-keyed arrays) merge by key so users only need to override
     * the leaves they care about. Lists (numeric-keyed arrays — monitor_backups
     * rules, source.include/exclude paths, notification recipient lists) are
     * replaced wholesale, so a project override doesn't silently inherit a
     * default entry alongside its own.
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !array_is_list($value)
                && !array_is_list($base[$key])
            ) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    public static function fromArray(array $raw): self
    {
        $source = $raw['source'] ?? [];
        $compression = $raw['compression'] ?? [];
        $encryption = $raw['encryption'] ?? [];
        $throttle = $raw['throttle'] ?? [];
        $download = $raw['download'] ?? [];

        $xSendFileHeader = $download['x_send_file'] ?? null;
        if ($xSendFileHeader !== null) {
            $xSendFileHeader = (string) $xSendFileHeader;
            if (!in_array($xSendFileHeader, ['X-Sendfile', 'X-Accel-Redirect'], true)) {
                throw new InvalidArgumentException("download.x_send_file must be 'X-Sendfile', 'X-Accel-Redirect', or null.");
            }
        }

        $xSendFileUriPrefix = $download['x_send_file_uri_prefix'] ?? null;
        if ($xSendFileUriPrefix !== null) {
            $xSendFileUriPrefix = (string) $xSendFileUriPrefix;
            if ($xSendFileUriPrefix === '' || $xSendFileUriPrefix[0] !== '/') {
                throw new InvalidArgumentException("download.x_send_file_uri_prefix must start with '/'.");
            }
        }

        $config = new self(
            name: (string) ($raw['name'] ?? 'craft-backup'),
            databases: (array) ($source['databases'] ?? ['db']),
            includePaths: (array) ($source['include'] ?? []),
            excludePaths: (array) ($source['exclude'] ?? []),
            followSymlinks: (bool) ($source['follow_symlinks'] ?? false),
            splitDbAndFiles: (bool) ($source['split_db_and_files'] ?? false),
            compressionFormat: (string) ($compression['format'] ?? 'tar.gz'),
            compressionLevel: (int) ($compression['level'] ?? 6),
            archivePassword: isset($compression['password']) && $compression['password'] !== '' ? (string) $compression['password'] : null,
            encryptionEnabled: (bool) ($encryption['enabled'] ?? true),
            encryptionCipher: (string) ($encryption['cipher'] ?? 'aes-256-cbc'),
            encryptionKey: $encryption['key'] ?? null,
            throttleEnabled: (bool) ($throttle['enabled'] ?? false),
            throttleBytesPerSecond: (int) ($throttle['bytes_per_second'] ?? 5 * 1024 * 1024),
            targets: (array) ($raw['targets'] ?? []),
            retention: (array) ($raw['retention'] ?? []),
            logging: (array) ($raw['logging'] ?? []),
            notifications: (array) ($raw['notifications'] ?? []),
            monitorBackups: (array) ($raw['monitor_backups'] ?? []),
            dateTimeFormat: isset($raw['date_time_format']) && $raw['date_time_format'] !== '' ? (string) $raw['date_time_format'] : null,
            downloadMaxBytes: Bytes::parse($download['max_bytes'] ?? '500MB'),
            xSendFileHeader: $xSendFileHeader,
            xSendFileUriPrefix: $xSendFileUriPrefix,
        );

        $config->validate();

        return $config;
    }

    private function validate(): void
    {
        if (!in_array($this->compressionFormat, ['tar.gz', 'zip'], true)) {
            throw new InvalidArgumentException("Unsupported compression.format '{$this->compressionFormat}'. Use 'tar.gz' or 'zip'.");
        }

        if ($this->compressionFormat === 'tar.gz' && $this->archivePassword !== null) {
            throw new InvalidArgumentException("compression.password is only supported with compression.format = 'zip'.");
        }

        if ($this->compressionFormat === 'zip' && $this->encryptionEnabled) {
            throw new InvalidArgumentException("compression.format = 'zip' uses its own password-based encryption; disable the 'encryption' block or use compression.format = 'tar.gz'.");
        }

        if ($this->encryptionEnabled && empty($this->encryptionKey)) {
            throw new InvalidArgumentException('Encryption is enabled but no key was provided.');
        }

        if (empty($this->targets)) {
            throw new InvalidArgumentException('At least one backup target must be configured.');
        }
    }
}
