<?php

namespace webhubworks\backup\services;

use Craft;
use webhubworks\backup\exceptions\BackupFailedException;

/**
 * Writes a SQL dump for each configured DB connection into the staging directory.
 * Delegates to Craft's own backup command, which already handles driver differences.
 */
class DbDumper
{
    /**
     * @param string[] $connectionIds
     * @return string[] Absolute paths of the dump files that were produced.
     */
    public function dump(array $connectionIds, string $stagingDir): array
    {
        $outputs = [];

        foreach ($connectionIds as $connectionId) {
            $db = $connectionId === 'db'
                ? Craft::$app->getDb()
                : Craft::$app->get($connectionId);

            if ($db === null) {
                throw new BackupFailedException("DB connection '{$connectionId}' is not registered.");
            }

            $target = $stagingDir . DIRECTORY_SEPARATOR . "db-{$connectionId}.sql";

            $db->backupTo($target);
            if (!is_file($target)) {
                throw new BackupFailedException("Craft DB backup for '{$connectionId}' did not produce a file.");
            }

            $outputs[] = $target;
        }

        return $outputs;
    }
}
