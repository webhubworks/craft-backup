<?php

namespace webhubworks\backup\services\sanitize;

use Craft;
use Throwable;
use webhubworks\backup\models\BackupConfig;

/**
 * Describes what a sanitized copy would do with the current config and the
 * live database schema, for the Backups utility. Reads the schema only.
 *
 * A rule shows its Faker formatter name, or for a closure a translated
 * description when it's built in. `custom` says whether the rule comes from the
 * project config or from the built-in preset.
 */
class SanitizePreview
{
    public function __construct(private readonly CraftSchema $schema = new CraftSchema())
    {
    }

    /**
     * @return array{
     *     targets: list<string>,
     *     users: array<string, array{formatter: ?string, description: ?string, custom: bool}>|null,
     *     password: ?string,
     *     keepUsers: 'admins'|'custom'|'none',
     *     tables: array<string, array<string, array{formatter: ?string, description: ?string, custom: bool}>>,
     *     fields: array<string, array{formatter: ?string, description: ?string, custom: bool}>,
     *     emptied: list<array{pattern: string, tables: list<string>}>,
     *     emptiedCount: int,
     *     warnings: list<string>,
     * }
     */
    public function describe(BackupConfig $config): array
    {
        $settings = $config->sanitize;
        $rules = SanitizeRules::fromConfig($settings, $this->schema);
        $projectTables = (array) ($settings['tables'] ?? []);
        $existing = $this->tableNames();
        $descriptions = SanitizeRules::presetDescriptions($this->schema);
        $warnings = [];

        $targets = array_keys($config->targets);
        $only = $settings['targets'] ?? null;
        if (is_array($only)) {
            $unknown = array_diff($only, $targets);
            if ($unknown !== []) {
                $warnings[] = Craft::t('backup', 'Targets in sanitize.targets aren’t configured: {names}', ['names' => implode(', ', $unknown)]);
            }
            $targets = array_values(array_intersect($targets, $only));
        }

        $emptied = [];
        foreach ($rules->emptyTables as $pattern) {
            $matches = array_values(array_filter($existing, fn(string $table) => fnmatch($pattern, $table)));
            if ($matches !== []) {
                $emptied[] = ['pattern' => $pattern, 'tables' => $matches];
            } elseif (!in_array($pattern, $rules->optionalTables, true)) {
                $warnings[] = Craft::t('backup', 'No table matches {pattern} in sanitize.empty_tables.', ['pattern' => $pattern]);
            }
        }

        $tables = [];
        foreach ($rules->tables as $table => $columnRules) {
            $columns = in_array($table, $existing, true) ? $this->columnNames($table) : [];
            if ($columns === []) {
                if (!in_array($table, $rules->optionalTables, true)) {
                    $warnings[] = Craft::t('backup', 'Table {table} in sanitize.tables doesn’t exist.', ['table' => $table]);
                }
                continue;
            }
            $missing = array_diff(array_keys($columnRules), $columns, $rules->optionalColumns[$table] ?? []);
            if ($missing !== []) {
                $warnings[] = Craft::t('backup', 'Columns in sanitize.tables.{table} don’t exist: {columns}', ['table' => $table, 'columns' => implode(', ', $missing)]);
            }
            foreach (array_intersect_key($columnRules, array_flip($columns)) as $column => $rule) {
                $custom = isset($projectTables[$table][$column]);
                $description = $custom ? null : ($descriptions[$table][$column] ?? null);
                $tables[$table][$column] = [
                    'formatter' => is_string($rule) ? $rule : null,
                    'description' => $description !== null ? Craft::t('backup', $description) : null,
                    'custom' => $custom,
                ];
            }
        }

        // The users table gets its own section; the password shows as its plain value instead.
        $users = $tables['users'] ?? null;
        unset($tables['users'], $users['password']);

        $fields = [];
        $projectFields = (array) ($settings['fields'] ?? []);
        if ($projectFields !== []) {
            try {
                $unknown = $this->schema->fieldRulesByUid($projectFields)['unknown'];
            } catch (Throwable $e) {
                $warnings[] = $e->getMessage();
                $unknown = array_keys($projectFields);
            }
            if ($unknown !== []) {
                $warnings[] = Craft::t('backup', 'Field handles in sanitize.fields aren’t in any field layout: {handles}', ['handles' => implode(', ', $unknown)]);
            }
            foreach (array_diff_key($projectFields, array_flip($unknown)) as $handle => $rule) {
                $fields[$handle] = ['formatter' => is_string($rule) ? $rule : null, 'description' => null, 'custom' => true];
            }
        }

        return [
            'targets' => $targets,
            'users' => $users,
            'password' => array_key_exists('password', $settings) ? $settings['password'] : 'password',
            'keepUsers' => match (true) {
                !array_key_exists('keep_users', $settings) => 'admins',
                $settings['keep_users'] === null => 'none',
                default => 'custom',
            },
            'tables' => $tables,
            'fields' => $fields,
            'emptied' => $emptied,
            'emptiedCount' => array_sum(array_map(fn(array $entry) => count($entry['tables']), $emptied)),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<string> Unprefixed table names of the site database.
     */
    private function tableNames(): array
    {
        $db = Craft::$app->getDb();
        $prefix = (string) $db->tablePrefix;
        $tables = [];
        foreach ($db->getSchema()->getTableNames() as $name) {
            if ($prefix === '' || str_starts_with($name, $prefix)) {
                $tables[] = substr($name, strlen($prefix));
            }
        }
        return $tables;
    }

    /**
     * @return list<string>
     */
    private function columnNames(string $table): array
    {
        return Craft::$app->getDb()->getTableSchema("{{%{$table}}}")?->getColumnNames() ?? [];
    }
}
