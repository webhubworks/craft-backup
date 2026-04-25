<?php

namespace webhubworks\backup;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterUrlRulesEvent;
use craft\i18n\PhpMessageSource;
use craft\web\UrlManager;
use webhubworks\backup\services\BackupMonitor;
use webhubworks\backup\services\BackupRunner;
use webhubworks\backup\services\RunStateStore;
use yii\base\Event;

/**
 * Craft Backup plugin.
 *
 * @method static Plugin getInstance()
 * @property BackupRunner $runner
 * @property BackupMonitor $monitor
 * @property RunStateStore $runState
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'runner' => BackupRunner::class,
            'monitor' => BackupMonitor::class,
            'runState' => RunStateStore::class,
        ]);

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'webhubworks\\backup\\console\\controllers';
        } else {
            $this->controllerNamespace = 'webhubworks\\backup\\controllers';
            $this->registerCpRoutes();
            $this->registerTranslations();
        }
    }

    protected function cpNavIconPath(): ?string
    {
        return Craft::getAlias('@webhubworks/backup/nav-icon.svg') ?: null;
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['backup'] = 'backup/backup/index';
            }
        );
    }

    private function registerTranslations(): void
    {
        Craft::$app->i18n->translations['backup'] = [
            'class' => PhpMessageSource::class,
            'sourceLanguage' => 'en',
            'basePath' => __DIR__ . '/translations',
            'allowOverrides' => true,
        ];
    }
}
