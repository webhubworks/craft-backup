<?php

namespace webhubworks\backup\models;

final class BackupResult
{
    /**
     * @param list<string> $archivePaths Local paths of the archive(s) produced by this run.
     *        One entry for a combined run; two entries (db + files) when source.split_db_and_files is on.
     * @param array<string, string> $targetStatuses target name => 'ok' | 'failed: reason'
     * @param array<string, array{free:int, threshold:int, total:int}> $lowDiskTargets
     *        target name => post-upload disk snapshot for targets that fell below their warn threshold
     */
    public function __construct(
        public readonly string $runId,
        public readonly array $archivePaths,
        public readonly int $archiveBytes,
        public readonly float $durationSeconds,
        public readonly array $targetStatuses,
        public readonly array $errors,
        public readonly array $lowDiskTargets = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->errors === [] && !$this->hasFailedTargets();
    }

    public function isPartial(): bool
    {
        return $this->archivePaths !== [] && $this->hasFailedTargets();
    }

    public function summary(): string
    {
        $sizeMb = number_format($this->archiveBytes / 1024 / 1024, 2);
        $targets = array_map(
            fn(string $name, string $status) => "{$name}={$status}",
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
