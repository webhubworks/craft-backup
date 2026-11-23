<?php

namespace webhubworks\backup\events;

use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\models\BackupResult;
use yii\base\Event;

/**
 * Fired after a run with its result: after every run, after a failed run, and when
 * targets are low on disk space.
 */
class RunResultEvent extends Event
{
    public BackupConfig $config;

    public BackupResult $result;
}
