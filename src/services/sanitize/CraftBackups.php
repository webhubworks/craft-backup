<?php

namespace webhubworks\backup\services\sanitize;

use Craft;
use craft\db\Connection;
use craft\events\BackupEvent;
use craft\helpers\Db;
use Throwable;
use webhubworks\backup\exceptions\BackupFailedException;

/**
 * Dumps the site database and creates scratch databases, on Craft 4 and 5
 * (Yii connections) and Craft 6 (Laravel connections).
 */
class CraftBackups
{
    /**
     * Dumps the default connection. Rows of $ignoreTables are left out; their
     * structure stays, so the scratch copy has every table.
     *
     * @param list<string> $ignoreTables Unprefixed table names.
     */
    public function dumpSource(string $file, array $ignoreTables): void
    {
        if (CraftSchema::isCraft6()) {
            $connection = \Illuminate\Support\Facades\DB::connection();
            $backups = app(\CraftCms\Cms\Database\Backups::class);
            $backups->backupTo($file, $connection, ignoreTables: [...self::craft6DefaultIgnoredTables(), ...$ignoreTables]);
            return;
        }

        $db = Craft::$app->getDb();
        $handler = function(BackupEvent $event) use ($ignoreTables) {
            foreach ($ignoreTables as $table) {
                $event->ignoreTables[] = "{{%{$table}}}";
            }
        };
        $db->on(Connection::EVENT_BEFORE_CREATE_BACKUP, $handler);
        try {
            $db->backupTo($file);
        } finally {
            $db->off(Connection::EVENT_BEFORE_CREATE_BACKUP, $handler);
        }

        if (!is_file($file)) {
            throw new BackupFailedException('Craft DB backup did not produce a file.');
        }
    }

    /**
     * Creates an empty database next to the site database, on the same server
     * and with the same credentials.
     */
    public function createScratch(string $runId): ScratchDatabase
    {
        $this->assertCommandsTargetConnection();

        try {
            return CraftSchema::isCraft6() ? $this->createLaravelScratch($runId) : $this->createYiiScratch($runId);
        } catch (BackupFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BackupFailedException(
                'Could not create the scratch database for the sanitized copy. The database user needs the CREATE DATABASE and DROP DATABASE privileges. '
                . 'Error: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function createYiiScratch(string $runId): ScratchDatabase
    {
        $db = Craft::$app->getDb();
        $source = Db::parseDsn($db->dsn)['dbname'] ?? null;
        if (!$source) {
            throw new BackupFailedException('Could not read the database name from the DB connection DSN.');
        }

        $name = self::scratchName($source, $runId);
        $db->createCommand('CREATE DATABASE ' . $db->quoteTableName($name))->execute();

        // Cloning resets the PDO instance and schema, so the copy talks to the new database only.
        $scratch = clone $db;
        $scratch->dsn = (string) preg_replace('/dbname=[^;]*/', 'dbname=' . $name, $db->dsn);
        if ((Db::parseDsn($scratch->dsn)['dbname'] ?? null) !== $name) {
            throw new BackupFailedException('Could not point the scratch connection at the scratch database.');
        }

        return new YiiScratchDatabase($scratch, $db, $name);
    }

    private function createLaravelScratch(string $runId): ScratchDatabase
    {
        $source = \Illuminate\Support\Facades\DB::connection();
        $name = self::scratchName($source->getDatabaseName(), $runId);
        $connectionName = 'craft_backup_scratch_' . $runId;

        $source->statement('CREATE DATABASE ' . $source->getQueryGrammar()->wrap($name));
        config(["database.connections.{$connectionName}" => [...$source->getConfig(), 'database' => $name]]);

        return new LaravelScratchDatabase($connectionName, $source, $name);
    }

    /**
     * A custom backupCommand or restoreCommand string with a hardcoded
     * database name would restore into the live database and dump it
     * unsanitized. Only commands that use the {database} token follow the
     * scratch connection.
     */
    private function assertCommandsTargetConnection(): void
    {
        $general = CraftSchema::isCraft6()
            ? app(\CraftCms\Cms\Config\GeneralConfig::class)
            : Craft::$app->getConfig()->getGeneral();

        foreach (['backupCommand' => $general->backupCommand, 'restoreCommand' => $general->restoreCommand] as $setting => $command) {
            if (is_string($command) && !str_contains($command, '{database}')) {
                throw new BackupFailedException("The {$setting} general config setting doesn't use the {database} token, so it can't be pointed at the scratch database. Use {database} in it, or a closure, to create sanitized copies.");
            }
        }
    }

    public static function scratchName(string $source, string $runId): string
    {
        // MySQL caps database names at 64 characters.
        return substr($source, 0, 40) . '_sanitize_' . $runId;
    }

    /**
     * @return list<string>
     */
    private static function craft6DefaultIgnoredTables(): array
    {
        return ['assetindexdata', 'cache', 'imagetransformindex', 'resourcepaths', 'sessions'];
    }
}
