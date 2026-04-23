<?php

namespace webhubworks\backup;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use webhubworks\backup\services\BackupRunner;

/**
 * Craft Backup plugin.
 *
 * @method static Plugin getInstance()
 * @property BackupRunner $runner
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'runner' => BackupRunner::class,
        ]);

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'webhubworks\\backup\\console\\controllers';
        }
    }
}
