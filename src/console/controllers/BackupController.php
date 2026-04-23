<?php

namespace webhubworks\backup\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\Plugin;
use yii\console\ExitCode;

class BackupController extends Controller
{
    public ?bool $onlyDb = null;
    public ?bool $onlyFiles = null;
    public ?string $onlyTo = null;
    public bool $disableCleanup = false;
    public bool $dryRun = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'run' => [...$options, 'onlyDb', 'onlyFiles', 'onlyTo', 'disableCleanup', 'dryRun'],
            'clean' => [...$options, 'onlyTo', 'dryRun'],
            default => $options,
        };
    }

    public function optionAliases(): array
    {
        return [
            'db' => 'onlyDb',
            'files' => 'onlyFiles',
            'to' => 'onlyTo',
        ];
    }

    /**
     * Run a full backup: dump databases, archive source paths, compress, encrypt, upload, prune.
     */
    public function actionRun(): int
    {
        $config = $this->loadConfig();

        $result = Plugin::getInstance()->runner->run($config, [
            'only_db' => $this->onlyDb === true,
            'only_files' => $this->onlyFiles === true,
            'only_to' => $this->onlyTo,
            'disable_cleanup' => $this->disableCleanup,
            'dry_run' => $this->dryRun,
        ]);

        $this->stdout($result->summary() . PHP_EOL, Console::FG_GREEN);

        return $result->isSuccessful()
            ? ExitCode::OK
            : ($result->isPartial() ? 1 : 2);
    }

    /**
     * List existing backups on each configured target.
     */
    public function actionList(): int
    {
        $config = $this->loadConfig();

        foreach (Plugin::getInstance()->runner->list($config) as $targetName => $files) {
            $this->stdout(PHP_EOL . $targetName . PHP_EOL, Console::FG_YELLOW);
            foreach ($files as $file) {
                $this->stdout(sprintf("  %s  %10d bytes  %s\n", $file['modified'], $file['size'], $file['path']));
            }
        }

        return ExitCode::OK;
    }

    /**
     * Apply the retention policy without running a new backup.
     */
    public function actionClean(): int
    {
        $config = $this->loadConfig();

        $deleted = Plugin::getInstance()->runner->clean($config, [
            'only_to' => $this->onlyTo,
            'dry_run' => $this->dryRun,
        ]);

        $this->stdout("Deleted {$deleted} backup(s)." . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Copy the default config file to the project's config/ directory.
     */
    public function actionPublishConfig(): int
    {
        $source = dirname(__DIR__, 2) . '/config.php';
        $destination = Craft::getAlias('@config/craft-backup.php');

        if (file_exists($destination)) {
            $this->stderr("Config already exists at {$destination}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        copy($source, $destination);
        $this->stdout("Published to {$destination}\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function loadConfig(): BackupConfig
    {
        $raw = Craft::$app->config->getConfigFromFile('craft-backup');
        return BackupConfig::fromArray($raw);
    }
}
