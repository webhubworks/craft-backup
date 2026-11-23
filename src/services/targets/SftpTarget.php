<?php

namespace webhubworks\backup\services\targets;

use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use Throwable;
use webhubworks\backup\exceptions\BackupFailedException;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\services\BackupGrouper;
use webhubworks\backup\services\Throttler;

class SftpTarget implements TargetInterface
{
    private readonly Filesystem $fs;

    public function __construct(private readonly array $def, private readonly string $backupName)
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
                hostFingerprint: ($def['host_fingerprint'] ?? null) ?: null,
            ),
            (string) ($def['root'] ?? '/backups'),
        );

        $this->fs = new Filesystem($adapter);
    }

    public function hasHostFingerprint(): bool
    {
        return !empty($this->def['host_fingerprint']);
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

        // Write under a name list() ignores, so an interrupted upload never counts as a backup.
        $partial = $remoteFilename . '.part';
        try {
            $this->fs->writeStream($partial, $stream);
            $this->fs->move($partial, $remoteFilename);
        } catch (Throwable $e) {
            try {
                $this->fs->delete($partial);
            } catch (Throwable) {
            }
            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function list(string $kind = self::KIND_BACKUP): array
    {
        $out = [];
        foreach ($this->fs->listContents('/', false) as $item) {
            if (!$item instanceof FileAttributes || BackupGrouper::kindOf(basename($item->path()), $this->backupName) !== $kind) {
                continue;
            }
            $out[] = [
                'path' => $item->path(),
                'size' => $item->fileSize() ?? 0,
                'modified' => $item->lastModified() ?? 0,
                'encrypted' => null,
            ];
        }
        return $out;
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
