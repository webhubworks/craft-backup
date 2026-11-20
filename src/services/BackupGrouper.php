<?php

namespace webhubworks\backup\services;

use webhubworks\backup\services\targets\TargetInterface;

/**
 * Collapses a flat list of backup archives on a target into "logical backups":
 * the two halves of a split run (sharing a runId in the filename) become one
 * group. Files without a recognizable runId become their own group, so legacy
 * archives produced before the split feature still count individually.
 *
 * Used by RetentionPolicy and BackupMonitor so that GFS bucketing, the size
 * cap, and `min_number_of_backups` all treat a (db, files) pair as one
 * backup.
 */
class BackupGrouper
{
    /**
     * Returns the 8-hex-char runId embedded in a backup archive filename, or
     * null if the filename doesn't match the expected pattern.
     */
    public static function parseRunId(string $path): ?string
    {
        $name = basename($path);
        if (preg_match('/-([a-f0-9]{8})(?:-(?:db|files))?\.(zip|tar\.gz|tar\.gz\.enc)$/', $name, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Whether a filename is an archive this plugin wrote for the given backup
     * name. Targets list only these, so retention and the monitor never touch
     * another site's archives in a shared root or Craft's own `db/backup` files.
     */
    public static function isArchiveOf(string $filename, string $name): bool
    {
        return (bool) preg_match(
            '/^' . preg_quote($name, '/') . '-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-[a-f0-9]{8}(?:-(?:db|files))?\.(zip|tar\.gz|tar\.gz\.enc)$/',
            $filename,
        );
    }

    /**
     * Tells backups, sanitized copies, and Craft's own dumps apart. Sanitized
     * copies use the archive naming with a `-sanitized` suffix; Craft's dumps
     * look like `my-site--2026-11-20-140118--v5.12.1.sql.zip`. Only backups
     * match isArchiveOf(), so retention and health checks never see the others.
     *
     * @return TargetInterface::KIND_*|null
     */
    public static function kindOf(string $filename, string $name): ?string
    {
        if (self::isArchiveOf($filename, $name)) {
            return TargetInterface::KIND_BACKUP;
        }
        if (preg_match('/^' . preg_quote($name, '/') . '-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-[a-f0-9]{8}-sanitized\.(zip|tar\.gz|tar\.gz\.enc)$/', $filename)) {
            return TargetInterface::KIND_SANITIZED;
        }
        if (preg_match('/^.+--\d{4}-\d{2}-\d{2}-\d{6}--v[0-9A-Za-z.\-]+\.(sql|dump)(\.zip)?$/', $filename)) {
            return TargetInterface::KIND_CRAFT;
        }
        return null;
    }

    /**
     * @param array<int, array{path:string, size:int, modified:int, encrypted?:?bool}> $listing
     * @return list<array{paths: list<string>, size: int, modified: int}>
     */
    public static function group(array $listing): array
    {
        $byRunId = [];
        $loners = [];

        foreach ($listing as $entry) {
            $runId = self::parseRunId($entry['path']);
            if ($runId === null) {
                $loners[] = $entry;
                continue;
            }
            $byRunId[$runId][] = $entry;
        }

        $groups = [];
        foreach ($byRunId as $files) {
            $groups[] = [
                'paths' => array_column($files, 'path'),
                'size' => array_sum(array_column($files, 'size')),
                'modified' => max(array_column($files, 'modified')),
            ];
        }
        foreach ($loners as $f) {
            $groups[] = [
                'paths' => [$f['path']],
                'size' => $f['size'],
                'modified' => $f['modified'],
            ];
        }
        return $groups;
    }
}
