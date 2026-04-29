<?php

namespace webhubworks\backup\services;

use Craft;
use craft\helpers\FileHelper;
use Throwable;
use webhubworks\backup\exceptions\BackupFailedException;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\models\BackupResult;
use webhubworks\backup\services\targets\LocalTarget;
use webhubworks\backup\services\targets\SftpTarget;
use webhubworks\backup\services\targets\TargetInterface;
use yii\base\Component;

/**
 * Orchestrates a backup run: DB dump → file collection → archive → encrypt →
 * upload to each target → apply retention → log.
 */
class BackupRunner extends Component
{
    public function run(BackupConfig $config, array $flags = []): BackupResult
    {
        $runId = bin2hex(random_bytes(4));
        $startedAt = microtime(true);

        $logger = $this->logger($runId);
        $logger->info('Backup run starting', ['dry_run' => (bool) ($flags['dry_run'] ?? false)]);

        $stagingDir = $this->makeStagingDir($config->name, $runId);
        $archivePath = null;
        $archiveBytes = 0;
        $targetStatuses = [];
        $errors = [];
        $lowDiskTargets = [];

        try {
            if (!($flags['only_files'] ?? false)) {
                $dumps = (new DbDumper())->dump($config->databases, $stagingDir);
                $logger->info('Dumped databases', ['files' => array_map('basename', $dumps)]);
            }

            if (!($flags['only_db'] ?? false)) {
                $this->stageFiles($config, $stagingDir, $logger);
            }

            $archivePath = $this->buildArchive($config, $runId, $stagingDir, $logger);
            $archiveBytes = (int) filesize($archivePath);

            if ($config->encryptionEnabled) {
                $archivePath = $this->encryptArchive($config, $archivePath, $logger);
                $archiveBytes = (int) filesize($archivePath);
            }

            if ($flags['dry_run'] ?? false) {
                $logger->info('Dry run: skipping upload and cleanup', ['archive' => $archivePath]);
                return $this->result($runId, $archivePath, $archiveBytes, $startedAt, $targetStatuses, $errors);
            }

            foreach ($this->targetsFor($config, $flags['only_to'] ?? null) as $name => $target) {
                try {
                    $target->upload($archivePath, basename($archivePath), $config);
                    $targetStatuses[$name] = 'ok';
                    $logger->info("Uploaded to {$name}");

                    $low = $this->checkLowDisk($name, $target, $config);
                    if ($low !== null) {
                        $lowDiskTargets[$name] = $low;
                        $logger->warning("Free disk on {$name} is below the configured warning threshold", $low);
                    }
                } catch (Throwable $e) {
                    $targetStatuses[$name] = 'failed: ' . $e->getMessage();
                    $errors[] = "target {$name}: " . $e->getMessage();
                    $logger->error("Upload to {$name} failed", ['error' => $e->getMessage()]);
                }
            }

            if (!($flags['disable_cleanup'] ?? false)) {
                $policy = new RetentionPolicy();
                foreach ($this->targetsFor($config, $flags['only_to'] ?? null) as $name => $target) {
                    try {
                        $deleted = $policy->apply($target, $config->retention);
                        $logger->info("Pruned {$deleted} old backup(s) on {$name}");
                    } catch (Throwable $e) {
                        $logger->warning("Retention on {$name} failed", ['error' => $e->getMessage()]);
                    }
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $logger->error('Backup run failed', ['error' => $e->getMessage()]);
        } finally {
            FileHelper::removeDirectory($stagingDir);
        }

        $result = $this->result($runId, $archivePath, $archiveBytes, $startedAt, $targetStatuses, $errors, $lowDiskTargets);

        if (!($flags['dry_run'] ?? false)) {
            (new RunStateStore())->record($result);
            $this->notify($config, $result, $logger);
            $this->notifyLowDisk($config, $result, $logger);
        }

        return $result;
    }

    /**
     * @return array<string, array<int, array{path:string, size:int, modified:int}>>
     */
    public function list(BackupConfig $config): array
    {
        $out = [];
        foreach ($this->targetsFor($config, null) as $name => $target) {
            $out[$name] = $target->list();
        }
        return $out;
    }

    /**
     * Per-target snapshot used by the Backups-by-target card: listing plus
     * current disk usage of the volume backing the target (null when the
     * driver doesn't expose it, e.g. SFTP).
     *
     * @return array<string, array{
     *     backups: array<int, array{path:string, size:int, modified:int, encrypted:?bool}>,
     *     diskUsage: array{total:int, free:int}|null,
     * }>
     */
    public function status(BackupConfig $config): array
    {
        $out = [];
        foreach ($this->targetsFor($config, null) as $name => $target) {
            $out[$name] = [
                'backups' => $target->list(),
                'diskUsage' => $target->diskUsage(),
            ];
        }
        return $out;
    }

    public function clean(BackupConfig $config, array $flags = []): int
    {
        $policy = new RetentionPolicy();
        $total = 0;
        foreach ($this->targetsFor($config, $flags['only_to'] ?? null) as $target) {
            $total += $policy->apply($target, $config->retention, (bool) ($flags['dry_run'] ?? false));
        }
        return $total;
    }

    private function stageFiles(BackupConfig $config, string $stagingDir, BackupLogger $logger): void
    {
        $root = Craft::getAlias('@root') ?: '';
        $filesDir = $stagingDir . DIRECTORY_SEPARATOR . 'files';
        FileHelper::createDirectory($filesDir);

        $count = 0;
        foreach ((new SourceCollector())->collect($config) as $file) {
            $source = $file->getPathname();
            $relative = is_string($root) && str_starts_with($source, $root)
                ? ltrim(substr($source, strlen($root)), DIRECTORY_SEPARATOR)
                : ltrim($source, DIRECTORY_SEPARATOR);

            $dest = $filesDir . DIRECTORY_SEPARATOR . $relative;
            FileHelper::createDirectory(dirname($dest));
            copy($source, $dest);
            $count++;
        }

        $logger->info("Staged {$count} file(s)");
    }

    private function buildArchive(BackupConfig $config, string $runId, string $stagingDir, BackupLogger $logger): string
    {
        $extension = $config->compressionFormat === 'zip' ? 'zip' : 'tar.gz';
        $filename = sprintf('%s-%s-%s.%s', $config->name, date('Y-m-d_H-i-s'), $runId, $extension);
        $archivePath = dirname($stagingDir) . DIRECTORY_SEPARATOR . $filename;

        (new Archiver())->archive($stagingDir, $archivePath, $config->compressionFormat, $config->archivePassword);

        $note = $config->archivePassword !== null ? ' (password-protected)' : '';
        $logger->info('Archive built' . $note, ['path' => $archivePath, 'bytes' => filesize($archivePath)]);

        return $archivePath;
    }

    private function encryptArchive(BackupConfig $config, string $plainArchive, BackupLogger $logger): string
    {
        $encryptedPath = $plainArchive . '.enc';
        (new Encryptor())->encrypt($plainArchive, $encryptedPath, $config->encryptionCipher, $config->encryptionKey);
        unlink($plainArchive);
        $logger->info('Archive encrypted', ['path' => $encryptedPath]);

        return $encryptedPath;
    }

    /**
     * @return iterable<string, TargetInterface>
     */
    private function targetsFor(BackupConfig $config, ?string $onlyTo): iterable
    {
        foreach ($config->targets as $name => $def) {
            if ($onlyTo !== null && $onlyTo !== $name) {
                continue;
            }
            yield $name => $this->buildTarget($def);
        }
    }

    private function buildTarget(array $def): TargetInterface
    {
        return match ($def['driver'] ?? null) {
            'local' => new LocalTarget($def),
            'sftp' => new SftpTarget($def),
            default => throw new BackupFailedException("Unknown target driver '{$def['driver']}'."),
        };
    }

    private function makeStagingDir(string $name, string $runId): string
    {
        $base = Craft::$app->getPath()->getTempPath() . "/craft-backup/{$name}-{$runId}";
        FileHelper::createDirectory($base);
        return $base;
    }

    private function result(string $runId, ?string $archive, int $bytes, float $start, array $statuses, array $errors, array $lowDiskTargets = []): BackupResult
    {
        return new BackupResult(
            runId: $runId,
            archivePath: $archive,
            archiveBytes: $bytes,
            durationSeconds: microtime(true) - $start,
            targetStatuses: $statuses,
            errors: $errors,
            lowDiskTargets: $lowDiskTargets,
        );
    }

    /**
     * @return array{free:int, threshold:int, total:int}|null
     */
    private function checkLowDisk(string $targetName, TargetInterface $target, BackupConfig $config): ?array
    {
        $usage = $target->diskUsage();
        if ($usage === null) {
            return null;
        }

        $threshold = $this->warnThresholdFor($config, $targetName, $usage['total']);
        if ($threshold === null) {
            return null;
        }

        if ($usage['free'] >= $threshold) {
            return null;
        }

        return [
            'free' => $usage['free'],
            'threshold' => $threshold,
            'total' => $usage['total'],
        ];
    }

    private function warnThresholdFor(BackupConfig $config, string $targetName, int $totalBytes): ?int
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
            return $parsed === null ? null : Bytes::resolveThreshold($parsed, $totalBytes);
        }
        return null;
    }

