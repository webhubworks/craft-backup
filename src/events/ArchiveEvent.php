<?php

namespace webhubworks\backup\events;

use webhubworks\backup\models\BackupConfig;
use yii\base\Event;

/**
 * Fired once per archive after it is built and, when configured, encrypted.
 * The archive is deleted from the temp folder at the end of the run.
 */
class ArchiveEvent extends Event
{
    public BackupConfig $config;

    public string $runId;

    /**
     * @var string Local path of the finished archive. Read-only: changing it doesn't swap the archive.
     */
    public string $path;

    /**
     * @var string|null `db` or `files` when `source.split_db_and_files` is on, otherwise null.
     */
    public ?string $kind = null;

    public int $bytes = 0;

    public bool $encrypted = false;

    public bool $dryRun = false;
}
