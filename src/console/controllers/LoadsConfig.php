<?php

namespace webhubworks\backup\console\controllers;

use webhubworks\backup\models\BackupConfig;

trait LoadsConfig
{
    protected function loadConfig(): BackupConfig
    {
        return BackupConfig::load();
    }
}
