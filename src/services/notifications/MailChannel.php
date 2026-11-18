<?php

namespace webhubworks\backup\services\notifications;

use Craft;
use RuntimeException;

final class MailChannel implements NotificationChannel
{
    /**
     * @param array<string, mixed> $settings The `notifications.mail` block: event => list of addresses.
     */
    public function __construct(private readonly array $settings)
    {
    }

    public function label(): string
    {
        return Craft::t('backup', 'Mail');
    }

    public function detail(): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function recipients(string $event): array
    {
        return array_values(array_filter(array_map('strval', (array) ($this->settings[$event] ?? []))));
    }

    public function isConfigured(): bool
    {
        foreach ([Notification::EVENT_FAILURE, Notification::EVENT_SUCCESS, Notification::EVENT_LOW_DISK_SPACE] as $event) {
            if ($this->recipients($event) !== []) {
                return true;
            }
        }
        return false;
    }

    public function wants(string $event): bool
    {
        return $this->recipients($event) !== [];
    }

    public function supportsTest(): bool
    {
        return false;
    }

    public function send(Notification $notification): void
    {
        $sent = Craft::$app->getMailer()
            ->compose()
            ->setTo($this->recipients($notification->event))
            ->setSubject($notification->subject)
            ->setTextBody($notification->body)
            ->send();

        if (!$sent) {
            throw new RuntimeException('The mailer did not send the message.');
        }
    }
}
