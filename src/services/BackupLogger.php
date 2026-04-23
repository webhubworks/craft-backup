<?php

namespace webhubworks\backup\services;

use Craft;
use yii\base\Component;

/**
 * Thin PSR-3-ish logger that threads a run id through every line so multi-run
 * logs stay readable, and mirrors output to Craft's own log channel.
 */
class BackupLogger extends Component
{
    public string $runId = '';

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $formatted = sprintf(
            '[%s][%s] %s%s',
            $this->runId ?: '-',
            strtoupper($level),
            $message,
            $context ? ' ' . json_encode($context) : '',
        );

        match ($level) {
            'error' => Craft::error($formatted, 'craft-backup'),
            'warning' => Craft::warning($formatted, 'craft-backup'),
            default => Craft::info($formatted, 'craft-backup'),
        };
    }
}
