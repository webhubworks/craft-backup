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

            if (count($seenDay) < $dailyLimit && ! isset($seenDay[$dayKey])) {
                $seenDay[$dayKey] = true;
                $keep[$backup['path']] = true;
                continue;
            }
            if (count($seenWeek) < $weeklyLimit && ! isset($seenWeek[$weekKey])) {
                $seenWeek[$weekKey] = true;
                $keep[$backup['path']] = true;
                continue;
            }
            if (count($seenMonth) < $monthlyLimit && ! isset($seenMonth[$monthKey])) {
                $seenMonth[$monthKey] = true;
                $keep[$backup['path']] = true;
                continue;
            }
            if (count($seenYear) < $yearlyLimit && ! isset($seenYear[$yearKey])) {
                $seenYear[$yearKey] = true;
                $keep[$backup['path']] = true;
                continue;
            }
        }

        $deleted = 0;
        foreach ($backups as $backup) {
            if (! isset($keep[$backup['path']])) {
                if (! $dryRun) {
                    $target->delete($backup['path']);
                }
                $deleted++;
            }
        }

        return $deleted;
    }

    private function sortByDateDesc(array $listing): array
    {
        $parsed = [];
        foreach ($listing as $entry) {
            $date = $this->parseDate($entry['path']) ?? (new DateTimeImmutable())->setTimestamp($entry['modified'] ?? 0);
            $parsed[] = ['path' => $entry['path'], 'date' => $date];
        }

        usort($parsed, fn ($a, $b) => $b['date'] <=> $a['date']);
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
