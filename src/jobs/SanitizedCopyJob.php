<?php

namespace webhubworks\backup\jobs;

use Craft;
use craft\queue\BaseJob;
use webhubworks\backup\exceptions\BackupFailedException;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\Plugin;

/**
 * Builds a sanitized copy from the Backups utility. Runs in the queue
 * because a dump, restore, and rewrite take longer than a web request should.
 */
class SanitizedCopyJob extends BaseJob
{
    public function execute($queue): void
    {
        $result = Plugin::getInstance()->runner->sanitize(BackupConfig::load());

        if (!$result->isSuccessful()) {
            throw new BackupFailedException($result->summary());
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('backup', 'Creating sanitized copy');
    }
}
