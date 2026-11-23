<?php

namespace webhubworks\backup\services;

use Craft;
use craft\helpers\FileHelper;
use Throwable;
use webhubworks\backup\events\ArchiveEvent;
use webhubworks\backup\events\CleanupEvent;
use webhubworks\backup\events\DbDumpEvent;
use webhubworks\backup\events\RunEvent;
use webhubworks\backup\events\RunResultEvent;
use webhubworks\backup\exceptions\BackupFailedException;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\models\BackupResult;
use webhubworks\backup\services\sanitize\CraftBackups;
use webhubworks\backup\services\sanitize\CraftSchema;
use webhubworks\backup\services\sanitize\Sanitizer;
use webhubworks\backup\services\sanitize\SanitizeRules;
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
    /**
     * @event RunEvent Before a run starts. Set `$event->isValid = false` to skip the run.
     */
    public const EVENT_BEFORE_RUN = 'beforeRun';

    /**
     * @event RunResultEvent After every run except dry runs, whether it succeeded or not.
     */
    public const EVENT_AFTER_RUN = 'afterRun';

    /**
     * @event RunResultEvent After EVENT_AFTER_RUN, when the run failed or a target failed.
     */
    public const EVENT_RUN_FAILED = 'runFailed';

    /**
     * @event DbDumpEvent Before each database connection is dumped. Set `$event->isValid = false` to skip it.
     */
    public const EVENT_BEFORE_DB_DUMP = 'beforeDbDump';

    /**
     * @event ArchiveEvent After each archive is built and, when configured, encrypted.
     */
    public const EVENT_ARCHIVE_CREATED = 'archiveCreated';

    /**
     * @event CleanupEvent After retention ran on a target, in a run or in `backup/clean`.
     */
    public const EVENT_AFTER_CLEANUP = 'afterCleanup';

    /**
     * @event CleanupEvent When retention failed on a target.
     */
    public const EVENT_CLEANUP_FAILED = 'cleanupFailed';

    /**
     * @event RunResultEvent After EVENT_AFTER_RUN, when targets fell below their free-disk warning threshold.
     */
    public const EVENT_LOW_DISK_SPACE = 'lowDiskSpace';

    /**
     * @event \webhubworks\backup\events\RegisterTargetDriversEvent When target drivers are resolved.
     * Listen on the class: `Event::on(BackupRunner::class, BackupRunner::EVENT_REGISTER_TARGET_DRIVERS, ...)`.
     */
    public const EVENT_REGISTER_TARGET_DRIVERS = 'registerTargetDrivers';

    public function run(BackupConfig $config, array $flags = []): BackupResult
    {
        $runId = bin2hex(random_bytes(4));
        $dryRun = (bool) ($flags['dry_run'] ?? false);
        $startedAt = microtime(true);

        $logger = new BackupLogger(['runId' => $runId]);

        $beforeRun = new RunEvent(['config' => $config, 'runId' => $runId, 'flags' => $flags, 'dryRun' => $dryRun]);
        $this->trigger(self::EVENT_BEFORE_RUN, $beforeRun);
        if (!$beforeRun->isValid) {
            $logger->info('Backup run skipped by an event handler');
            return new BackupResult(
                runId: $runId,
                archivePaths: [],
                archiveBytes: 0,
                durationSeconds: microtime(true) - $startedAt,
                targetStatuses: [],
                errors: [],
                skipped: true,
            );
        }

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
                    // Event handlers can skip every connection, which leaves nothing to archive.
                    if ($this->dumpDatabases($config, $runId, $dryRun, $dbStaging, $logger) !== []) {
                        $archivePaths[] = $this->finishArchive($config, $runId, $dryRun, $dbStaging, $runDir, $logger, 'db');
                    }
                }

                if (!$onlyDb) {
                    $filesStaging = $stagingDir . DIRECTORY_SEPARATOR . 'files-staging';
                    FileHelper::createDirectory($filesStaging);
                    $this->stageFiles($config, $filesStaging, $logger);
                    $archivePaths[] = $this->finishArchive($config, $runId, $dryRun, $filesStaging, $runDir, $logger, 'files');
                }
            } else {
                if (!$onlyFiles) {
                    $this->dumpDatabases($config, $runId, $dryRun, $stagingDir, $logger);
                }

                if (!$onlyDb) {
                    $this->stageFiles($config, $stagingDir, $logger);
                }

                $archivePaths[] = $this->finishArchive($config, $runId, $dryRun, $stagingDir, $runDir, $logger, null);
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
                    $event = new CleanupEvent(['config' => $config, 'target' => $name, 'runId' => $runId]);
                    try {
                        $event->deleted = $policy->apply($target, $config->retention);
                    } catch (Throwable $e) {
                        $logger->warning("Retention on {$name} failed", ['error' => $e->getMessage()]);
                        $event->exception = $e;
                        $this->trigger(self::EVENT_CLEANUP_FAILED, $event);
                        continue;
                    }
                    $logger->info("Pruned {$event->deleted} old backup(s) on {$name}");
                    $this->trigger(self::EVENT_AFTER_CLEANUP, $event);
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

            $event = new RunResultEvent(['config' => $config, 'result' => $result]);
            $this->trigger(self::EVENT_AFTER_RUN, $event);
            if (!$result->isSuccessful()) {
                $this->trigger(self::EVENT_RUN_FAILED, $event);
            }
            if ($result->lowDiskTargets !== []) {
                $this->trigger(self::EVENT_LOW_DISK_SPACE, $event);
            }
        }

        return $result;
    }

    /**
     * Builds a sanitized copy of the site database and uploads it to the
     * `sanitize.targets`. On each target where the upload succeeds, older
     * sanitized copies are deleted. Doesn't touch run state, retention, or
     * notifications.
     */
    public function sanitize(BackupConfig $config, ?string $onlyTo = null): BackupResult
    {
        $runId = bin2hex(random_bytes(4));
        $startedAt = microtime(true);
        $logger = new BackupLogger(['runId' => $runId]);
        $logger->info('Sanitized copy starting');

        $runDir = $this->makeRunDir($config->name, $runId);
        $stagingDir = $runDir . DIRECTORY_SEPARATOR . 'staging';
        FileHelper::createDirectory($stagingDir);
        $archivePaths = [];
        $archiveSizes = [];
        $targetStatuses = [];
        $errors = [];

        try {
            $schema = new CraftSchema();
            $rules = SanitizeRules::fromConfig($config->sanitize, $schema);
            $backups = new CraftBackups();

            $sourceDump = $runDir . DIRECTORY_SEPARATOR . 'source.sql';
            // mysqldump and pg_dump don't take patterns, so those tables are emptied in the scratch copy only.
            $backups->dumpSource($sourceDump, array_values(array_filter($rules->emptyTables, fn(string $table) => !str_contains($table, '*'))));
            $logger->info('Dumped site database');

            $scratch = $backups->createScratch($runId);
            try {
                $scratch->restore($sourceDump);
                unlink($sourceDump);
                $logger->info('Restored into scratch database');

                (new Sanitizer($logger, $schema, (string) ($config->sanitize['faker_locale'] ?? 'en_US')))->sanitize($scratch, $rules);

                $scratch->dump($stagingDir . DIRECTORY_SEPARATOR . 'db-sanitized.sql');
            } finally {
                $scratch->drop();
                $logger->info('Dropped scratch database');
            }

            $archivePaths[] = $archive = $this->finishArchive($config, $runId, false, $stagingDir, $runDir, $logger, 'sanitized');
            $archiveSizes[basename($archive)] = (int) filesize($archive);

            $only = $config->sanitize['targets'] ?? null;
            $factory = new TargetFactory();
            foreach ($this->targetDefinitions($config, $onlyTo) as $name => $definition) {
                if (is_array($only) && !in_array($name, $only, true)) {
                    continue;
                }
                try {
                    $target = $factory->make($definition, $config->name);
                    $target->upload($archive, basename($archive), $config);
                    $targetStatuses[$name] = 'ok';
                    $logger->info("Uploaded sanitized copy to {$name}");

                    foreach ($target->list(TargetInterface::KIND_SANITIZED) as $old) {
                        if (basename($old['path']) !== basename($archive)) {
                            $target->delete($old['path']);
                        }
                    }
                } catch (Throwable $e) {
                    $targetStatuses[$name] = 'failed: ' . $e->getMessage();
                    $errors[] = "target {$name}: " . $e->getMessage();
                    $logger->error("Upload of sanitized copy to {$name} failed", ['error' => $e->getMessage()]);
                }
            }

            if ($targetStatuses === []) {
                $errors[] = 'No target received the sanitized copy. Check sanitize.targets.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $logger->error('Sanitized copy failed', ['error' => $e->getMessage()]);
        } finally {
            // Also removes the unsanitized source dump if the run failed before deleting it.
            FileHelper::removeDirectory($runDir);
        }

        return $this->result($runId, $archivePaths, array_sum($archiveSizes), $startedAt, $targetStatuses, $errors, [], $archiveSizes);
    }

    /**
     * @deprecated in 2.5.0. Use status(), which also reports a per-target error instead of hiding it.
     * @return array<string, array<int, array{path:string, size:int, modified:int, encrypted:?bool}>>
     */
    public function list(BackupConfig $config): array
    {
        return array_map(fn(array $entry) => $entry['backups'], $this->status($config));
    }

    /**
     * Per-target snapshot for `backup/list` and the Backups-by-target card: listing plus
     * current disk usage of the volume backing the target (null when the driver doesn't
     * expose it, e.g. SFTP). A target that can't be built or listed carries its error
     * instead, so it doesn't hide the others.
     *
     * @return array<string, array{
     *     backups: array<int, array{path:string, size:int, modified:int, encrypted:?bool}>,
     *     sanitized: array<int, array{path:string, size:int, modified:int, encrypted:?bool}>,
     *     craft: array<int, array{path:string, size:int, modified:int, encrypted:?bool}>,
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
                $out[$name] = [
                    'backups' => $target->list(),
                    'sanitized' => $target->list(TargetInterface::KIND_SANITIZED),
                    'craft' => $target->list(TargetInterface::KIND_CRAFT),
                    'diskUsage' => $target->diskUsage(),
                    'error' => null,
                ];
            } catch (Throwable $e) {
                $out[$name] = ['backups' => [], 'sanitized' => [], 'craft' => [], 'diskUsage' => null, 'error' => $e->getMessage()];
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
        $dryRun = (bool) ($flags['dry_run'] ?? false);
        $factory = new TargetFactory();
        $policy = new RetentionPolicy();
        $total = 0;
        $errors = [];
        foreach ($this->targetDefinitions($config, $flags['only_to'] ?? null) as $name => $definition) {
            $event = new CleanupEvent(['config' => $config, 'target' => $name]);
            try {
                $event->deleted = $policy->apply($factory->make($definition, $config->name), $config->retention, $dryRun);
            } catch (Throwable $e) {
                $errors[] = "target {$name}: " . $e->getMessage();
                $event->exception = $e;
                if (!$dryRun) {
                    $this->trigger(self::EVENT_CLEANUP_FAILED, $event);
                }
                continue;
            }
            $total += $event->deleted;
            if (!$dryRun) {
                $this->trigger(self::EVENT_AFTER_CLEANUP, $event);
            }
        }

        if ($errors !== []) {
            throw new BackupFailedException('Cleanup failed on ' . implode('; ', $errors));
        }

        return $total;
    }

    /**
     * Dumps every configured connection that no EVENT_BEFORE_DB_DUMP handler skips.
     *
     * @return list<string> Paths of the dumps written.
     */
    private function dumpDatabases(BackupConfig $config, string $runId, bool $dryRun, string $stagingDir, BackupLogger $logger): array
    {
        $dumper = new DbDumper();
        $dumps = [];
        foreach ($config->databases as $connectionId) {
            $event = new DbDumpEvent([
                'config' => $config,
                'runId' => $runId,
                'connectionId' => $connectionId,
                'path' => DbDumper::path($stagingDir, $connectionId),
                'dryRun' => $dryRun,
            ]);
            $this->trigger(self::EVENT_BEFORE_DB_DUMP, $event);
            if (!$event->isValid) {
                $logger->info("Dump of {$connectionId} skipped by an event handler");
                continue;
            }
            array_push($dumps, ...$dumper->dump([$connectionId], $stagingDir));
        }
        $logger->info('Dumped databases', ['files' => array_map('basename', $dumps)]);

        return $dumps;
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
     * $kind is null for combined runs, 'db' or 'files' for split runs, and
     * 'sanitized' for sanitized copies. When set, it's appended to the
     * filename so split halves are grouped in retention/monitor by their
     * shared runId.
     */
    private function finishArchive(BackupConfig $config, string $runId, bool $dryRun, string $stagingDir, string $outputDir, BackupLogger $logger, ?string $kind): string
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

        $this->trigger(self::EVENT_ARCHIVE_CREATED, new ArchiveEvent([
            'config' => $config,
            'runId' => $runId,
            'path' => $archivePath,
            'kind' => $kind,
            'bytes' => (int) filesize($archivePath),
            'encrypted' => $config->encryptionEnabled || $config->archivePassword !== null,
            'dryRun' => $dryRun,
        ]));

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
}
