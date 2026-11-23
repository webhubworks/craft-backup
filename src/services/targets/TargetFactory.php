<?php

namespace webhubworks\backup\services\targets;

use webhubworks\backup\exceptions\BackupFailedException;

/**
 * Builds targets from their config definitions. The runner and the monitor share it,
 * so every driver works for uploads, retention, listing, and health checks alike.
 */
final class TargetFactory
{
    /** @var array<string, class-string<TargetInterface>> */
    private const DRIVERS = [
        'local' => LocalTarget::class,
        'sftp' => SftpTarget::class,
    ];

    public function make(array $definition, string $name): TargetInterface
    {
        $driver = (string) ($definition['driver'] ?? '');
        $class = self::DRIVERS[$driver] ?? throw new BackupFailedException("Unknown target driver '{$driver}'.");

        return new $class($definition, $name);
    }
}
