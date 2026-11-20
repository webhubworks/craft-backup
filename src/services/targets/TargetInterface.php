<?php

namespace webhubworks\backup\services\targets;

use webhubworks\backup\models\BackupConfig;

interface TargetInterface
{
    /** Archives written by backup/run. Retention and health checks work on these only. */
    public const KIND_BACKUP = 'backup';
    /** Sanitized copies written by backup/sanitize. */
    public const KIND_SANITIZED = 'sanitized';
    /** Dumps written by Craft's own Backup button or db/backup. */
    public const KIND_CRAFT = 'craft';

    public function upload(string $localPath, string $remoteFilename, BackupConfig $config): void;

    /**
     * Lists the files of one kind, backups by default.
     *
     * @param self::KIND_* $kind
     * @return array<int, array{path: string, size: int, modified: int, encrypted: ?bool}>
     */
    public function list(string $kind = self::KIND_BACKUP): array;

    public function delete(string $path): void;

    /**
     * Returns total/free disk bytes for the volume backing this target, or null
     * if the driver cannot determine it (e.g. remote SFTP).
     *
     * @return array{total: int, free: int}|null
     */
    public function diskUsage(): ?array;
}
