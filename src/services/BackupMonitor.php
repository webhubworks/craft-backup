<?php

namespace webhubworks\backup\services;

use InvalidArgumentException;
use Throwable;
use webhubworks\backup\exceptions\BackupFailedException;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\services\targets\LocalTarget;
use webhubworks\backup\services\targets\SftpTarget;
use webhubworks\backup\services\targets\TargetInterface;
use yii\base\Component;

/**
 * Evaluates the configured monitor_backups rules against each target and
 * returns a structured result suitable for JSON output / external health
 * checks.
 */
class BackupMonitor extends Component
{
    /**
     * @return array{
     *     status: 'ok'|'failure',
     *     reason?: string,
     *     checks?: array<int, array{
     *         target: string,
     *         status: 'ok'|'failure',
     *         reason?: string,
     *         items: array<int, array{id: string, label: string, status: 'ok'|'failure'|'skipped', detail?: string}>
     *     }>
     * }
     */
    public function check(BackupConfig $config): array
    {
        if ($config->monitorBackups === []) {
            return [
                'status' => 'failure',
                'reason' => "No 'monitor_backups' rules defined. Add at least one rule to config/backup.php to enable monitoring.",
            ];
        }

        $checks = [];

        foreach ($config->monitorBackups as $index => $rule) {
            $checks[] = $this->checkRule($config, $rule, $index);
        }

        $overall = 'ok';
        foreach ($checks as $check) {
            if ($check['status'] === 'failure') {
                $overall = 'failure';
                break;
            }
        }

        return ['status' => $overall, 'checks' => $checks];
    }

    /**
     * @return array{
     *     target: string,
     *     status: 'ok'|'failure',
     *     reason?: string,
     *     items: array<int, array{id: string, label: string, status: 'ok'|'failure'|'skipped', detail?: string}>
     * }
     */
    private function checkRule(BackupConfig $config, mixed $rule, int $index): array
    {
        if (!is_array($rule)) {
            return $this->groupResult("(rule #{$index})", [
                $this->item('rule_valid', "Rule is a valid array", 'failure', "Rule #{$index} must be an array."),
            ]);
        }

        $targetName = $rule['target'] ?? null;
        if (!is_string($targetName) || $targetName === '') {
            return $this->groupResult("(rule #{$index})", [
                $this->item('rule_target_set', "Rule has a target name", 'failure', "Rule #{$index} is missing a 'target' name."),
            ]);
        }

        $hasMin = array_key_exists('min_number_of_backups', $rule);
        $hasAge = array_key_exists('youngest_backup_should_be_within_the_last', $rule);
        $minLabel = $hasMin ? 'Minimum number of backups' : null;
        $ageLabel = $hasAge ? sprintf('Youngest backup within %s', (string) $rule['youngest_backup_should_be_within_the_last']) : null;

        $items = [];

        if (!array_key_exists($targetName, $config->targets)) {
            $items[] = $this->item('target_reachable', 'Target is reachable', 'failure', "Target '{$targetName}' is not defined in the 'targets' config.");
            if ($hasMin) {
                $items[] = $this->item('min_backups', $minLabel, 'skipped', 'Skipped — target not defined.');
            }
            if ($hasAge) {
                $items[] = $this->item('youngest_age', $ageLabel, 'skipped', 'Skipped — target not defined.');
            }
            return $this->groupResult($targetName, $items);
        }

        // 1. Target is reachable
        try {
            $target = $this->buildTarget($config->targets[$targetName]);
            $files = $target->list();
            $items[] = $this->item('target_reachable', 'Target is reachable', 'ok');
        } catch (Throwable $e) {
            $items[] = $this->item('target_reachable', 'Target is reachable', 'failure', "Could not list backups: " . $e->getMessage());
            if ($hasMin) {
                $items[] = $this->item('min_backups', $minLabel, 'skipped', 'Skipped — target unreachable.');
            }
            if ($hasAge) {
                $items[] = $this->item('youngest_age', $ageLabel, 'skipped', 'Skipped — target unreachable.');
            }
            return $this->groupResult($targetName, $items);
        }

        // 3. Minimum number of backups
        if ($hasMin) {
            $min = $rule['min_number_of_backups'];
            if (!is_int($min) || $min < 0) {
                $items[] = $this->item('min_backups', 'Minimum number of backups', 'failure', "'min_number_of_backups' must be a non-negative integer.");
            } else {
                $count = count($files);
                $ok = $count >= $min;
                $items[] = $this->item(
                    'min_backups',
                    sprintf('At least %d backup(s) present', $min),
                    $ok ? 'ok' : 'failure',
                    $ok
                        ? sprintf('Found %d', $count)
                        : sprintf('Found only %d, expected at least %d.', $count, $min),
                );
            }
        }

        // 4. Youngest backup within max age
        if ($hasAge) {
            $rawAge = (string) $rule['youngest_backup_should_be_within_the_last'];
            $maxAgeSeconds = null;
            try {
                $maxAgeSeconds = $this->parseDuration($rawAge);
            } catch (InvalidArgumentException $e) {
                $items[] = $this->item('youngest_age', "Youngest backup within {$rawAge}", 'failure', $e->getMessage());
            }

            if ($maxAgeSeconds !== null) {
                if ($files === []) {
                    $items[] = $this->item('youngest_age', "Youngest backup within {$rawAge}", 'failure', 'No backups found on target.');
                } else {
                    $youngest = max(array_column($files, 'modified'));
                    $ageSeconds = time() - (int) $youngest;
                    $ok = $ageSeconds <= $maxAgeSeconds;
                    $formatted = $this->formatDuration(max(0, $ageSeconds));
                    $items[] = $this->item(
                        'youngest_age',
                        "Youngest backup within {$rawAge}",
                        $ok ? 'ok' : 'failure',
                        $ok
                            ? "Last backup {$formatted} ago"
                            : "Last backup {$formatted} ago; max allowed is {$rawAge}.",
                    );
                }
            }
        }

        return $this->groupResult($targetName, $items);
    }

