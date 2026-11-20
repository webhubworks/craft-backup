<?php

namespace webhubworks\backup\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use webhubworks\backup\Plugin;
use yii\console\ExitCode;

/**
 * ./craft backup/sanitize
 */
class SanitizeController extends Controller
{
    use LoadsConfig;

    public ?string $onlyTo = null;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'onlyTo'];
    }

    public function optionAliases(): array
    {
        return ['to' => 'onlyTo'];
    }

    /**
     * Creates a sanitized copy of the database: personal data replaced, secrets removed.
     */
    public function actionIndex(): int
    {
        $result = Plugin::getInstance()->runner->sanitize($this->loadConfig(), $this->onlyTo);

        if ($result->isSuccessful()) {
            $this->stdout($result->summary() . PHP_EOL, Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stderr($result->summary() . PHP_EOL, Console::FG_RED);
        return $result->isPartial() ? 1 : 2;
    }
}
