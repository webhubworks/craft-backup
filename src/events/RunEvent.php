<?php

namespace webhubworks\backup\events;

use craft\events\CancelableEvent;
use webhubworks\backup\models\BackupConfig;

/**
 * Fired before a backup run starts. Set `$isValid` to false to skip the run.
 */
class RunEvent extends CancelableEvent
{
    public BackupConfig $config;

    public string $runId;

    /**
     * @var array<string, mixed> The run flags: `only_db`, `only_files`, `only_to`, `disable_cleanup`, and `dry_run`.
     */
    public array $flags = [];

    public bool $dryRun = false;
}
