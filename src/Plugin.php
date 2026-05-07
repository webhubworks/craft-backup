<?php

namespace webhubworks\backup;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\i18n\PhpMessageSource;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\utilities\DbBackup;
use craft\web\View;
use webhubworks\backup\assetbundles\backup\BackupAsset;
use webhubworks\backup\controllers\BackupController;
use webhubworks\backup\services\BackupMonitor;
use webhubworks\backup\services\BackupRunner;
use webhubworks\backup\services\RunStateStore;
use webhubworks\backup\twigextensions\BackupTwigExtension;
use webhubworks\backup\utilities\BackupUtility;
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
            $this->registerTranslations();
            $this->renameDbBackupUtility();
            $this->extendDbBackupUtility();
            $this->registerPermissions();
            Craft::$app->getView()->registerTwigExtension(new BackupTwigExtension());
        }
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

    private function renameDbBackupUtility(): void
    {
        $eventName = version_compare(Craft::$app->getVersion(), '5.0', '<')
            ? 'registerUtilityTypes'
            : 'registerUtilities';

        Event::on(
            Utilities::class,
            $eventName,
            function(RegisterComponentTypesEvent $event) {
                $index = array_search(DbBackup::class, $event->types, true);
                if ($index !== false) {
                    $event->types[$index] = BackupUtility::class;
                }
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('backup', 'Backups'),
                    'permissions' => [
                        'backup:download' => [
                            'label' => Craft::t('backup', 'Download backups from the Backups utility'),
                        ],
                    ],
                ];
            }
        );
    }

    private function extendDbBackupUtility(): void
    {
        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_TEMPLATE,
            function(TemplateEvent $event) {
                if ($event->templateMode !== View::TEMPLATE_MODE_CP) {
                    return;
                }

                if (!in_array($event->template, [
                    '_components/utilities/DbBackup',
                    '_components/utilities/DbBackup.twig',
                ], true)) {
                    return;
                }

                $view = Craft::$app->getView();
                $view->registerAssetBundle(BackupAsset::class);

                $statusHtml = $view->renderTemplate(
                    'backup/_status',
                    BackupController::collectShellData(),
                    View::TEMPLATE_MODE_CP,
                );

                $event->output .= '<div class="cb-utility-extension">' . $statusHtml . '</div>';
            }
        );
    }
}
