<?php

namespace webhubworks\backup\services\targets;

use webhubworks\backup\events\RegisterTargetDriversEvent;
use webhubworks\backup\exceptions\BackupFailedException;
use webhubworks\backup\services\BackupRunner;
use yii\base\Event;

/**
 * Builds targets from their config definitions. The runner and the monitor share it,
 * so every driver works for uploads, retention, listing, and health checks alike.
 */
final class TargetFactory
{
    /** @var array<string, class-string<TargetInterface>>|null */
    private ?array $drivers = null;

    public function make(array $definition, string $name): TargetInterface
    {
        $driver = (string) ($definition['driver'] ?? '');
        $class = $this->drivers()[$driver] ?? throw new BackupFailedException("Unknown target driver '{$driver}'.");

        return new $class($definition, $name);
    }

    /**
     * @return array<string, class-string<TargetInterface>>
     */
    private function drivers(): array
    {
        if ($this->drivers === null) {
            $event = new RegisterTargetDriversEvent(['drivers' => [
                'local' => LocalTarget::class,
                'sftp' => SftpTarget::class,
            ]]);
            // Class-level, because the monitor and the control panel resolve targets without a runner instance.
            Event::trigger(BackupRunner::class, BackupRunner::EVENT_REGISTER_TARGET_DRIVERS, $event);
            $this->drivers = $event->drivers;
        }

        return $this->drivers;
    }
}