    private function logger(string $runId): BackupLogger
    {
        $logger = new BackupLogger();
        $logger->runId = $runId;
        return $logger;
    }

    private function notify(BackupConfig $config, BackupResult $result, BackupLogger $logger): void
    {
        $successful = $result->isSuccessful();
        $event = $successful ? 'on_success' : 'on_failure';
        $siteName = Craft::$app->getSystemName() ?: $config->name;
        $status = $successful ? 'Success' : 'Failure';

        $lines = [
            $successful ? 'Backup run completed successfully.' : 'A backup run did not complete successfully.',
            '',
            "Site:     {$siteName}",
            "Run ID:   {$result->runId}",
            sprintf('Duration: %.1fs', $result->durationSeconds),
            sprintf('Archive:  %s (%d bytes)', $result->archivePath ?? '(not produced)', $result->archiveBytes),
            '',
            'Targets:',
        ];
        foreach ($result->targetStatuses as $name => $targetStatus) {
            $lines[] = "  - {$name}: {$targetStatus}";
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

        $body = implode(PHP_EOL, $lines);
        $this->sendMail($config, $event, "[Craft Backup] {$status} on {$siteName}", $body, $logger);
        $this->sendSlack($config, $event, "[Craft Backup] {$status} on {$siteName}", $body, $logger);
    }

    private function notifyLowDisk(BackupConfig $config, BackupResult $result, BackupLogger $logger): void
    {
        if ($result->lowDiskTargets === []) {
            return;
        }

        $siteName = Craft::$app->getSystemName() ?: $config->name;
        $subject = "[Craft Backup] Low disk space on {$siteName}";

        $lines = [
            'One or more backup targets fell below their configured free-disk warning threshold after the latest backup.',
            '',
            "Site:   {$siteName}",
            "Run ID: {$result->runId}",
            '',
            'Targets:',
        ];
        foreach ($result->lowDiskTargets as $name => $info) {
            $lines[] = sprintf(
                '  - %s: %s free of %s (threshold %s)',
                $name,
                $this->formatBytes($info['free']),
                $this->formatBytes($info['total']),
                $this->formatBytes($info['threshold']),
            );
        }

        $body = implode(PHP_EOL, $lines);
        $this->sendMail($config, 'on_low_disk_space', $subject, $body, $logger);
        $this->sendSlack($config, 'on_low_disk_space', $subject, $body, $logger);
    }

    private function sendMail(BackupConfig $config, string $event, string $subject, string $body, BackupLogger $logger): void
    {
        $recipients = array_filter((array) ($config->notifications['mail'][$event] ?? []));
        if ($recipients === []) {
            return;
        }

        try {
            Craft::$app->getMailer()
                ->compose()
                ->setTo($recipients)
                ->setSubject($subject)
                ->setTextBody($body)
                ->send();

            $logger->info("mail notification sent ({$event})", ['to' => $recipients]);
        } catch (Throwable $e) {
            $logger->warning("Failed to send mail notification ({$event})", ['error' => $e->getMessage()]);
        }
    }

    private function sendSlack(BackupConfig $config, string $event, string $subject, string $body, BackupLogger $logger): void
    {
        $slack = (array) ($config->notifications['slack'] ?? []);
        $webhookUrl = $slack['webhook_url'] ?? null;
        if (!is_string($webhookUrl) || $webhookUrl === '') {
            return;
        }
        if (!($slack[$event] ?? false)) {
            return;
        }

        $payload = ['text' => "*{$subject}*\n```\n{$body}\n```"];
        if (!empty($slack['channel'])) {
            $payload['channel'] = $slack['channel'];
        }
        if (!empty($slack['username'])) {
            $payload['username'] = $slack['username'];
        }
        if (!empty($slack['icon'])) {
            $icon = $slack['icon'];
            $payload[str_starts_with($icon, ':') ? 'icon_emoji' : 'icon_url'] = $icon;
        }

        try {
            Craft::createGuzzleClient()->post($webhookUrl, [
                'json' => $payload,
                'timeout' => 10,
            ]);

            $logger->info("slack notification sent ({$event})");
        } catch (Throwable $e) {
            $logger->warning("Failed to send slack notification ({$event})", ['error' => $e->getMessage()]);
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / (1024 ** 3), 2) . ' GB';
        }
        if ($bytes >= 1024 ** 2) {
            return number_format($bytes / (1024 ** 2), 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
