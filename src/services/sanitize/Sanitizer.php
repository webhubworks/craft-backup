<?php

namespace webhubworks\backup\services\sanitize;

use Faker\Factory;
use Faker\Generator;
use webhubworks\backup\services\BackupLogger;

/**
 * Rewrites a scratch database in place: empties tables, replaces column
 * values, and replaces custom field values in `elements_sites.content`.
 */
class Sanitizer
{
    private readonly Generator $faker;

    public function __construct(
        private readonly BackupLogger $logger,
        private readonly CraftSchema $schema,
        string $fakerLocale,
    ) {
        $this->faker = Factory::create($fakerLocale);
    }

    public function sanitize(ScratchDatabase $db, SanitizeRules $rules): void
    {
        $existing = $db->tables();
        $emptied = [];
        foreach ($rules->emptyTables as $pattern) {
            $matches = array_filter($existing, fn(string $table) => fnmatch($pattern, $table));
            if ($matches === []) {
                $this->warnUnlessOptional($rules, $pattern, "No table matches '{$pattern}' in sanitize.empty_tables; skipped.");
                continue;
            }
            foreach ($matches as $table) {
                $db->deleteAll($table);
                $emptied[] = $table;
            }
        }
        $this->logger->info('Emptied tables', ['tables' => $emptied]);

        foreach ($rules->tables as $table => $columnRules) {
            $this->sanitizeTable($db, $rules, $table, $columnRules);
        }

        $this->sanitizeFields($db, $rules);
    }

    /**
     * @param array<string, mixed> $columnRules
     */
    private function sanitizeTable(ScratchDatabase $db, SanitizeRules $rules, string $table, array $columnRules): void
    {
        $existing = $db->columns($table);
        if ($existing === null) {
            $this->warnUnlessOptional($rules, $table, "Table '{$table}' in sanitize.tables doesn't exist; skipped.");
            return;
        }

        $missing = array_diff(array_keys($columnRules), $existing, $rules->optionalColumns[$table] ?? []);
        if ($missing !== []) {
            $this->warnUnlessOptional($rules, $table, "Columns in sanitize.tables.{$table} don't exist and were skipped: " . implode(', ', $missing));
        }
        $columnRules = array_intersect_key($columnRules, array_flip($existing));
        if ($columnRules === []) {
            return;
        }

        $isUsers = $table === 'users';
        $read = $isUsers ? $existing : array_keys($columnRules);
        $changed = 0;
        $kept = 0;

        $db->eachBatch($table, $read, function(array $rows) use ($db, $table, $columnRules, $isUsers, $rules, &$changed, &$kept) {
            foreach ($rows as $row) {
                if ($isUsers && $rules->keepUsers !== null && ($rules->keepUsers)($row)) {
                    $kept++;
                    continue;
                }
                $values = SanitizeRules::applyToRow($columnRules, $row, $this->faker);
                if ($values !== []) {
                    $db->updateById($table, (int) $row['id'], $values);
                    $changed++;
                }
            }
        });

        $this->logger->info("Sanitized {$table}", ['rows' => $changed, 'kept' => $kept]);
    }

    private function sanitizeFields(ScratchDatabase $db, SanitizeRules $rules): void
    {
        $resolved = $this->schema->fieldRulesByUid($rules->fields);
        if ($resolved['unknown'] !== []) {
            $this->logger->warning('Field handles in sanitize.fields aren\'t in any field layout and were skipped: ' . implode(', ', $resolved['unknown']));
        }
        if ($resolved['rules'] === []) {
            return;
        }

        $changed = 0;
        $db->eachBatch('elements_sites', ['content'], function(array $rows) use ($db, $resolved, &$changed) {
            foreach ($rows as $row) {
                $content = is_string($row['content']) ? json_decode($row['content'], true) : null;
                if (!is_array($content)) {
                    continue;
                }
                $new = SanitizeRules::applyToContent($content, $resolved['rules'], $this->faker);
                if ($new !== null) {
                    $db->updateById('elements_sites', (int) $row['id'], ['content' => json_encode($new)]);
                    $changed++;
                }
            }
        });

        $this->logger->info('Sanitized custom fields', ['rows' => $changed]);
    }

    private function warnUnlessOptional(SanitizeRules $rules, string $table, string $message): void
    {
        if (!in_array($table, $rules->optionalTables, true)) {
            $this->logger->warning($message);
        }
    }
}
