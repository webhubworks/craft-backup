<?php

namespace webhubworks\backup\services;

use php_user_filter;

class ThrottleStreamFilter extends php_user_filter
{
    public static int $rate = 0;
    private float $windowStart = 0.0;
    private int $windowBytes = 0;

    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->waitForBudget($bucket->datalen);
            stream_bucket_append($out, $bucket);
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }

    private function waitForBudget(int $bytes): void
    {
        if (self::$rate <= 0) {
            return;
        }

        $now = microtime(true);
        if ($this->windowStart === 0.0 || ($now - $this->windowStart) >= 1.0) {
            $this->windowStart = $now;
            $this->windowBytes = 0;
        }

        $this->windowBytes += $bytes;

        if ($this->windowBytes > self::$rate) {
            $over = $this->windowBytes - self::$rate;
            $sleepSeconds = $over / self::$rate;
            usleep((int) ($sleepSeconds * 1_000_000));
            $this->windowStart = microtime(true);
            $this->windowBytes = 0;
        }
    }
}
