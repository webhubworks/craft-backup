<?php

namespace webhubworks\backup\services;

use Craft;
use craft\helpers\FileHelper;
use Throwable;
use webhubworks\backup\exceptions\BackupFailedException;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\models\BackupResult;
use webhubworks\backup\services\notifications\Notifier;
use webhubworks\backup\services\targets\SftpTarget;
use webhubworks\backup\services\targets\TargetFactory;
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
        $dryRun = (bool) ($flags['dry_run'] ?? false);
        $startedAt = microtime(true);

        $logger = $this->logger($runId);
        $logger->info('Backup run starting', [
            'dry_run' => $dryRun,
            'split' => $config->splitDbAndFiles,
        ]);

        // Archives are built inside $runDir, so removing it also removes partial or plaintext leftovers.
        $runDir = $this->makeRunDir($config->name, $runId);
        $stagingDir = $runDir . DIRECTORY_SEPARATOR . 'staging';
        FileHelper::createDirectory($stagingDir);
        $archivePaths = [];
        $archiveBytes = 0;
        $archiveSizes = [];
        $targetStatuses = [];
        $errors = [];
        $lowDiskTargets = [];

        try {
            $onlyDb = (bool) ($flags['only_db'] ?? false);
            $onlyFiles = (bool) ($flags['only_files'] ?? false);

            if ($config->splitDbAndFiles) {
                if (!$onlyFiles) {
                    $dbStaging = $stagingDir . DIRECTORY_SEPARATOR . 'db-staging';
                    FileHelper::createDirectory($dbStaging);
                    $dumps = (new DbDumper())->dump($config->databases, $dbStaging);
                    $logger->info('Dumped databases', ['files' => array_map('basename', $dumps)]);
                    $archivePaths[] = $this->finishArchive($config, $runId, $dbStaging, $runDir, $logger, 'db');
                }

                if (!$onlyDb) {
                    $filesStaging = $stagingDir . DIRECTORY_SEPARATOR . 'files-staging';
                    FileHelper::createDirectory($filesStaging);
                    $this->stageFiles($config, $filesStaging, $logger);
                    $archivePaths[] = $this->finishArchive($config, $runId, $filesStaging, $runDir, $logger, 'files');
                }
            } else {
                if (!$onlyFiles) {
                    $dumps = (new DbDumper())->dump($config->databases, $stagingDir);
                    $logger->info('Dumped databases', ['files' => array_map('basename', $dumps)]);
                }

                if (!$onlyDb) {
                    $this->stageFiles($config, $stagingDir, $logger);
                }

                $archivePaths[] = $this->finishArchive($config, $runId, $stagingDir, $runDir, $logger, null);
            }

            foreach ($archivePaths as $path) {
                $archiveSizes[basename($path)] = (int) filesize($path);
            }
            $archiveBytes = array_sum($archiveSizes);

            if ($dryRun) {
                $logger->info('Dry run: skipping upload and cleanup', ['archives' => $archivePaths]);
                return $this->result($runId, $archivePaths, $archiveBytes, $startedAt, $targetStatuses, $errors, [], $archiveSizes);
            }

            $factory = new TargetFactory();
            $uploadedTargets = [];
            foreach ($this->targetDefinitions($config, $flags['only_to'] ?? null) as $name => $definition) {
                // Built inside the try, so one broken target config doesn't stop the others.
                try {
                    $target = $factory->make($definition, $config->name);
                    if ($target instanceof SftpTarget && !$target->hasHostFingerprint()) {
                        $logger->warning("SFTP target {$name} has no host_fingerprint, so any server answering on its host receives the backup.");
                    }
                    foreach ($archivePaths as $archivePath) {
                        $target->upload($archivePath, basename($archivePath), $config);
                    }
                    $targetStatuses[$name] = 'ok';
                    $logger->info("Uploaded to {$name}");

                    $low = $this->checkLowDisk($name, $target, $config);
                    if ($low !== null) {
                        $lowDiskTargets[$name] = $low;
                        $logger->warning("Free disk on {$name} is below the configured warning threshold", $low);
                    }
                    $uploadedTargets[$name] = $target;
                } catch (Throwable $e) {
                    $targetStatuses[$name] = 'failed: ' . $e->getMessage();
                    $errors[] = "target {$name}: " . $e->getMessage();
                    $logger->error("Upload to {$name} failed", ['error' => $e->getMessage()]);
                }
            }

            if (!($flags['disable_cleanup'] ?? false)) {
                $policy = new RetentionPolicy();
                // Only targets with a successful upload: pruning after a failed one could delete good backups while the new one is missing.
                foreach ($uploadedTargets as $name => $target) {
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
            // Dry runs keep their archives for inspection.
            FileHelper::removeDirectory($dryRun ? $stagingDir : $runDir);
        }

        $result = $this->result($runId, $archivePaths, $archiveBytes, $startedAt, $targetStatuses, $errors, $lowDiskTargets, $archiveSizes);

        if (!$dryRun) {
            (new RunStateStore())->record($result);
            $notifier = new Notifier($config, $logger);
            $notifier->notifyRun($result);
            $notifier->notifyLowDisk($result);
        }

        return $result;
    }

    /**
     * Per-target snapshot for `backup/list` and the Backups-by-target card: listing plus
     * current disk usage of the volume backing the target (null when the driver doesn't
     * expose it, e.g. SFTP). A target that can't be built or listed carries its error
     * instead, so it doesn't hide the others.
     *
     * @return array<string, array{
     *     backups: array<int, array{path:string, size:int, modified:int, encrypted:?bool}>,
     *     diskUsage: array{total:int, free:int}|null,
     *     error: ?string,
     * }>
     */
    public function status(BackupConfig $config): array
    {
        $factory = new TargetFactory();
        $out = [];
        foreach ($config->targets as $name => $definition) {
            try {
                $target = $factory->make($definition, $config->name);
                $out[$name] = ['backups' => $target->list(), 'diskUsage' => $target->diskUsage(), 'error' => null];
            } catch (Throwable $e) {
                $out[$name] = ['backups' => [], 'diskUsage' => null, 'error' => $e->getMessage()];
            }
        }
        return $out;
    }

    /**
     * Applies retention to every target, or only to `only_to`. A failing target doesn't
     * stop the others; the failures are thrown together at the end.
     */
    public function clean(BackupConfig $config, array $flags = []): int
    {
        $factory = new TargetFactory();
        $policy = new RetentionPolicy();
        $total = 0;
        $errors = [];
        foreach ($this->targetDefinitions($config, $flags['only_to'] ?? null) as $name => $definition) {
            try {
                $total += $policy->apply($factory->make($definition, $config->name), $config->retention, (bool) ($flags['dry_run'] ?? false));
            } catch (Throwable $e) {
                $errors[] = "target {$name}: " . $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new BackupFailedException('Cleanup failed on ' . implode('; ', $errors));
        }

        return $total;
    }

    private function stageFiles(BackupConfig $config, string $stagingDir, BackupLogger $logger): void
    {
        // Trailing separator, so /var/www doesn't match /var/www2.
        $rootPrefix = Craft::getAlias('@root') . DIRECTORY_SEPARATOR;
        $filesDir = $stagingDir . DIRECTORY_SEPARATOR . 'files';
        FileHelper::createDirectory($filesDir);

        $count = 0;
        foreach ((new SourceCollector())->collect($config) as $file) {
            $source = $file->getPathname();
            $relative = str_starts_with($source, $rootPrefix)
                ? substr($source, strlen($rootPrefix))
                : ltrim($source, DIRECTORY_SEPARATOR);

            $dest = $filesDir . DIRECTORY_SEPARATOR . $relative;
            FileHelper::createDirectory(dirname($dest));
            copy($source, $dest);
            $count++;
        }

        $logger->info("Staged {$count} file(s)");
    }

    /**
     * Builds the archive for a staged directory and, when configured, wraps
     * it in the encrypted envelope. Returns the final on-disk path.
     *
     * $kind is null for combined runs and 'db' or 'files' for split runs;
     * when set, it's appended to the filename so split halves are grouped
     * in retention/monitor by their shared runId.
     */
    private function finishArchive(BackupConfig $config, string $runId, string $stagingDir, string $outputDir, BackupLogger $logger, ?string $kind): string
    {
        $extension = $config->compressionFormat === 'zip' ? 'zip' : 'tar.gz';
        $kindSuffix = $kind !== null ? "-{$kind}" : '';
        $filename = sprintf('%s-%s-%s%s.%s', $config->name, date('Y-m-d_H-i-s'), $runId, $kindSuffix, $extension);
        $archivePath = $outputDir . DIRECTORY_SEPARATOR . $filename;

        (new Archiver())->archive($stagingDir, $archivePath, $config->compressionFormat, $config->archivePassword);

        $note = $config->archivePassword !== null ? ' (password-protected)' : '';
        $logger->info('Archive built' . $note, ['path' => $archivePath, 'bytes' => filesize($archivePath)]);

        if ($config->encryptionEnabled) {
            $archivePath = $this->encryptArchive($config, $archivePath, $logger);
        }

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
     * @return array<string, array> target name => definition
     */
    private function targetDefinitions(BackupConfig $config, ?string $onlyTo): array
    {
        return $onlyTo === null ? $config->targets : array_intersect_key($config->targets, [$onlyTo => true]);
    }

    private function makeRunDir(string $name, string $runId): string
    {
        $base = Craft::$app->getPath()->getTempPath() . "/craft-backup/{$name}-{$runId}";
        FileHelper::createDirectory($base);
        return $base;
    }

    /**
     * @param list<string> $archives
     */
    private function result(string $runId, array $archives, int $bytes, float $start, array $statuses, array $errors, array $lowDiskTargets = [], array $archiveSizes = []): BackupResult
    {
        return new BackupResult(
            runId: $runId,
            archivePaths: $archives,
            archiveBytes: $bytes,
            durationSeconds: microtime(true) - $start,
            targetStatuses: $statuses,
            errors: $errors,
            lowDiskTargets: $lowDiskTargets,
            archiveSizes: $archiveSizes,
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
}
