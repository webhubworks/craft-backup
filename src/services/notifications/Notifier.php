<?php

namespace webhubworks\backup\services\notifications;

use Craft;
use Throwable;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\models\BackupResult;
use webhubworks\backup\services\BackupLogger;
use webhubworks\backup\services\Bytes;

/**
 * Builds each message once and hands it to every channel that wants the event.
 * A failing channel is logged and never stops the others.
 */
final class Notifier
{
    public function __construct(
        private readonly BackupConfig $config,
        private readonly BackupLogger $logger,
    ) {
    }

    /**
     * @return array<string, NotificationChannel>
     */
    public function channels(): array
    {
        $settings = $this->config->notifications;

        return [
            'mail' => new MailChannel((array) ($settings['mail'] ?? [])),
            'slack' => new SlackChannel((array) ($settings['slack'] ?? [])),
            'discord' => new DiscordChannel((array) ($settings['discord'] ?? [])),
            'webhook' => new WebhookChannel((array) ($settings['webhook'] ?? [])),
        ];
    }

    public function notifyRun(BackupResult $result): void
    {
        $successful = $result->isSuccessful();
        $siteName = $this->siteName();
        $intro = $successful ? 'Backup run completed successfully.' : 'A backup run did not complete successfully.';

        $lines = [
            $intro,
            '',
            "Site:     {$siteName}",
            "Run ID:   {$result->runId}",
            sprintf('Duration: %.1fs', $result->durationSeconds),
            'Size:     ' . Bytes::format($result->archiveBytes),
        ];

        if ($result->archivePaths !== []) {
            $lines[] = 'Archives:';
            foreach ($result->archivePaths as $path) {
                $lines[] = '  - ' . basename($path);
            }
        } else {
            $lines[] = 'Archive:  (not produced)';
        }

        $lines[] = '';
        $lines[] = 'Targets:';
        foreach ($result->targetStatuses as $name => $status) {
            $lines[] = "  - {$name}: {$status}";
        }
        if ($result->targetStatuses === []) {
            $lines[] = '  (none reached)';
        }
        if ($result->errors !== []) {
            $lines[] = '';
            $lines[] = 'Errors:';
            foreach ($result->errors as $error) {
                $lines[] = "  - {$error}";
            }
        }

        $this->dispatch(new Notification(
            event: $successful ? Notification::EVENT_SUCCESS : Notification::EVENT_FAILURE,
            siteName: $siteName,
            subject: sprintf('[Craft Backup] %s on %s', $successful ? 'Success' : 'Failure', $siteName),
            intro: $intro,
            body: implode(PHP_EOL, $lines),
            result: $result,
        ));
    }

    public function notifyLowDisk(BackupResult $result): void
    {
        if ($result->lowDiskTargets === []) {
            return;
        }

        $siteName = $this->siteName();
        $intro = 'One or more backup targets fell below their configured free-disk warning threshold after the latest backup.';

        $lines = [$intro, '', "Site:   {$siteName}", "Run ID: {$result->runId}", '', 'Targets:'];
        foreach ($result->lowDiskTargets as $name => $info) {
            $lines[] = sprintf(
                '  - %s: %s free of %s (threshold %s)',
                $name,
                Bytes::format($info['free']),
                Bytes::format($info['total']),
                Bytes::format($info['threshold']),
            );
        }

        $this->dispatch(new Notification(
            event: Notification::EVENT_LOW_DISK_SPACE,
            siteName: $siteName,
            subject: "[Craft Backup] Low disk space on {$siteName}",
            intro: $intro,
            body: implode(PHP_EOL, $lines),
            result: $result,
        ));
    }

    /**
     * Sends a test message through one channel, ignoring its per-event toggles.
     * Returns null on success, or an error message.
     */
    public function sendTest(string $channelId): ?string
    {
        $channel = $this->channels()[$channelId] ?? null;
        if ($channel === null || !$channel->supportsTest()) {
            return "Channel '{$channelId}' has no test notification.";
        }
        if (!$channel->isConfigured()) {
            return "{$channel->label()} is not configured.";
        }

        $siteName = $this->siteName();
        $intro = "If you can read this, {$channel->label()} notifications are wired up correctly.";

        try {
            $channel->send(new Notification(
                event: Notification::EVENT_TEST,
                siteName: $siteName,
                subject: "[Craft Backup] Test notification from {$siteName}",
                intro: $intro,
                body: $intro,
            ));
            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    private function dispatch(Notification $notification): void
    {
        foreach ($this->channels() as $id => $channel) {
            if (!$channel->wants($notification->event)) {
                continue;
            }
            try {
                $channel->send($notification);
                $context = $channel instanceof MailChannel ? ['to' => $channel->recipients($notification->event)] : [];
                $this->logger->info("{$id} notification sent ({$notification->event})", $context);
            } catch (Throwable $e) {
                $this->logger->warning("Failed to send {$id} notification ({$notification->event})", ['error' => $e->getMessage()]);
            }
        }
    }

    private function siteName(): string
    {
        return Craft::$app->getSystemName() ?: $this->config->name;
    }
}
