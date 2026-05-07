<?php

namespace webhubworks\backup\services;

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
                'size' => (int) ($f['size'] ?? 0),
                'modified' => (int) ($f['modified'] ?? 0),
            ];
        }
        return $groups;
    }
}
