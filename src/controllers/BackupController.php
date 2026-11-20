<?php

namespace webhubworks\backup\controllers;

use Craft;
use craft\helpers\Cp;
use craft\helpers\Queue;
use craft\queue\Queue as CraftQueue;
use craft\web\Controller;
use craft\web\View;
use Throwable;
use webhubworks\backup\jobs\SanitizedCopyJob;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\Plugin;
use webhubworks\backup\services\BackupGrouper;
use webhubworks\backup\services\BackupLogger;
use webhubworks\backup\services\Bytes;
use webhubworks\backup\services\notifications\MailChannel;
use webhubworks\backup\services\notifications\Notification;
use webhubworks\backup\services\notifications\Notifier;
use webhubworks\backup\services\targets\TargetInterface;
use webhubworks\backup\utilities\BackupUtility;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class BackupController extends Controller
{
    private const SANITIZE_JOBS_SESSION_KEY = 'backup.sanitizeJobIds';

    protected array|bool|int $allowAnonymous = false;

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
                'downloadMaxBytes' => $config->xSendFileHeader !== null ? null : $config->downloadMaxBytes,
                'canDownload' => Craft::$app->getUser()->checkPermission('backup:download'),
                // Cp::iconSvg() exists from Craft 5 on; Craft 4 shows the text badge alone.
                'sanitizedIcon' => version_compare(Craft::$app->getVersion(), '5.0', '>=') ? Cp::iconSvg('broom') : null,
            ];
        });
    }

    public function actionDownload(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('backup:download');

        try {
            $config = BackupConfig::load();
        } catch (Throwable $e) {
            throw new BadRequestHttpException($e->getMessage(), 0, $e);
        }

        $targetName = (string) $this->request->getRequiredBodyParam('targetName');
        $name = (string) $this->request->getRequiredBodyParam('backupName');

        $def = $config->targets[$targetName] ?? null;
        if (!is_array($def) || ($def['driver'] ?? null) !== 'local') {
            throw new BadRequestHttpException("Downloads are only supported for local targets.");
        }

        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")
            || $name === '' || $name[0] === '.'
            || BackupGrouper::kindOf($name, $config->name) === null
        ) {
            throw new BadRequestHttpException("Invalid backup filename: '{$name}'.");
        }

        $rootAlias = $def['root'] ?? '@storage/backups';
        $root = Craft::getAlias($rootAlias);

        $rootReal = realpath($root);
        $pathReal = realpath($root . DIRECTORY_SEPARATOR . $name);

        if ($rootReal === false || $pathReal === false || !is_file($pathReal)) {
            throw new NotFoundHttpException('Backup not found.');
        }

        // Defense in depth against symlink/.. escapes.
        if (!str_starts_with($pathReal . DIRECTORY_SEPARATOR, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new NotFoundHttpException('Backup not found.');
        }

        $size = (int) filesize($pathReal);

        if ($config->xSendFileHeader === null
            && $config->downloadMaxBytes !== null
            && $size > $config->downloadMaxBytes
        ) {
            throw new BadRequestHttpException('Backup exceeds the configured download size limit.');
        }

        $mimeType = str_ends_with($name, '.zip') ? 'application/zip' : 'application/octet-stream';

        if ($config->xSendFileHeader !== null) {
            $headerPath = $pathReal;
            if ($config->xSendFileHeader === 'X-Accel-Redirect' && $config->xSendFileUriPrefix !== null) {
                $headerPath = rtrim($config->xSendFileUriPrefix, '/') . '/' . $name;
            }
            return $this->response->xSendFile($headerPath, $name, [
                'mimeType' => $mimeType,
                'xHeader' => $config->xSendFileHeader,
            ]);
        }

        // Streaming through PHP: drop any output buffers and lift the time
        // limit so a slow client connection doesn't get cut off mid-download.
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        return $this->response->sendFile($pathReal, $name, [
            'mimeType' => $mimeType,
        ]);
    }

    public function actionSanitize(): Response
    {
        $this->requirePermission('utility:' . BackupUtility::id());
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            BackupConfig::load();
        } catch (Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        // A dump, restore, and rewrite of a big database outlasts the default 300 s TTR.
        $jobId = Queue::push(new SanitizedCopyJob(), null, null, 3600);

        // actionSanitizeStatus() only answers for jobs this session queued.
        $session = Craft::$app->getSession();
        $session->set(self::SANITIZE_JOBS_SESSION_KEY, [...(array) $session->get(self::SANITIZE_JOBS_SESSION_KEY, []), $jobId]);

        return $this->asJson([
            'success' => true,
            'jobId' => $jobId,
            // Server time, so the page can recognize the new copy whatever the browser clock says.
            'queuedAt' => time(),
            'message' => Craft::t('backup', 'Sanitized copy queued. It appears under "Backups by target" when it finishes.'),
        ]);
    }

    /**
     * Lets the utility page follow a queued sanitized copy until it finishes.
     */
    public function actionSanitizeStatus(): Response
    {
        $this->requirePermission('utility:' . BackupUtility::id());
        $this->requireAcceptsJson();

        $jobId = (string) $this->request->getRequiredQueryParam('jobId');
        if (!in_array($jobId, (array) Craft::$app->getSession()->get(self::SANITIZE_JOBS_SESSION_KEY, []), true)) {
            throw new NotFoundHttpException('Unknown job.');
        }
        $queue = Craft::$app->getQueue();
        $status = $queue->status($jobId);

        if ($queue instanceof CraftQueue && $status === CraftQueue::STATUS_FAILED) {
            $error = $queue->getJobDetails($jobId)['error'] ?? null;
            return $this->asJson(['status' => 'failed', 'error' => $error ?: Craft::t('backup', 'Creating the sanitized copy failed. See the queue for details.')]);
        }

        return $this->asJson(['status' => match ($status) {
            CraftQueue::STATUS_DONE => 'done',
            CraftQueue::STATUS_RESERVED => 'running',
            default => 'waiting',
        }]);
    }

    public function actionNotificationsCard(): Response
    {
        return $this->renderCard('backup/_card_notifications', function(BackupConfig $config) {
            return [
                'channels' => self::collectNotificationChannels($config),
            ];
        });
    }

    public function actionTestNotification(): Response
    {
        $this->requirePermission('utility:' . BackupUtility::id());
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $config = BackupConfig::load();
        } catch (Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        $channel = (string) $this->request->getRequiredBodyParam('channel');
        $error = (new Notifier($config, new BackupLogger()))->sendTest($channel);

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
            $config = BackupConfig::load();
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
        $this->requirePermission('utility:' . BackupUtility::id());
        $this->requireAcceptsJson();

        try {
            $config = BackupConfig::load();
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
        $eventLabels = [
            Notification::EVENT_FAILURE => Craft::t('backup', 'On failure'),
            Notification::EVENT_SUCCESS => Craft::t('backup', 'On success'),
            Notification::EVENT_LOW_DISK_SPACE => Craft::t('backup', 'On low disk space'),
        ];

        $channels = [];
        foreach ((new Notifier($config, new BackupLogger()))->channels() as $id => $channel) {
            if (!$channel->isConfigured()) {
                continue;
            }

            $events = [];
            foreach ($eventLabels as $event => $label) {
                $events[] = $channel instanceof MailChannel
                    ? ['event' => $event, 'label' => $label, 'recipients' => $channel->recipients($event)]
                    : ['event' => $event, 'label' => $label, 'enabled' => $channel->wants($event)];
            }

            $channels[] = [
                'id' => $id,
                'name' => $channel->label(),
                'detail' => $channel->detail(),
                'events' => $events,
                'hasTestAction' => $channel->supportsTest(),
            ];
        }

        return $channels;
    }

    /**
     * @return array<string, array{
     *     driver:?string,
     *     backups:array<int, array{name:string, path:string, size:int, modified:int, encrypted:?bool, kind:string}>,
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
            $files = [
                ...array_map(fn(array $file) => [...$file, 'kind' => TargetInterface::KIND_BACKUP], $entry['backups']),
                ...array_map(fn(array $file) => [...$file, 'kind' => TargetInterface::KIND_SANITIZED], $entry['sanitized']),
                ...array_map(fn(array $file) => [...$file, 'kind' => TargetInterface::KIND_CRAFT], $entry['craft']),
            ];
            foreach ($files as $file) {
                $size = (int) $file['size'];
                $bytes += $size;
                $rows[] = [
                    'name' => basename($file['path']),
                    'path' => (string) $file['path'],
                    'size' => $size,
                    'modified' => (int) $file['modified'],
                    'encrypted' => $file['encrypted'] ?? null,
                    'kind' => $file['kind'],
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
