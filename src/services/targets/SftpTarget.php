<?php

namespace webhubworks\backup\services\targets;

use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use webhubworks\backup\exceptions\BackupFailedException;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\services\Throttler;

class SftpTarget implements TargetInterface
{
    private readonly Filesystem $fs;

    public function __construct(private readonly array $def)
    {
        foreach (['host', 'username'] as $required) {
            if (empty($def[$required])) {
                throw new BackupFailedException("SFTP target is missing required field '{$required}'.");
            }
        }

        $adapter = new SftpAdapter(
            new SftpConnectionProvider(
                host: (string) $def['host'],
                username: (string) $def['username'],
                password: $def['password'] ?? null,
                privateKey: $def['private_key'] ?? null,
                passphrase: $def['passphrase'] ?? null,
                port: (int) ($def['port'] ?? 22),
                useAgent: false,
                timeout: (int) ($def['timeout'] ?? 30),
            ),
            (string) ($def['root'] ?? '/backups'),
        );

        $this->fs = new Filesystem($adapter);
    }

    public function upload(string $localPath, string $remoteFilename, BackupConfig $config): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new BackupFailedException("Could not open {$localPath} for upload.");
        }

        if ($config->throttleEnabled) {
            (new Throttler())->throttle($stream, $config->throttleBytesPerSecond);
        }

        try {
            $this->fs->writeStream($remoteFilename, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function list(): array
    {
        $out = [];
        foreach ($this->fs->listContents('/', false) as $item) {
            if (!$item->isFile() || !$this->isBackupArchive(basename($item->path()))) {
                continue;
            }
            $out[] = [
                'path' => $item->path(),
                'size' => (int) ($item->fileSize() ?? 0),
                'modified' => (int) ($item->lastModified() ?? 0),
                'encrypted' => null,
            ];
        }
        return $out;
    }

    private function isBackupArchive(string $filename): bool
    {
        foreach (['.zip', '.tar.gz', '.tar.gz.enc'] as $ext) {
            if (str_ends_with($filename, $ext)) {
                return true;
            }
        }
        return false;
    }

    public function delete(string $path): void
    {
        $this->fs->delete($path);
    }

    public function diskUsage(): ?array
    {
        return null;
    }
}
