<?php

namespace webhubworks\backup\services;

/**
 * Returns a read stream that sleeps to cap throughput at a byte/sec rate.
 * Useful on small VPS uploads where saturating the pipe hurts the live site.
 */
class Throttler
{
    /**
     * @return resource
     */
    public function throttle($stream, int $bytesPerSecond)
    {
        if ($bytesPerSecond <= 0) {
            return $stream;
        }

        stream_filter_register('craft-backup.throttle', ThrottleStreamFilter::class);
        ThrottleStreamFilter::$rate = $bytesPerSecond;
        stream_filter_append($stream, 'craft-backup.throttle', STREAM_FILTER_READ);

        return $stream;
    }
}
