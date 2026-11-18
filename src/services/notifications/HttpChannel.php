<?php

namespace webhubworks\backup\services\notifications;

use Craft;

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

        Craft::createGuzzleClient()->post((string) $this->url(), [
            'body' => $json,
            'headers' => ['Content-Type' => 'application/json'] + $this->headers($json),
            'timeout' => 10,
        ]);
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
