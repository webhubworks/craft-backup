<?php

namespace webhubworks\backup\services;

use InvalidArgumentException;

final class Bytes
{
    /**
     * Parses sizes like '5GB', '500 MB', '1024' (bytes), or an integer/float
     * already in bytes. Returns null for null/empty input.
     */
    public static function parse(string|int|float|null $input): ?int
    {
        if ($input === null || $input === '' || $input === 0 || $input === 0.0) {
            return $input === null || $input === '' ? null : 0;
        }

        if (is_int($input) || is_float($input)) {
            return (int) $input;
        }

        $trimmed = trim($input);
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*(b|kb|mb|gb|tb)?$/i', $trimmed, $m)) {
            throw new InvalidArgumentException("'{$input}' is not a valid size (use e.g. '5GB', '500MB', or a byte count).");
        }

        $value = (float) $m[1];
        $multiplier = match (strtolower($m[2] ?? 'b')) {
            'b' => 1,
            'kb' => 1024,
            'mb' => 1024 ** 2,
            'gb' => 1024 ** 3,
            default => 1024 ** 4, // 'tb', the only unit the regex leaves
        };

        return (int) ($value * $multiplier);
    }

    /**
     * Parses a low-disk threshold expressed either as an absolute size
     * ('5GB', '500MB', '1024') or as a percentage of total disk ('20%',
     * '12.5 %'). Returns null for null/empty input. Use resolveThreshold()
     * to convert the result to absolute bytes against a known total.
     *
     * @return array{bytes:int}|array{percent:float}|null
     */
    public static function parseThreshold(string|int|float|null $input): ?array
    {
        if ($input === null || $input === '') {
            return null;
        }

        if (is_string($input) && preg_match('/^(\d+(?:\.\d+)?)\s*%$/', trim($input), $m)) {
            $percent = (float) $m[1];
            if ($percent < 0 || $percent > 100) {
                throw new InvalidArgumentException("'{$input}' is out of range (use 0–100%).");
            }
            return ['percent' => $percent];
        }

        $bytes = self::parse($input);
        return $bytes === null ? null : ['bytes' => $bytes];
    }

    /**
     * Resolves a parsed threshold to an absolute byte count. A percentage is
     * computed against the supplied total; an absolute byte threshold is
     * returned as-is.
     *
     * @param array{bytes:int}|array{percent:float} $threshold
     */
    public static function resolveThreshold(array $threshold, int $totalBytes): int
    {
        if (isset($threshold['percent'])) {
            return (int) round($totalBytes * $threshold['percent'] / 100);
        }

        return $threshold['bytes'];
    }

    /**
     * Formats a byte count for messages, e.g. "1.24 MB".
     */
    public static function format(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / (1024 ** 3), 2) . ' GB';
        }
        if ($bytes >= 1024 ** 2) {
            return number_format($bytes / (1024 ** 2), 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
