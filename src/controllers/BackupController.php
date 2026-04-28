<?php

namespace webhubworks\backup\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use Throwable;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\Plugin;
use webhubworks\backup\services\Bytes;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class BackupController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requirePermission('accessCp');

        return $this->renderTemplate('backup/index', self::collectShellData());
    }

    public function actionHealthCard(): Response
    {
        return $this->renderCard('backup/_card_health', function(BackupConfig $config) {
            return [
                'monitor' => Plugin::getInstance()->monitor->check($config),
                'monitorEnabled' => $config->monitorBackups !== [],
                'runState' => Plugin::getInstance()->runState->read(),
                'dateTimeFormat' => $config->dateTimeFormat,
            ];
        });
    }

    public function actionChecksCard(): Response
    {
        return $this->renderCard('backup/_card_checks', function(BackupConfig $config) {
            return [
                'monitor' => Plugin::getInstance()->monitor->check($config),
                'monitorEnabled' => $config->monitorBackups !== [],
            ];
        });
    }

    public function actionBackupsCard(): Response
    {
        return $this->renderCard('backup/_card_backups', function(BackupConfig $config) {
            return [
                'backupsByTarget' => self::collectBackups($config),
                'dateTimeFormat' => $config->dateTimeFormat,
            ];
        });
    }

    /**
     * Renders the initial page shell. Loading the config file is cheap, so we
     * do it eagerly to decide which cards to render skeletons for; everything
     * actually slow happens in the deferred card endpoints.
     *
     * @return array{configError: ?string, monitorEnabled: bool}
     */
    public static function collectShellData(): array
    {
        $configError = null;
        $monitorEnabled = false;

        try {
            $config = BackupConfig::fromArray(Craft::$app->config->getConfigFromFile('backup'));
            $monitorEnabled = $config->monitorBackups !== [];
        } catch (Throwable $e) {
            $configError = $e->getMessage();
        }

        return [
            'configError' => $configError,
            'monitorEnabled' => $monitorEnabled,
        ];
    }

    /**
     * @param callable(BackupConfig): array<string, mixed> $collect
     */
    private function renderCard(string $template, callable $collect): Response
    {
        $this->requirePermission('accessCp');
        $this->requireAcceptsJson();

        try {
            $config = BackupConfig::fromArray(Craft::$app->config->getConfigFromFile('backup'));
        } catch (Throwable $e) {
            throw new BadRequestHttpException($e->getMessage(), 0, $e);
        }

        $variables = $collect($config);

        $html = Craft::$app->getView()->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP);

        return $this->asJson(['html' => $html]);
    }

    /**
     * @return array<string, array{
     *     driver:?string,
     *     backups:array<int, array{name:string, size:int, modified:int, encrypted:?bool}>,
     *     diskUsage:array{total:int, free:int}|null,
     *     warnThreshold:?int,
     * }>
     */
    private static function collectBackups(BackupConfig $config): array
    {
        $status = Plugin::getInstance()->runner->status($config);

        $byTarget = [];
        foreach ($status as $targetName => $entry) {
            $rows = [];
            foreach ($entry['backups'] as $file) {
                $rows[] = [
                    'name' => basename($file['path']),
                    'size' => (int) $file['size'],
                    'modified' => (int) $file['modified'],
                    'encrypted' => $file['encrypted'] ?? null,
                ];
            }

            usort($rows, fn(array $a, array $b) => $b['modified'] <=> $a['modified']);

            $byTarget[$targetName] = [
                'driver' => $config->targets[$targetName]['driver'] ?? null,
                'backups' => $rows,
                'diskUsage' => $entry['diskUsage'],
                'warnThreshold' => self::warnThresholdFor($config, $targetName, $entry['diskUsage']),
            ];
        }

        return $byTarget;
    }

    /**
     * @param array{total:int, free:int}|null $diskUsage
     */
    private static function warnThresholdFor(BackupConfig $config, string $targetName, ?array $diskUsage): ?int
    {
        foreach ($config->monitorBackups as $rule) {
            if (!is_array($rule) || ($rule['target'] ?? null) !== $targetName) {
                continue;
            }
            if (!array_key_exists('warn_when_disk_space_is_lower_than', $rule)) {
                return null;
            }
            try {
                $parsed = Bytes::parseThreshold($rule['warn_when_disk_space_is_lower_than']);
            } catch (Throwable) {
                return null;
            }
            if ($parsed === null) {
                return null;
            }
            // Percentage thresholds need a total; if the driver doesn't expose
            // disk usage there's nothing meaningful to render.
            if (isset($parsed['percent']) && $diskUsage === null) {
                return null;
            }
            return Bytes::resolveThreshold($parsed, $diskUsage['total'] ?? 0);
        }
        return null;
    }
}
