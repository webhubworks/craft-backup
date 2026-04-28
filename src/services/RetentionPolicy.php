<?php

namespace webhubworks\backup\services;

use DateTimeImmutable;
use webhubworks\backup\services\targets\TargetInterface;

/**
 * Grandfather-Father-Son retention.
 *
 * Given a list of remote backups with timestamps parsed from filenames, decide
 * which to delete. Nothing inside "keep_all_for_days" is ever pruned. Older
 * backups are bucketed by day/week/month/year and the newest per bucket is kept
 * up to the configured count.
 *
 * After GFS pruning, an optional size cap
 * ("delete_oldest_backups_when_using_more_megabytes_than") drops the oldest
 * surviving backups until the total kept size is under the cap. The newest
 * backup is always retained, even if it alone exceeds the cap.
 */
class RetentionPolicy
{
    public function apply(TargetInterface $target, array $retention, bool $dryRun = false): int
    {
        $backups = $this->sortByDateDesc($target->list());

        $now = new DateTimeImmutable();
        $keep = [];

        $keepAllUntil = $now->modify('-' . (int) ($retention['keep_all_for_days'] ?? 7) . ' days');

        $dailyLimit = (int) ($retention['keep_daily_for_days'] ?? 0);
        $weeklyLimit = (int) ($retention['keep_weekly_for_weeks'] ?? 0);
        $monthlyLimit = (int) ($retention['keep_monthly_for_months'] ?? 0);
        $yearlyLimit = (int) ($retention['keep_yearly_for_years'] ?? 0);

        $seenDay = $seenWeek = $seenMonth = $seenYear = [];

        foreach ($backups as $backup) {
            $date = $backup['date'];

            if ($date >= $keepAllUntil) {
                $keep[$backup['path']] = true;
                continue;
            }

            $dayKey = $date->format('Y-m-d');
            $weekKey = $date->format('o-W');
            $monthKey = $date->format('Y-m');
            $yearKey = $date->format('Y');

            if (count($seenDay) < $dailyLimit && !isset($seenDay[$dayKey])) {
                $seenDay[$dayKey] = true;
                $keep[$backup['path']] = true;
                continue;
            }
            if (count($seenWeek) < $weeklyLimit && !isset($seenWeek[$weekKey])) {
                $seenWeek[$weekKey] = true;
                $keep[$backup['path']] = true;
                continue;
            }
            if (count($seenMonth) < $monthlyLimit && !isset($seenMonth[$monthKey])) {
                $seenMonth[$monthKey] = true;
                $keep[$backup['path']] = true;
                continue;
            }
            if (count($seenYear) < $yearlyLimit && !isset($seenYear[$yearKey])) {
                $seenYear[$yearKey] = true;
                $keep[$backup['path']] = true;
                continue;
            }
        }

        $this->enforceSizeCap($backups, $keep, $retention['delete_oldest_backups_when_using_more_megabytes_than'] ?? null);

        $deleted = 0;
        foreach ($backups as $backup) {
            if (!isset($keep[$backup['path']])) {
                if (!$dryRun) {
                    $target->delete($backup['path']);
                }
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @param array<int, array{path: string, date: DateTimeImmutable, size: int}> $backups
     * @param array<string, true> $keep
     */
    private function enforceSizeCap(array $backups, array &$keep, int|float|null $maxMegabytes): void
    {
        if ($maxMegabytes === null || $maxMegabytes <= 0) {
            return;
        }

        $maxBytes = (int) ($maxMegabytes * 1024 * 1024);

        $keptSize = 0;
        foreach ($backups as $backup) {
            if (isset($keep[$backup['path']])) {
                $keptSize += $backup['size'];
            }
        }

        if ($keptSize <= $maxBytes) {
            return;
        }

        // Drop oldest kept backups until under the cap, but always retain the newest one.
        foreach (array_reverse($backups) as $backup) {
            if ($keptSize <= $maxBytes || count($keep) <= 1) {
                return;
            }
            if (!isset($keep[$backup['path']])) {
                continue;
            }
            unset($keep[$backup['path']]);
            $keptSize -= $backup['size'];
        }
    }

    private function sortByDateDesc(array $listing): array
    {
        $parsed = [];
        foreach ($listing as $entry) {
            $date = $this->parseDate($entry['path']) ?? (new DateTimeImmutable())->setTimestamp($entry['modified'] ?? 0);
            $parsed[] = [
                'path' => $entry['path'],
                'date' => $date,
                'size' => (int) ($entry['size'] ?? 0),
            ];
        }

        usort($parsed, fn($a, $b) => $b['date'] <=> $a['date']);
        return $parsed;
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
