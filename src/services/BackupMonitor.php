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
     * @return array{status: 'ok'|'failure', checks: array<int, array{target: string, status: 'ok'|'failure', reason?: string}>}
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
     * @return array{target: string, status: 'ok'|'failure', reason?: string}
     */
    private function checkRule(BackupConfig $config, mixed $rule, int $index): array
    {
        if (!is_array($rule)) {
            return $this->failure("(rule #{$index})", "Monitor rule #{$index} must be an array.");
        }

        $targetName = $rule['target'] ?? null;
        if (!is_string($targetName) || $targetName === '') {
            return $this->failure("(rule #{$index})", "Monitor rule #{$index} is missing a 'target' name.");
        }

        if (!array_key_exists($targetName, $config->targets)) {
            return $this->failure($targetName, "Target '{$targetName}' is not defined in the 'targets' config.");
        }

        try {
            $target = $this->buildTarget($config->targets[$targetName]);
            $files = $target->list();
        } catch (Throwable $e) {
            return $this->failure($targetName, "Could not list backups on target '{$targetName}': " . $e->getMessage());
        }

        if (array_key_exists('min_number_of_backups', $rule)) {
            $min = $rule['min_number_of_backups'];
            if (!is_int($min) || $min < 0) {
                return $this->failure($targetName, "'min_number_of_backups' must be a non-negative integer.");
            }
            if (count($files) < $min) {
                return $this->failure(
                    $targetName,
                    sprintf("Target '%s' has %d backup(s); expected at least %d.", $targetName, count($files), $min)
                );
            }
        }

        $ageKey = 'youngest_backup_should_be_within_the_last';
        if (array_key_exists($ageKey, $rule)) {
            try {
                $maxAgeSeconds = $this->parseDuration((string) $rule[$ageKey]);
            } catch (InvalidArgumentException $e) {
                return $this->failure($targetName, "Invalid '{$ageKey}' on target '{$targetName}': " . $e->getMessage());
            }

            if ($files === []) {
                return $this->failure($targetName, "Target '{$targetName}' has no backups.");
            }

            $youngest = max(array_column($files, 'modified'));
            $ageSeconds = time() - (int) $youngest;
            if ($ageSeconds > $maxAgeSeconds) {
                return $this->failure(
                    $targetName,
                    sprintf(
                        "Youngest backup on '%s' is %s old; max allowed is %s.",
                        $targetName,
                        $this->formatDuration($ageSeconds),
                        (string) $rule[$ageKey]
                    )
                );
            }
        }

        return ['target' => $targetName, 'status' => 'ok'];
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
        if ($seconds >= 86400) {
            return sprintf('%.1fd', $seconds / 86400);
        }
        if ($seconds >= 3600) {
            return sprintf('%.1fh', $seconds / 3600);
        }
        if ($seconds >= 60) {
            return sprintf('%dm', intdiv($seconds, 60));
        }
        return "{$seconds}s";
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
     * @return array{target: string, status: 'failure', reason: string}
     */
    private function failure(string $target, string $reason): array
    {
        return ['target' => $target, 'status' => 'failure', 'reason' => $reason];
    }
}
