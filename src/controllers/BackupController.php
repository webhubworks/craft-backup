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

    public function actionNotificationsCard(): Response
    {
        return $this->renderCard('backup/_card_notifications', function(BackupConfig $config) {
            return [
                'channels' => self::collectNotificationChannels($config),
            ];
        });
    }

    public function actionTestSlack(): Response
    {
        $this->requirePermission('accessCp');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $config = BackupConfig::fromArray(Craft::$app->config->getConfigFromFile('backup'));
        } catch (Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        $error = Plugin::getInstance()->runner->sendTestSlack($config);

        return $this->asJson($error === null ? ['success' => true] : ['success' => false, 'error' => $error]);
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

        $html = trim(Craft::$app->getView()->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP));

        return $this->asJson(['html' => $html]);
    }

    /**
     * @return list<array{
     *     id:string,
     *     name:string,
     *     detail:?string,
     *     events:list<array{event:string, label:string, recipients?:list<string>, enabled?:bool}>,
     *     hasTestAction:bool,
     * }>
     */
    private static function collectNotificationChannels(BackupConfig $config): array
    {
        $eventKeys = ['on_failure', 'on_success', 'on_low_disk_space'];
        $eventLabels = [
            'on_failure' => Craft::t('backup', 'On failure'),
            'on_success' => Craft::t('backup', 'On success'),
            'on_low_disk_space' => Craft::t('backup', 'On low disk space'),
        ];

        $channels = [];

        $mail = (array) ($config->notifications['mail'] ?? []);
        $mailEvents = [];
        $mailHasAny = false;
        foreach ($eventKeys as $key) {
            $recipients = array_values(array_filter(array_map('strval', (array) ($mail[$key] ?? []))));
            $mailEvents[] = ['event' => $key, 'label' => $eventLabels[$key], 'recipients' => $recipients];
            if ($recipients !== []) {
                $mailHasAny = true;
            }
        }
        if ($mailHasAny) {
            $channels[] = [
                'id' => 'mail',
                'name' => Craft::t('backup', 'Mail'),
                'detail' => null,
                'events' => $mailEvents,
                'hasTestAction' => false,
            ];
        }

        $slack = (array) ($config->notifications['slack'] ?? []);
        $webhookUrl = $slack['webhook_url'] ?? null;
        if (is_string($webhookUrl) && $webhookUrl !== '') {
            $slackEvents = [];
            foreach ($eventKeys as $key) {
                $slackEvents[] = ['event' => $key, 'label' => $eventLabels[$key], 'enabled' => (bool) ($slack[$key] ?? false)];
            }
            $detail = !empty($slack['channel']) ? (string) $slack['channel'] : null;
            $channels[] = [
                'id' => 'slack',
                'name' => Craft::t('backup', 'Slack'),
                'detail' => $detail,
                'events' => $slackEvents,
                'hasTestAction' => true,
            ];
        }

        return $channels;
    }

    /**
     * @return array<string, array{
     *     driver:?string,
     *     backups:array<int, array{name:string, size:int, modified:int, encrypted:?bool}>,
     *     backupsBytes:int,
     *     diskUsage:array{total:int, free:int}|null,
     *     warnThreshold:array{bytes:int, percent:?float}|null,
     * }>
     */
    private static function collectBackups(BackupConfig $config): array
    {
        $status = Plugin::getInstance()->runner->status($config);

        $byTarget = [];
        foreach ($status as $targetName => $entry) {
            $rows = [];
            $bytes = 0;
            foreach ($entry['backups'] as $file) {
                $size = (int) $file['size'];
                $bytes += $size;
                $rows[] = [
                    'name' => basename($file['path']),
                    'size' => $size,
                    'modified' => (int) $file['modified'],
                    'encrypted' => $file['encrypted'] ?? null,
                ];
            }

            usort($rows, fn(array $a, array $b) => $b['modified'] <=> $a['modified']);

            $byTarget[$targetName] = [
                'driver' => $config->targets[$targetName]['driver'] ?? null,
                'backups' => $rows,
                'backupsBytes' => $bytes,
                'diskUsage' => $entry['diskUsage'],
                'warnThreshold' => self::warnThresholdFor($config, $targetName, $entry['diskUsage']),
            ];
        }

        return $byTarget;
    }

    /**
     * @param array{total:int, free:int}|null $diskUsage
     * @return array{bytes:int, percent:?float}|null
     */
    private static function warnThresholdFor(BackupConfig $config, string $targetName, ?array $diskUsage): ?array
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
            return [
                'bytes' => Bytes::resolveThreshold($parsed, $diskUsage['total'] ?? 0),
                'percent' => $parsed['percent'] ?? null,
            ];
        }
        return null;
    }
}
