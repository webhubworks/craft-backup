<?php

namespace webhubworks\backup\tests\Unit;

use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\services\targets\TargetInterface;

final class FakeTarget implements TargetInterface
{
    /** @var array<string, array{path:string, size:int, modified:int, encrypted:?bool}> */
    public array $files = [];

    public function add(string $path, int $size = 1): void
    {
        $this->files[$path] = ['path' => $path, 'size' => $size, 'modified' => 0, 'encrypted' => null];
    }

    public function upload(string $localPath, string $remoteFilename, BackupConfig $config): void
    {
        $this->add($remoteFilename);
    }

    public function list(string $kind = self::KIND_BACKUP): array
    {
        return array_values(array_filter(
            $this->files,
            fn(array $file) => str_contains($file['path'], '-sanitized.') === ($kind === self::KIND_SANITIZED),
        ));
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    public function diskUsage(): ?array
    {
        return null;
    }
}
