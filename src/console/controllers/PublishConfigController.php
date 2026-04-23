<?php

namespace webhubworks\backup\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * ./craft backup/publish-config
 */
class PublishConfigController extends Controller
{
    public function actionIndex(): int
    {
        $source = dirname(__DIR__, 2) . '/config.php';
        $destination = Craft::getAlias('@config/backup.php');

        if (file_exists($destination)) {
            $this->stderr("Config already exists at {$destination}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        copy($source, $destination);
        $this->stdout("Published to {$destination}\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
