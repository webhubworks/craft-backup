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
            'tb' => 1024 ** 4,
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

        return $threshold['bytes'] ?? 0;
    }
}
