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
}
