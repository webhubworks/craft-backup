<?php

namespace webhubworks\backup\console\controllers;

use Craft;
use webhubworks\backup\models\BackupConfig;

trait LoadsConfig
{
    protected function loadConfig(): BackupConfig
    {
        $raw = Craft::$app->config->getConfigFromFile('backup');
        return BackupConfig::fromArray($raw);
    }
}
