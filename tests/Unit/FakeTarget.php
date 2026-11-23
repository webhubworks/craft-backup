<?php

namespace webhubworks\backup\tests\Unit;

use RuntimeException;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\services\targets\TargetInterface;

/**
 * In-memory target. Registered as the `fake` driver, it reads these config keys:
 * `id` to find the instance in `$built`, `fail` to make uploads throw, and `disk`
 * for diskUsage().
 */
final class FakeTarget implements TargetInterface
{
    /** @var array<string, self> Instances built through the `fake` driver, by their `id` key. */
    public static array $built = [];

    /** @var array<string, array{path:string, size:int, modified:int, encrypted:?bool}> */
    public array $files = [];

    public function __construct(private readonly array $definition = [], string $name = '')
    {
        if (isset($definition['id'])) {
            self::$built[$definition['id']] = $this;
        }
    }

    public function add(string $path, int $size = 1): void
    {
        $this->files[$path] = ['path' => $path, 'size' => $size, 'modified' => 0, 'encrypted' => null];
    }

    public function upload(string $localPath, string $remoteFilename, BackupConfig $config): void
    {
        if ($this->definition['fail'] ?? false) {
            throw new RuntimeException('Connection refused');
        }
        $this->add($remoteFilename, (int) filesize($localPath));
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
        return $this->definition['disk'] ?? null;
    }
}
