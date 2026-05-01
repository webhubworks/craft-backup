<?php

namespace webhubworks\backup\services;

use Craft;
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
        $minLabel = $hasMin ? Craft::t('backup', 'Minimum number of backups') : null;
        $ageLabel = $hasAge
            ? Craft::t('backup', 'Youngest backup within {duration}', ['duration' => (string) $rule['youngest_backup_should_be_within_the_last']])
            : null;

        $items = [];
        $reachableLabel = Craft::t('backup', 'Target is reachable');

        if (!array_key_exists($targetName, $config->targets)) {
            $items[] = $this->item(
                'target_reachable',
                $reachableLabel,
                'failure',
                Craft::t('backup', "Target '{name}' is not defined in the 'targets' config.", ['name' => $targetName]),
            );
            $skippedNotDefined = Craft::t('backup', 'Skipped — target not defined.');
            if ($hasMin) {
                $items[] = $this->item('min_backups', $minLabel, 'skipped', $skippedNotDefined);
            }
            if ($hasAge) {
                $items[] = $this->item('youngest_age', $ageLabel, 'skipped', $skippedNotDefined);
            }
            return $this->groupResult($targetName, $items);
        }

        // 1. Target is reachable
        try {
            $target = $this->buildTarget($config->targets[$targetName]);
            $files = $target->list();
            $items[] = $this->item('target_reachable', $reachableLabel, 'ok');
        } catch (Throwable $e) {
            $items[] = $this->item(
                'target_reachable',
                $reachableLabel,
                'failure',
                Craft::t('backup', 'Could not list backups: {error}', ['error' => $e->getMessage()]),
            );
            $skippedUnreachable = Craft::t('backup', 'Skipped — target unreachable.');
            if ($hasMin) {
                $items[] = $this->item('min_backups', $minLabel, 'skipped', $skippedUnreachable);
            }
            if ($hasAge) {
                $items[] = $this->item('youngest_age', $ageLabel, 'skipped', $skippedUnreachable);
            }
            return $this->groupResult($targetName, $items);
        }

        // 2. Minimum number of backups
        if ($hasMin) {
            $min = $rule['min_number_of_backups'];
            if (!is_int($min) || $min < 0) {
                $items[] = $this->item(
                    'min_backups',
                    $minLabel,
                    'failure',
                    "'min_number_of_backups' must be a non-negative integer.",
                );
            } else {
                $count = count($files);
                $ok = $count >= $min;
                $items[] = $this->item(
                    'min_backups',
                    Craft::t('backup', 'At least {n} backup(s) present', ['n' => $min]),
                    $ok ? 'ok' : 'failure',
                    $ok
                        ? Craft::t('backup', 'Found {count}', ['count' => $count])
                        : Craft::t('backup', 'Found only {count}, expected at least {min}.', ['count' => $count, 'min' => $min]),
                );
            }
        }

        // 3. Youngest backup within max age
        if ($hasAge) {
            $rawAge = (string) $rule['youngest_backup_should_be_within_the_last'];
            $maxAgeSeconds = null;
            try {
                $maxAgeSeconds = $this->parseDuration($rawAge);
            } catch (InvalidArgumentException $e) {
                $items[] = $this->item('youngest_age', $ageLabel, 'failure', $e->getMessage());
            }

            $graceSeconds = 15 * 60;
            if (array_key_exists('youngest_backup_grace', $rule)) {
                try {
                    $graceSeconds = $this->parseDuration((string) $rule['youngest_backup_grace']);
                } catch (InvalidArgumentException $e) {
                    $items[] = $this->item('youngest_age', $ageLabel, 'failure', $e->getMessage());
                    $maxAgeSeconds = null;
                }
            }

            if ($maxAgeSeconds !== null) {
                if ($files === []) {
                    $items[] = $this->item(
                        'youngest_age',
                        $ageLabel,
                        'failure',
                        Craft::t('backup', 'No backups found on target.'),
                    );
                } else {
                    $youngest = max(array_column($files, 'modified'));
                    $ageSeconds = time() - (int) $youngest;
                    $ok = $ageSeconds <= $maxAgeSeconds + $graceSeconds;
                    $formatted = $this->formatDuration(max(0, $ageSeconds));
                    $items[] = $this->item(
                        'youngest_age',
                        $ageLabel,
                        $ok ? 'ok' : 'failure',
                        $ok
                            ? Craft::t('backup', 'Last backup {age} ago', ['age' => $formatted])
                            : Craft::t('backup', 'Last backup {age} ago; max allowed is {duration}.', ['age' => $formatted, 'duration' => $rawAge]),
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
