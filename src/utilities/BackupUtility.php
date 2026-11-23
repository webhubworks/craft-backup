<?php

namespace webhubworks\backup\utilities;

use Craft;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\utilities\DbBackup;

class BackupUtility extends DbBackup
{
    public static function displayName(): string
    {
        return Craft::t('backup', 'Backups');
    }

    /**
     * Adds the "Sanitized copy" button next to Craft's own "Backup" button.
     */
    public static function contentHtml(): string
    {
        $html = parent::contentHtml();

        // type="button", so a click never submits Craft's backup form around it.
        $button = Html::button(Craft::t('backup', 'Sanitized copy'), [
            'type' => 'button',
            'class' => ['btn', 'cb-sanitize-button'],
            'title' => Craft::t('backup', 'Database dump with personal data replaced and secrets removed, for developers and staging.'),
            'data' => [
                'cb-sanitize' => true,
                'cb-action-url' => UrlHelper::actionUrl('backup/backup/sanitize'),
                'cb-label-busy' => Craft::t('backup', 'Creating…'),
                'cb-label-done' => Craft::t('backup', 'Sanitized copy is ready.'),
                'cb-label-too-large' => Craft::t('backup', 'Sanitized copy is ready, but too large to download here. See "Backups by target" for its path.'),
                'cb-label-no-download' => Craft::t('backup', 'Sanitized copy is ready, but can’t be downloaded here: it needs a local target and the permission to download backups.'),
            ],
        ]);

        $marker = '<div class="utility-status">';
        $position = strpos($html, $marker);

        return $position === false
            ? $html . $button
            : substr_replace($html, $button . $marker, $position, strlen($marker));
    }
}
