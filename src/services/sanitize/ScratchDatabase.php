<?php

namespace webhubworks\backup\services\sanitize;

/**
 * A throwaway database the sanitizer restores the dump into and rewrites.
 * Table names are unprefixed; implementations add the site's table prefix.
 */
interface ScratchDatabase
{
    public function restore(string $file): void;

    public function dump(string $file): void;

    /**
     * @return list<string> Unprefixed names of all tables.
     */
    public function tables(): array;

    /**
     * @return list<string>|null Column names, or null when the table doesn't exist.
     */
    public function columns(string $table): ?array;

    public function deleteAll(string $table): void;

    /**
     * Calls $callback with batches of rows ordered by `id`, reading by keyset
     * so updates to the same table during the walk are safe.
     *
     * @param list<string> $columns
     * @param callable(list<array<string, mixed>>): void $callback
     */
    public function eachBatch(string $table, array $columns, callable $callback): void;

    /**
     * @param array<string, mixed> $values
     */
    public function updateById(string $table, int $id, array $values): void;

    /**
     * Drops the database. Safe to call more than once.
     */
    public function drop(): void;
}
