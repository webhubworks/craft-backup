<?php

namespace webhubworks\backup\services\targets;

use Craft;
use craft\helpers\FileHelper;
use webhubworks\backup\exceptions\BackupFailedException;
use webhubworks\backup\models\BackupConfig;
use ZipArchive;

class LocalTarget implements TargetInterface
{
    private readonly string $root;

    public function __construct(array $def)
    {
        $root = Craft::getAlias($def['root'] ?? '@storage/backups');
        if (!is_string($root)) {
            throw new BackupFailedException("Invalid local target root: '{$def['root']}'.");
        }
        FileHelper::createDirectory($root);
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
    }

    public function upload(string $localPath, string $remoteFilename, BackupConfig $config): void
    {
        $destination = $this->root . DIRECTORY_SEPARATOR . $remoteFilename;
        if (!copy($localPath, $destination)) {
            throw new BackupFailedException("Could not copy backup to {$destination}.");
        }
    }

    public function list(): array
    {
        if (!is_dir($this->root)) {
            return [];
        }

        $entries = [];
        foreach (scandir($this->root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->root . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path) || !$this->isBackupArchive($name)) {
                continue;
            }
            $entries[] = [
                'path' => $path,
                'size' => (int) filesize($path),
                'modified' => (int) filemtime($path),
                'encrypted' => $this->isZipPasswordProtected($path),
            ];
        }
        return $entries;
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

    private function isZipPasswordProtected(string $path): ?bool
    {
        if (!str_ends_with($path, '.zip') || !class_exists(ZipArchive::class)) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (is_array($stat) && !empty($stat['encryption_method'])) {
                    return true;
                }
            }
            return false;
        } finally {
            $zip->close();
        }
    }

    public function delete(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
