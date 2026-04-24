<?php

namespace webhubworks\backup\console\controllers;

use craft\console\Controller;
use Throwable;
use webhubworks\backup\Plugin;
use yii\console\ExitCode;

/**
 * ./craft backup/monitor
 *
 * Evaluates the configured monitor_backups rules and prints a JSON result.
 * Exits 0 when all checks pass, 1 otherwise.
 */
class MonitorController extends Controller
{
    use LoadsConfig;

    public function actionIndex(): int
    {
        try {
            $config = $this->loadConfig();
            $result = Plugin::getInstance()->monitor->check($config);
        } catch (Throwable $e) {
            $result = [
                'status' => 'failure',
                'reason' => $e->getMessage(),
            ];
        }

        $this->stdout(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return $result['status'] === 'ok' ? ExitCode::OK : 1;
    }
}
