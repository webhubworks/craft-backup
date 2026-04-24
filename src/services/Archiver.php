<?php

namespace webhubworks\backup\services;

use FilesystemIterator;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use webhubworks\backup\exceptions\BackupFailedException;
use ZipArchive;

/**
 * Produces an archive of the staging directory.
 *
 * Two container formats:
 *   - tar.gz (default): PharData tar + gzip. No encryption here; use the
 *     Encryptor service to wrap the output if encryption is desired.
 *   - zip: ZipArchive. Accepts an optional password; when provided every entry
 *     is encrypted with AES-256, producing a file that standard zip tooling
 *     (`unzip -P`, 7-Zip, macOS Archive Utility, etc.) can open.
 */
class Archiver
{
    public function archive(string $stagingDir, string $outputPath, string $format = 'tar.gz', ?string $password = null): string
    {
        return match ($format) {
            'tar.gz' => $this->archiveTarGz($stagingDir, $outputPath),
            'zip' => $this->archiveZip($stagingDir, $outputPath, $password),
            default => throw new BackupFailedException("Unknown compression format '{$format}'."),
        };
    }

    private function archiveTarGz(string $stagingDir, string $outputTarGz): string
    {
        $tarPath = preg_replace('/\.gz$/', '', $outputTarGz);

        try {
            $phar = new PharData($tarPath);
            $phar->buildFromDirectory($stagingDir);
            $phar->compress(\Phar::GZ);
            unset($phar);

            if (is_file($tarPath)) {
                unlink($tarPath);
            }
        } catch (\Throwable $e) {
            throw new BackupFailedException("Archiving failed: {$e->getMessage()}", 0, $e);
        }

        if (! is_file($outputTarGz)) {
            throw new BackupFailedException("Expected archive at {$outputTarGz} but it does not exist.");
        }

        return $outputTarGz;
    }

    private function archiveZip(string $stagingDir, string $outputZip, ?string $password): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new BackupFailedException('ZipArchive extension is not available.');
        }

        $encrypted = $password !== null && $password !== '';
        if ($encrypted && ! defined('ZipArchive::EM_AES_256')) {
            throw new BackupFailedException('This PHP/libzip build does not support AES-256 zip encryption.');
        }

        $zip = new ZipArchive();
        $status = $zip->open($outputZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($status !== true) {
            throw new BackupFailedException("Could not open zip for writing (code {$status}).");
        }

        if ($encrypted) {
            $zip->setPassword($password);
        }

        $stagingDir = rtrim($stagingDir, DIRECTORY_SEPARATOR);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $localName = ltrim(substr($file->getPathname(), strlen($stagingDir)), DIRECTORY_SEPARATOR);
            if (! $zip->addFile($file->getPathname(), $localName)) {
                throw new BackupFailedException("Could not add '{$localName}' to zip.");
            }

            if ($encrypted && ! $zip->setEncryptionName($localName, ZipArchive::EM_AES_256)) {
                throw new BackupFailedException("Could not apply encryption to '{$localName}'.");
            }
        }

        if (! $zip->close()) {
            throw new BackupFailedException('Could not finalize zip archive.');
        }

        if (! is_file($outputZip)) {
            throw new BackupFailedException("Expected archive at {$outputZip} but it does not exist.");
        }

        return $outputZip;
    }
}
