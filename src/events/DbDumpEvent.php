<?php

namespace webhubworks\backup\events;

use craft\events\CancelableEvent;
use webhubworks\backup\models\BackupConfig;

/**
 * Fired before each database connection is dumped. Set `$isValid` to false to skip
 * this connection; the rest of the run continues.
 */
class DbDumpEvent extends CancelableEvent
{
    public BackupConfig $config;

    public string $runId;

    /**
     * @var string The database connection's component ID, such as `db`.
     */
    public string $connectionId;

    /**
     * @var string Where the SQL dump is written. Read-only: changing it doesn't move the dump.
     */
    public string $path;

    public bool $dryRun = false;
}
