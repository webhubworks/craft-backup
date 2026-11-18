<?php

namespace webhubworks\backup\services\notifications;

use webhubworks\backup\models\BackupResult;

/**
 * One message, built once by the Notifier and rendered by each channel in its own format.
 */
final class Notification
{
    public const EVENT_SUCCESS = 'on_success';
    public const EVENT_FAILURE = 'on_failure';
    public const EVENT_LOW_DISK_SPACE = 'on_low_disk_space';
    public const EVENT_TEST = 'test';

    /**
     * @param string $intro One sentence that says what happened.
     * @param string $body Plain-text message for mail and Slack, starting with the intro.
     */
    public function __construct(
        public readonly string $event,
        public readonly string $siteName,
        public readonly string $subject,
        public readonly string $intro,
        public readonly string $body,
        public readonly ?BackupResult $result = null,
    ) {
    }
}
