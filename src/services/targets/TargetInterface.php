<?php

namespace webhubworks\backup\services\targets;

use webhubworks\backup\models\BackupConfig;

interface TargetInterface
{
    public function upload(string $localPath, string $remoteFilename, BackupConfig $config): void;

    /**
     * @return array<int, array{path: string, size: int, modified: int, encrypted: ?bool}>
     */
    public function list(): array;

    public function delete(string $path): void;

    /**
     * Returns total/free disk bytes for the volume backing this target, or null
     * if the driver cannot determine it (e.g. remote SFTP).
     *
     * @return array{total: int, free: int}|null
     */
    public function diskUsage(): ?array;
}
