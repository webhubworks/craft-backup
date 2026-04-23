<?php

namespace webhubworks\backup\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use webhubworks\backup\Plugin;
use yii\console\ExitCode;

/**
 * ./craft backup/run
 */
class RunController extends Controller
{
    use LoadsConfig;

    public ?bool $onlyDb = null;
    public ?bool $onlyFiles = null;
    public ?string $onlyTo = null;
    public bool $disableCleanup = false;
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'onlyDb', 'onlyFiles', 'onlyTo', 'disableCleanup', 'dryRun'];
    }

    public function optionAliases(): array
    {
        return [
            'db' => 'onlyDb',
            'files' => 'onlyFiles',
            'to' => 'onlyTo',
        ];
    }

    public function actionIndex(): int
    {
        $result = Plugin::getInstance()->runner->run($this->loadConfig(), [
            'only_db' => $this->onlyDb === true,
            'only_files' => $this->onlyFiles === true,
            'only_to' => $this->onlyTo,
            'disable_cleanup' => $this->disableCleanup,
            'dry_run' => $this->dryRun,
        ]);

        if ($result->isSuccessful()) {
            $this->stdout($result->summary() . PHP_EOL, Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stderr($result->summary() . PHP_EOL, Console::FG_RED);
        return $result->isPartial() ? 1 : 2;
    }
}
