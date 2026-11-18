<?php

namespace webhubworks\backup\services\notifications;

use Craft;

final class SlackChannel extends HttpChannel
{
    public function label(): string
    {
        return Craft::t('backup', 'Slack');
    }

    public function detail(): ?string
    {
        return $this->stringSetting('channel');
    }

    protected function url(): ?string
    {
        return $this->stringSetting('webhook_url');
    }

    public function payload(Notification $notification): array
    {
        $payload = ['text' => "*{$notification->subject}*\n```\n{$notification->body}\n```"];
        if (($channel = $this->stringSetting('channel')) !== null) {
            $payload['channel'] = $channel;
        }
        if (($username = $this->stringSetting('username')) !== null) {
            $payload['username'] = $username;
        }
        if (($icon = $this->stringSetting('icon')) !== null) {
            $payload[str_starts_with($icon, ':') ? 'icon_emoji' : 'icon_url'] = $icon;
        }
        return $payload;
    }
}
