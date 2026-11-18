<?php

namespace webhubworks\backup\services\notifications;

interface NotificationChannel
{
    public function label(): string;

    /**
     * Extra detail for the Notifications card, such as the Slack channel, or null.
     */
    public function detail(): ?string;

    public function isConfigured(): bool;

    public function wants(string $event): bool;

    public function supportsTest(): bool;

    /**
     * @throws \Throwable when the message could not be delivered
     */
    public function send(Notification $notification): void;
}
