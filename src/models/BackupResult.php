<?php

namespace webhubworks\backup\models;

final class BackupResult
{
    /** @param array<string, string> $targetStatuses target name => 'ok' | 'failed: reason' */
    public function __construct(
        public readonly string $runId,
        public readonly ?string $archivePath,
        public readonly int $archiveBytes,
        public readonly float $durationSeconds,
        public readonly array $targetStatuses,
        public readonly array $errors,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->errors === [] && ! $this->hasFailedTargets();
    }

    public function isPartial(): bool
    {
        return $this->archivePath !== null && $this->hasFailedTargets();
    }

    public function summary(): string
    {
        $sizeMb = number_format($this->archiveBytes / 1024 / 1024, 2);
        $targets = array_map(
            fn (string $name, string $status) => "{$name}={$status}",
            array_keys($this->targetStatuses),
            $this->targetStatuses,
        );

        $line = sprintf(
            '[%s] %.1fs  %s MB  %s',
            $this->runId,
            $this->durationSeconds,
            $sizeMb,
            implode(' ', $targets) ?: '(no targets)',
        );

        if ($this->errors !== []) {
            $line .= PHP_EOL . 'Errors:' . PHP_EOL . '  - ' . implode(PHP_EOL . '  - ', $this->errors);
        }

        return $line;
    }

    private function hasFailedTargets(): bool
    {
        foreach ($this->targetStatuses as $status) {
            if ($status !== 'ok') {
                return true;
            }
        }
        return false;
    }
}
