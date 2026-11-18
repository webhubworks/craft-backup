<?php

namespace webhubworks\backup\services\notifications;

use Craft;
use RuntimeException;
use Throwable;

/**
 * Base for channels that POST a JSON payload to a URL from the config.
 */
abstract class HttpChannel implements NotificationChannel
{
    /**
     * @param array<string, mixed> $settings The channel's block from `notifications`.
     */
    public function __construct(protected readonly array $settings)
    {
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function payload(Notification $notification): array;

    abstract protected function url(): ?string;

    public function detail(): ?string
    {
        return null;
    }

    public function isConfigured(): bool
    {
        return $this->url() !== null;
    }

    public function wants(string $event): bool
    {
        return $this->isConfigured() && (bool) ($this->settings[$event] ?? false);
    }

    public function supportsTest(): bool
    {
        return true;
    }

    public function send(Notification $notification): void
    {
        $json = json_encode($this->payload($notification), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $url = (string) $this->url();
        try {
            Craft::createGuzzleClient()->post($url, [
                'body' => $json,
                'headers' => ['Content-Type' => 'application/json'] + $this->headers($json),
                'timeout' => 10,
            ]);
        } catch (Throwable $e) {
            // Guzzle puts URLs in its messages, re-encoded and possibly a redirect target, and
            // webhook URLs carry their token in the path. Keep only scheme, host, and port of
            // every URL, and don't chain $e, whose message still holds the full URL.
            throw new RuntimeException((string) preg_replace(
                '#(https?://)(?:[^/@\s`]*@)?([^/?\#\s`]+)[^\s`]*#i',
                '$1$2/[redacted]',
                $e->getMessage(),
            ));
        }
    }

    /**
     * @return array<string, string>
     */
    protected function headers(string $json): array
    {
        return [];
    }

    protected function stringSetting(string $key): ?string
    {
        $value = $this->settings[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }
}