    /**
     * Parses shorthand durations like '6h', '30m', '45s', '2d' into seconds.
     */
    private function parseDuration(string $input): int
    {
        $input = trim($input);
        if (!preg_match('/^(\d+)\s*([smhd])$/i', $input, $m)) {
            throw new InvalidArgumentException("'{$input}' is not a valid duration (use e.g. '6h', '30m', '2d').");
        }

        $value = (int) $m[1];
        return match (strtolower($m[2])) {
            's' => $value,
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
        };
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'min';
        }
        if ($seconds < 86400) {
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            return $m > 0 ? "{$h}h {$m}min" : "{$h}h";
        }
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        return $h > 0 ? "{$d}d {$h}h" : "{$d}d";
    }

    private function buildTarget(array $def): TargetInterface
    {
        return match ($def['driver'] ?? null) {
            'local' => new LocalTarget($def),
            'sftp' => new SftpTarget($def),
            default => throw new BackupFailedException("Unknown target driver '{$def['driver']}'."),
        };
    }

    /**
     * @param array<int, array{id: string, label: string, status: 'ok'|'failure'|'skipped', detail?: string}> $items
     * @return array{target: string, status: 'ok'|'failure', reason?: string, items: array<int, array{id: string, label: string, status: 'ok'|'failure'|'skipped', detail?: string}>}
     */
    private function groupResult(string $target, array $items): array
    {
        $status = 'ok';
        $reason = null;
        foreach ($items as $item) {
            if ($item['status'] === 'failure') {
                $status = 'failure';
                $reason = $item['detail'] ?? $item['label'];
                break;
            }
        }

        $result = ['target' => $target, 'status' => $status, 'items' => $items];
        if ($reason !== null) {
            $result['reason'] = $reason;
        }
        return $result;
    }

    /**
     * @return array{id: string, label: string, status: 'ok'|'failure'|'skipped', detail?: string}
     */
    private function item(string $id, string $label, string $status, ?string $detail = null): array
    {
        $out = ['id' => $id, 'label' => $label, 'status' => $status];
        if ($detail !== null && $detail !== '') {
            $out['detail'] = $detail;
        }
        return $out;
    }
}
