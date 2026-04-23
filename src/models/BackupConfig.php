<?php

namespace webhubworks\backup\models;

use InvalidArgumentException;

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
        public readonly string $compressionFormat,
        public readonly int $compressionLevel,
        public readonly bool $encryptionEnabled,
        public readonly string $encryptionCipher,
        public readonly ?string $encryptionKey,
        public readonly bool $throttleEnabled,
        public readonly int $throttleBytesPerSecond,
        public readonly array $targets,
        public readonly array $retention,
        public readonly array $logging,
    ) {
    }

    public static function fromArray(array $raw): self
    {
        $source = $raw['source'] ?? [];
        $compression = $raw['compression'] ?? [];
        $encryption = $raw['encryption'] ?? [];
        $throttle = $raw['throttle'] ?? [];

        $config = new self(
            name: (string) ($raw['name'] ?? 'craft-backup'),
            databases: (array) ($source['databases'] ?? ['db']),
            includePaths: (array) ($source['include'] ?? []),
            excludePaths: (array) ($source['exclude'] ?? []),
            followSymlinks: (bool) ($source['follow_symlinks'] ?? false),
            compressionFormat: (string) ($compression['format'] ?? 'tar.gz'),
            compressionLevel: (int) ($compression['level'] ?? 6),
            encryptionEnabled: (bool) ($encryption['enabled'] ?? true),
            encryptionCipher: (string) ($encryption['cipher'] ?? 'aes-256-cbc'),
            encryptionKey: $encryption['key'] ?? null,
            throttleEnabled: (bool) ($throttle['enabled'] ?? false),
            throttleBytesPerSecond: (int) ($throttle['bytes_per_second'] ?? 5 * 1024 * 1024),
            targets: (array) ($raw['targets'] ?? []),
            retention: (array) ($raw['retention'] ?? []),
            logging: (array) ($raw['logging'] ?? []),
        );

        $config->validate();

        return $config;
    }

    private function validate(): void
    {
        if ($this->encryptionEnabled && empty($this->encryptionKey)) {
            throw new InvalidArgumentException('Encryption is enabled but no key was provided.');
        }

        if (empty($this->targets)) {
            throw new InvalidArgumentException('At least one backup target must be configured.');
        }
    }
}
