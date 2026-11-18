<?php

namespace webhubworks\backup\services\notifications;

use Craft;

/**
 * POSTs a versioned JSON document. With a `secret`, the body is signed with
 * HMAC-SHA256 so receivers can check that it came from this site.
 */
final class WebhookChannel extends HttpChannel
{
    public const SCHEMA_VERSION = 1;

    private const STATUSES = [
        Notification::EVENT_SUCCESS => 'success',
        Notification::EVENT_FAILURE => 'failure',
        Notification::EVENT_LOW_DISK_SPACE => 'low_disk_space',
        Notification::EVENT_TEST => 'test',
    ];

    public function label(): string
    {
        return Craft::t('backup', 'Webhook');
    }

    public function detail(): ?string
    {
        // Host only: the path of a webhook URL often carries a token.
        return parse_url((string) $this->url(), PHP_URL_HOST) ?: null;
    }

    protected function url(): ?string
    {
        return $this->stringSetting('url');
    }

    public function payload(Notification $notification): array
    {
        $result = $notification->result;

        $targets = [];
        foreach ($result->targetStatuses ?? [] as $name => $status) {
            $targets[$name] = $status === 'ok'
                ? ['status' => 'ok', 'error' => null]
                : ['status' => 'failed', 'error' => preg_replace('/^failed: /', '', $status)];
        }

        $lowDisk = [];
        foreach ($result->lowDiskTargets ?? [] as $name => $info) {
            $lowDisk[$name] = ['free_bytes' => $info['free'], 'total_bytes' => $info['total'], 'threshold_bytes' => $info['threshold']];
        }

        $archives = [];
        foreach ($result->archiveSizes ?? [] as $name => $bytes) {
            $archives[] = ['name' => $name, 'bytes' => $bytes];
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'event' => $notification->event,
            'status' => self::STATUSES[$notification->event],
            'site' => $notification->siteName,
            'timestamp' => gmdate('c'),
            'run_id' => $result?->runId,
            'duration_seconds' => $result !== null ? round($result->durationSeconds, 1) : null,
            'archives' => $archives,
            'targets' => (object) $targets,
            'low_disk_targets' => (object) $lowDisk,
            'errors' => $result->errors ?? [],
        ];
    }

    protected function headers(string $json): array
    {
        $secret = $this->stringSetting('secret');
        return $secret === null ? [] : ['X-Craft-Backup-Signature' => 'sha256=' . hash_hmac('sha256', $json, $secret)];
    }
}
