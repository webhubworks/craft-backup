<?php

namespace webhubworks\backup\controllers;

use Craft;
use craft\web\Controller;
use Throwable;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\Plugin;
use yii\web\Response;

class BackupController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requirePermission('accessCp');

        return $this->renderTemplate('backup/index', self::collectStatusData());
    }

    /**
     * @return array{configError: ?string, monitor: mixed, monitorEnabled: bool, runState: array, backupsByTarget: array<string, array<int, array{name:string, size:int, modified:int}>>}
     */
    public static function collectStatusData(): array
    {
        $configError = null;
        $config = null;
        try {
            $raw = Craft::$app->config->getConfigFromFile('backup');
            $config = BackupConfig::fromArray($raw);
        } catch (Throwable $e) {
            $configError = $e->getMessage();
        }

        $monitor = null;
        $backupsByTarget = [];
        if ($config !== null) {
            try {
                $monitor = Plugin::getInstance()->monitor->check($config);
            } catch (Throwable $e) {
                $monitor = ['status' => 'failure', 'reason' => $e->getMessage()];
            }

            $backupsByTarget = self::collectBackups($config);
        }

        return [
            'configError' => $configError,
            'monitor' => $monitor,
            'monitorEnabled' => $config !== null && $config->monitorBackups !== [],
            'runState' => Plugin::getInstance()->runState->read(),
            'backupsByTarget' => $backupsByTarget,
        ];
    }

    /**
     * @return array<string, array<int, array{name:string, size:int, modified:int}>>
     */
    private static function collectBackups(BackupConfig $config): array
    {
        try {
            $listings = Plugin::getInstance()->runner->list($config);
        } catch (Throwable) {
            return [];
        }

        $byTarget = [];
        foreach ($listings as $targetName => $files) {
            $rows = [];
            foreach ($files as $file) {
                $rows[] = [
                    'name' => basename($file['path']),
                    'size' => (int) $file['size'],
                    'modified' => (int) $file['modified'],
                ];
            }

            usort($rows, fn(array $a, array $b) => $b['modified'] <=> $a['modified']);

            $byTarget[$targetName] = $rows;
        }

        return $byTarget;
    }
}
