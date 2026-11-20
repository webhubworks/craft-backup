<?php

namespace webhubworks\backup\services\sanitize;

use craft\db\Connection;
use craft\db\Query;
use webhubworks\backup\exceptions\BackupFailedException;

/**
 * Scratch database on Craft 4 and 5.
 */
class YiiScratchDatabase implements ScratchDatabase
{
    private bool $dropped = false;

    public function __construct(
        private readonly Connection $db,
        private readonly Connection $source,
        private readonly string $name,
    ) {
    }

    public function restore(string $file): void
    {
        // The restore overwrites every table, so make sure it can only ever hit the scratch database.
        $current = $this->db->getIsPgsql()
            ? $this->db->createCommand('SELECT current_database()')->queryScalar()
            : $this->db->createCommand('SELECT DATABASE()')->queryScalar();
        if ($current !== $this->name) {
            throw new BackupFailedException("Refusing to restore into '{$current}': expected the scratch database '{$this->name}'.");
        }

        $this->db->restore($file);
        $this->db->getSchema()->refresh();
    }

    public function dump(string $file): void
    {
        $this->db->backupTo($file);
    }

    public function tables(): array
    {
        $prefix = (string) $this->db->tablePrefix;
        $tables = [];
        foreach ($this->db->getSchema()->getTableNames('', true) as $name) {
            if ($prefix === '' || str_starts_with($name, $prefix)) {
                $tables[] = substr($name, strlen($prefix));
            }
        }
        return $tables;
    }

    public function columns(string $table): ?array
    {
        return $this->db->getTableSchema("{{%{$table}}}", true)?->getColumnNames();
    }

    public function deleteAll(string $table): void
    {
        $this->db->createCommand()->delete("{{%{$table}}}")->execute();
    }

    public function eachBatch(string $table, array $columns, callable $callback): void
    {
        $lastId = 0;
        do {
            $rows = (new Query())
                ->select(array_values(array_unique(['id', ...$columns])))
                ->from("{{%{$table}}}")
                ->where(['>', 'id', $lastId])
                ->orderBy(['id' => SORT_ASC])
                ->limit(500)
                ->all($this->db);

            if ($rows !== []) {
                $callback($rows);
                $lastId = (int) end($rows)['id'];
            }
        } while (count($rows) === 500);
    }

    public function updateById(string $table, int $id, array $values): void
    {
        $this->db->createCommand()->update("{{%{$table}}}", $values, ['id' => $id], [], false)->execute();
    }

    public function drop(): void
    {
        if ($this->dropped) {
            return;
        }
        $this->db->close();
        $this->source->createCommand('DROP DATABASE IF EXISTS ' . $this->source->quoteTableName($this->name))->execute();
        $this->dropped = true;
    }
}
