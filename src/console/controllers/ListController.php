<?php

namespace webhubworks\backup\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use webhubworks\backup\Plugin;
use yii\console\ExitCode;

/**
 * ./craft backup/list
 */
class ListController extends Controller
{
    use LoadsConfig;

    public function actionIndex(): int
    {
        foreach (Plugin::getInstance()->runner->status($this->loadConfig()) as $targetName => $entry) {
            $this->stdout(PHP_EOL . $targetName . PHP_EOL, Console::FG_YELLOW);
            if ($entry['error'] !== null) {
                $this->stderr("  Could not list backups: {$entry['error']}" . PHP_EOL, Console::FG_RED);
            }
            foreach ($entry['backups'] as $file) {
                $this->stdout(sprintf("  %s  %10d bytes  %s\n", $file['modified'], $file['size'], $file['path']));
            }
        }

        return ExitCode::OK;
    }
}
