<?php

namespace webhubworks\backup\services\notifications;

use Craft;
use webhubworks\backup\services\Bytes;

/**
 * Sends a native Discord embed: a colored sidebar, short facts side by side,
 * and targets and errors as their own fields.
 */
final class DiscordChannel extends HttpChannel
{
    // Discord's limits per embed part, in characters.
    private const TITLE_LIMIT = 256;
    private const DESCRIPTION_LIMIT = 4096;
    private const FIELD_LIMIT = 1024;

    private const COLORS = [
        Notification::EVENT_SUCCESS => 0x2E7D32,
        Notification::EVENT_FAILURE => 0xC62828,
        Notification::EVENT_LOW_DISK_SPACE => 0xF9A825,
        Notification::EVENT_TEST => 0x5865F2,
    ];

    private const ICONS = [
        Notification::EVENT_SUCCESS => '✅',
        Notification::EVENT_FAILURE => '❌',
        Notification::EVENT_LOW_DISK_SPACE => '⚠️',
        Notification::EVENT_TEST => '🔔',
    ];

    public function label(): string
    {
        return Craft::t('backup', 'Discord');
    }

    protected function url(): ?string
    {
        return $this->stringSetting('webhook_url');
    }

    public function payload(Notification $notification): array
    {
        $title = self::ICONS[$notification->event] . ' ' . str_replace('[Craft Backup] ', '', $notification->subject);

        $embed = [
            'title' => mb_substr($title, 0, self::TITLE_LIMIT),
            'description' => mb_substr($notification->intro, 0, self::DESCRIPTION_LIMIT),
            'color' => self::COLORS[$notification->event],
            'fields' => $this->fields($notification),
            // The bot name already says Craft Backup; the site tells channels shared by several sites apart.
            'footer' => ['text' => $notification->siteName],
            'timestamp' => gmdate('c'),
        ];

        $payload = ['embeds' => [$embed]];
        if (($username = $this->stringSetting('username')) !== null) {
            $payload['username'] = $username;
        }
        if (($avatar = $this->stringSetting('avatar_url')) !== null) {
            $payload['avatar_url'] = $avatar;
        }
        return $payload;
    }

    /**
     * @return list<array{name:string, value:string, inline:bool}>
     */
    private function fields(Notification $notification): array
    {
        $result = $notification->result;
        if ($result === null) {
            return [];
        }

        if ($notification->event === Notification::EVENT_LOW_DISK_SPACE) {
            $lines = [];
            foreach ($result->lowDiskTargets as $name => $info) {
                $lines[] = sprintf(
                    '%s: %s free of %s (threshold %s)',
                    $name,
                    Bytes::format($info['free']),
                    Bytes::format($info['total']),
                    Bytes::format($info['threshold']),
                );
            }
            return array_values(array_filter([$this->field('Targets', $lines)]));
        }

        $targetLines = [];
        foreach ($result->targetStatuses as $name => $status) {
            $targetLines[] = ($status === 'ok' ? '✅ ' : '❌ ') . "{$name}: {$status}";
        }

        // Target errors already show next to their target, so list only the rest.
        $errors = array_filter($result->errors, function(string $error) use ($result) {
            foreach (array_keys($result->targetStatuses) as $name) {
                if (str_starts_with($error, "target {$name}: ")) {
                    return false;
                }
            }
            return true;
        });

        return array_values(array_filter([
            ['name' => 'Run ID', 'value' => $result->runId, 'inline' => true],
            ['name' => 'Duration', 'value' => sprintf('%.1fs', $result->durationSeconds), 'inline' => true],
            ['name' => 'Size', 'value' => Bytes::format($result->archiveBytes), 'inline' => true],
            $this->field('Targets', $targetLines ?: ['(none reached)']),
            $this->field('Errors', array_values($errors)),
        ]));
    }

    /**
     * Joins lines into one field value, cutting off with "... N more" at Discord's field limit.
     *
     * @param list<string> $lines
     * @return array{name:string, value:string, inline:bool}|null null for an empty list, since Discord rejects empty fields
     */
    private function field(string $name, array $lines): ?array
    {
        $lines = array_values(array_filter($lines, fn(string $line) => trim($line) !== ''));
        if ($lines === []) {
            return null;
        }

        // Keep room for the "... N more" line.
        $limit = self::FIELD_LIMIT - 20;
        $value = '';
        foreach ($lines as $i => $line) {
            $candidate = ltrim($value . "\n" . mb_substr($line, 0, $limit), "\n");
            if (mb_strlen($candidate) > $limit) {
                $value .= "\n... " . (count($lines) - $i) . ' more';
                break;
            }
            $value = $candidate;
        }

        return ['name' => $name, 'value' => $value, 'inline' => false];
    }
}
