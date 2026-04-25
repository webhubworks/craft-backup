<?php

namespace webhubworks\backup\services;

use Craft;
use craft\helpers\FileHelper;
use Throwable;
use webhubworks\backup\models\BackupResult;
use yii\base\Component;

/**
 * Persists a small JSON record of the most recent backup run so the CP page
 * can surface "last run", "last successful run", and the failure reason of
 * the last attempt without scanning every target.
 */
class RunStateStore extends Component
{
    /**
     * @return array{
     *     lastRun: ?array{at:int, status:'ok'|'failed', errors:array<int,string>, targetStatuses:array<string,string>},
     *     lastSuccessfulRun: ?array{at:int}
     * }
     */
    public function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return ['lastRun' => null, 'lastSuccessfulRun' => null];
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['lastRun' => null, 'lastSuccessfulRun' => null];
        }

        return [
            'lastRun' => is_array($data['lastRun'] ?? null) ? $data['lastRun'] : null,
            'lastSuccessfulRun' => is_array($data['lastSuccessfulRun'] ?? null) ? $data['lastSuccessfulRun'] : null,
        ];
    }

    public function record(BackupResult $result): void
    {
        $current = $this->read();
        $now = time();
        $successful = $result->isSuccessful();

        $current['lastRun'] = [
            'at' => $now,
            'status' => $successful ? 'ok' : 'failed',
            'errors' => $result->errors,
            'targetStatuses' => $result->targetStatuses,
        ];

        if ($successful) {
            $current['lastSuccessfulRun'] = ['at' => $now];
        }

        $path = $this->path();
        FileHelper::createDirectory(dirname($path));
        file_put_contents($path, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function path(): string
    {
        return Craft::$app->getPath()->getRuntimePath() . '/craft-backup/state.json';
    }
}
