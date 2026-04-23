<?php

namespace webhubworks\backup\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use webhubworks\backup\Plugin;
use yii\console\ExitCode;

/**
 * ./craft backup/clean
 */
class CleanController extends Controller
{
    use LoadsConfig;

    public ?string $onlyTo = null;
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'onlyTo', 'dryRun'];
    }

    public function optionAliases(): array
    {
        return ['to' => 'onlyTo'];
    }

    public function actionIndex(): int
    {
        $deleted = Plugin::getInstance()->runner->clean($this->loadConfig(), [
            'only_to' => $this->onlyTo,
            'dry_run' => $this->dryRun,
        ]);

        $this->stdout("Deleted {$deleted} backup(s)." . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
