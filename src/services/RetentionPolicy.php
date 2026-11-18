<?php

namespace webhubworks\backup\services;

use DateTimeImmutable;
use webhubworks\backup\services\targets\TargetInterface;

/**
 * Grandfather-Father-Son retention.
 *
 * Given a list of remote backups with timestamps parsed from filenames, decide
 * which to delete. Nothing inside "keep_all_for_days" is ever pruned. Older
 * backups fall into consecutive date ranges (daily, then weekly, monthly, and
 * yearly), and the newest backup per day, week, month, or year of its range is
 * kept. Backups older than the yearly range are deleted, except the newest
 * backup overall.
 *
 * After GFS pruning, an optional size cap
 * ("delete_oldest_backups_when_using_more_megabytes_than") drops the oldest
 * surviving backups until the total kept size is under the cap. The newest
 * backup is always retained, even if it alone exceeds the cap.
 *
 * When source.split_db_and_files is on, the two halves of a run share a runId
 * in their filenames and are bucketed together: the pair is kept or pruned as
 * one. The returned count is logical backups (groups), not files.
 */
class RetentionPolicy
{
    public function apply(TargetInterface $target, array $retention, bool $dryRun = false): int
    {
        $groups = $this->groupAndSortByDateDesc($target->list());

        $now = new DateTimeImmutable();
        $keep = [];

        $keepAllFrom = $now->modify('-' . (int) ($retention['keep_all_for_days'] ?? 7) . ' days');

        // Each range starts where the newer one ends: daily after keep-all, weekly after daily, and so on.
        $ranges = [];
        $until = $keepAllFrom;
        foreach ([
            ['keep_daily_for_days', 'days', 'Y-m-d'],
            ['keep_weekly_for_weeks', 'weeks', 'o-W'],
            ['keep_monthly_for_months', 'months', 'Y-m'],
            ['keep_yearly_for_years', 'years', 'Y'],
        ] as [$setting, $unit, $bucketFormat]) {
            $from = $until->modify('-' . (int) ($retention[$setting] ?? 0) . " {$unit}");
            $ranges[] = [$from, $until, $bucketFormat];
            $until = $from;
        }

        // Groups are sorted newest first, so the first group seen per bucket is the one kept.
        $seenBuckets = [];
        foreach ($groups as $i => $group) {
            $date = $group['date'];

            if ($date >= $keepAllFrom) {
                $keep[$i] = true;
                continue;
            }

            foreach ($ranges as $r => [$rangeFrom, $rangeUntil, $bucketFormat]) {
                if ($date >= $rangeFrom && $date < $rangeUntil) {
                    $bucket = $r . ':' . $date->format($bucketFormat);
                    if (!isset($seenBuckets[$bucket])) {
                        $seenBuckets[$bucket] = true;
                        $keep[$i] = true;
                    }
                    break;
                }
            }
        }

        // Never delete the newest backup, even when it is older than every range.
        if ($groups !== []) {
            $keep[0] = true;
        }

        $this->enforceSizeCap($groups, $keep, $retention['delete_oldest_backups_when_using_more_megabytes_than'] ?? null);

        $deleted = 0;
        foreach ($groups as $i => $group) {
            if (!isset($keep[$i])) {
                if (!$dryRun) {
                    foreach ($group['paths'] as $path) {
                        $target->delete($path);
                    }
                }
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @param list<array{paths:list<string>, date:DateTimeImmutable, size:int}> $groups
     * @param array<int, true> $keep
     */
    private function enforceSizeCap(array $groups, array &$keep, int|float|null $maxMegabytes): void
    {
        if ($maxMegabytes === null || $maxMegabytes <= 0) {
            return;
        }

        $maxBytes = (int) ($maxMegabytes * 1024 * 1024);

        $keptSize = 0;
        foreach ($groups as $i => $group) {
            if (isset($keep[$i])) {
                $keptSize += $group['size'];
            }
        }

        if ($keptSize <= $maxBytes) {
            return;
        }

        // Drop oldest kept groups until under the cap, but always retain the newest one.
        foreach (array_reverse($groups, true) as $i => $group) {
            if ($keptSize <= $maxBytes || count($keep) <= 1) {
                return;
            }
            if (!isset($keep[$i])) {
                continue;
            }
            unset($keep[$i]);
            $keptSize -= $group['size'];
        }
    }

    /**
     * @return list<array{paths: list<string>, date: DateTimeImmutable, size: int}>
     */
    private function groupAndSortByDateDesc(array $listing): array
    {
        $byRunId = [];
        $loners = [];

        foreach ($listing as $entry) {
            $path = $entry['path'];
            $size = (int) ($entry['size'] ?? 0);
            $date = $this->parseDate($path) ?? (new DateTimeImmutable())->setTimestamp((int) ($entry['modified'] ?? 0));
            $runId = BackupGrouper::parseRunId($path);

            if ($runId === null) {
                $loners[] = ['path' => $path, 'date' => $date, 'size' => $size];
                continue;
            }
            $byRunId[$runId][] = ['path' => $path, 'date' => $date, 'size' => $size];
        }

        $groups = [];
        foreach ($byRunId as $files) {
            $latest = $files[0]['date'];
            foreach ($files as $f) {
                if ($f['date'] > $latest) {
                    $latest = $f['date'];
                }
            }
            $groups[] = [
                'paths' => array_column($files, 'path'),
                'date' => $latest,
                'size' => array_sum(array_column($files, 'size')),
            ];
        }
        foreach ($loners as $f) {
            $groups[] = ['paths' => [$f['path']], 'date' => $f['date'], 'size' => $f['size']];
        }

        usort($groups, fn($a, $b) => $b['date'] <=> $a['date']);
        return $groups;
    }

    private function parseDate(string $path): ?DateTimeImmutable
    {
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})[T_-](\d{2})[:-](\d{2})[:-](\d{2})/', $path, $m)) {
            return DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}",
            ) ?: null;
        }
        return null;
    }
}
