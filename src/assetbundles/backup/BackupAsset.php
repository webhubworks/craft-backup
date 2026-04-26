<?php

namespace webhubworks\backup\assetbundles\backup;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class BackupAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@webhubworks/backup/assetbundles/backup/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/backup.css',
        ];

        $this->js = [
            'js/backup.js',
        ];

        parent::init();
    }
}
