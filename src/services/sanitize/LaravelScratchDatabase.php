<?php

namespace webhubworks\backup\services\sanitize;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use webhubworks\backup\exceptions\BackupFailedException;

/**
 * Scratch database on Craft 6.
 *
 * shortcut: written against the Craft 6.0.0-beta.1 source and untested; verify when the plugin is ported to Craft 6.
 */
class LaravelScratchDatabase implements ScratchDatabase
{
    private bool $dropped = false;

    public function __construct(
        private readonly string $connectionName,
        private readonly Connection $source,
        private readonly string $name,
    ) {
    }

    private function db(): Connection
    {
        return DB::connection($this->connectionName);
    }

    public function restore(string $file): void
    {
        // The restore overwrites every table, so make sure it can only ever hit the scratch database.
        $current = $this->db()->getDriverName() === 'pgsql'
            ? $this->db()->scalar('SELECT current_database()')
            : $this->db()->scalar('SELECT DATABASE()');
        if ($current !== $this->name) {
            throw new BackupFailedException("Refusing to restore into '{$current}': expected the scratch database '{$this->name}'.");
        }

        app(\CraftCms\Cms\Database\Backups::class)->restore($file, $this->db());
    }

    public function dump(string $file): void
    {
        app(\CraftCms\Cms\Database\Backups::class)->backupTo($file, $this->db(), ignoreTables: []);
    }

    public function tables(): array
    {
        $prefix = $this->db()->getTablePrefix();
        $tables = [];
        foreach ($this->db()->getSchemaBuilder()->getTables() as $table) {
            if ($prefix === '' || str_starts_with($table['name'], $prefix)) {
                $tables[] = substr($table['name'], strlen($prefix));
            }
        }
        return $tables;
    }

    public function columns(string $table): ?array
    {
        $schema = $this->db()->getSchemaBuilder();
        return $schema->hasTable($table) ? $schema->getColumnListing($table) : null;
    }

    public function deleteAll(string $table): void
    {
        $this->db()->table($table)->delete();
    }

    public function eachBatch(string $table, array $columns, callable $callback): void
    {
        $this->db()->table($table)
            ->select(array_values(array_unique(['id', ...$columns])))
            ->chunkById(500, fn($rows) => $callback($rows->map(fn($row) => (array) $row)->all()));
    }

    public function updateById(string $table, int $id, array $values): void
    {
        $this->db()->table($table)->where('id', $id)->update($values);
    }

    public function drop(): void
    {
        if ($this->dropped) {
            return;
        }
        DB::purge($this->connectionName);
        $this->source->statement('DROP DATABASE IF EXISTS ' . $this->source->getQueryGrammar()->wrap($this->name));
        $this->dropped = true;
    }
}
