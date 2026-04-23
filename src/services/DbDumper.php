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

            $craftBackupPath = $db->backup();
            if (! is_string($craftBackupPath) || ! is_file($craftBackupPath)) {
                throw new BackupFailedException("Craft DB backup for '{$connectionId}' did not produce a file.");
            }

            rename($craftBackupPath, $target);
            $outputs[] = $target;
        }

        return $outputs;
    }
}
