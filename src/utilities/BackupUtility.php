<?php

namespace webhubworks\backup\utilities;

use Craft;
use craft\utilities\DbBackup;

class BackupUtility extends DbBackup
{
    public static function displayName(): string
    {
        return Craft::t('backup', 'Backup');
    }
}
