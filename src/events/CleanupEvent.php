<?php

namespace webhubworks\backup\events;

use Throwable;
use webhubworks\backup\models\BackupConfig;
use yii\base\Event;

/**
 * Fired after retention ran on one target, or failed on it.
 */
class CleanupEvent extends Event
{
    public BackupConfig $config;

    /**
     * @var string The target's name from the `targets` config.
     */
    public string $target;

    /**
     * @var string|null The run ID, or null when the cleanup comes from `backup/clean`.
     */
    public ?string $runId = null;

    /**
     * @var int How many backups were deleted. A split backup counts once.
     */
    public int $deleted = 0;

    /**
     * @var Throwable|null Why the cleanup failed.
     */
    public ?Throwable $exception = null;
}
