<?php

namespace webhubworks\backup\services;

use PharData;
use webhubworks\backup\exceptions\BackupFailedException;

/**
 * Produces a gzipped tar of the staging directory.
 *
 * Uses PharData because it ships with core PHP (no ext dependency), streams
 * additions file-by-file, and handles large archives without loading them into memory.
 */
class Archiver
{
    public function archive(string $stagingDir, string $outputTarGz): string
    {
        $tarPath = preg_replace('/\.gz$/', '', $outputTarGz);

        try {
            $phar = new PharData($tarPath);
            $phar->buildFromDirectory($stagingDir);
            $phar->compress(\Phar::GZ);
            unset($phar);

            // Remove the uncompressed intermediate tar.
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
}
