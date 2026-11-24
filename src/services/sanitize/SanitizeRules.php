<?php

namespace webhubworks\backup\services\sanitize;

use Closure;
use Faker\Generator;
use InvalidArgumentException;

/**
 * The merged rule set for a sanitized copy: the built-in Craft preset first,
 * the project's `sanitize` config on top.
 *
 * A rule is either a Faker formatter name ('safeEmail') or a closure
 * `fn (Generator $faker, mixed $value, array $row): mixed`. Empty values
 * (null, '') are never passed to a rule and stay as they are.
 */
final class SanitizeRules
{
    /**
     * @param list<string> $emptyTables Unprefixed table names or glob patterns ('freeform_submissions_*') whose rows are deleted.
     * @param array<string, array<string, string|Closure>> $tables Unprefixed table name => column => rule.
     * @param array<string, string|Closure> $fields Custom field handle => rule.
     * @param list<string> $optionalTables Tables that only the preset names; skipped silently when missing.
     * @param array<string, list<string>> $optionalColumns Preset columns per table; skipped silently when missing.
     */
    public function __construct(
        public readonly array $emptyTables,
        public readonly array $tables,
        public readonly array $fields,
        public readonly ?Closure $keepUsers,
        public readonly array $optionalTables,
        public readonly array $optionalColumns = [],
    ) {
    }

    /**
     * @param array<string, mixed> $config The `sanitize` block of the plugin config.
     */
    public static function fromConfig(array $config, CraftSchema $schema): self
    {
        $password = array_key_exists('password', $config) ? $config['password'] : 'password';
        if ($password !== null && !is_string($password)) {
            throw new InvalidArgumentException('sanitize.password must be a string or null.');
        }
        $preset = self::craftPreset($schema, $password);

        $userEmptyTables = array_values((array) ($config['empty_tables'] ?? []));
        $userTables = (array) ($config['tables'] ?? []);
        $fields = (array) ($config['fields'] ?? []);

        foreach ($userTables as $table => $columns) {
            if (!is_string($table) || !is_array($columns)) {
                throw new InvalidArgumentException("sanitize.tables must map table names to column rules, e.g. ['commerce_orders' => ['email' => 'safeEmail']].");
            }
            foreach ($columns as $column => $rule) {
                self::assertRule($rule, "sanitize.tables.{$table}.{$column}");
            }
        }
        foreach ($fields as $handle => $rule) {
            self::assertRule($rule, "sanitize.fields.{$handle}");
        }

        $keepUsers = array_key_exists('keep_users', $config) ? $config['keep_users'] : fn(array $user) => (bool) ($user['admin'] ?? false);
        if ($keepUsers !== null && !$keepUsers instanceof Closure) {
            throw new InvalidArgumentException('sanitize.keep_users must be a closure or null.');
        }

        $tables = $preset['tables'];
        foreach ($userTables as $table => $columns) {
            $tables[$table] = [...($tables[$table] ?? []), ...$columns];
        }

        $presetNames = [...$preset['empty_tables'], ...array_keys($preset['tables'])];
        $userNames = [...$userEmptyTables, ...array_keys($userTables)];

        $optionalColumns = [];
        foreach ($preset['tables'] as $table => $columns) {
            $optionalColumns[$table] = array_values(array_diff(array_keys($columns), array_keys($userTables[$table] ?? [])));
        }

        return new self(
            emptyTables: array_values(array_unique([...$preset['empty_tables'], ...$userEmptyTables])),
            tables: $tables,
            fields: $fields,
            keepUsers: $keepUsers,
            optionalTables: array_values(array_diff($presetNames, $userNames)),
            optionalColumns: $optionalColumns,
        );
    }

    /**
     * Built-in rules for Craft's own tables. Table names differ between Craft
     * versions, so the list names all of them and the sanitizer skips the
     * ones a site doesn't have.
     *
     * TODO: add a `presets` config key with presets for Formie, Freeform, and
     * Commerce. Their schemas differ between plugin versions, so each needs
     * checking against real installs before it ships.
     *
     * @return array{empty_tables: list<string>, tables: array<string, array<string, string|Closure>>}
     */
    public static function craftPreset(CraftSchema $schema, ?string $password): array
    {
        $fullName = fn(Generator $faker, $value, array $row) => trim(($row['firstName'] ?? '') . ' ' . ($row['lastName'] ?? '')) ?: $faker->name();

        $users = [
            'email' => fn(Generator $faker) => $faker->unique()->safeEmail(),
            'username' => fn(Generator $faker, $value, array $row) => $schema->useEmailAsUsername() ? $row['email'] : $faker->unique()->userName(),
            'firstName' => 'firstName',
            'lastName' => 'lastName',
            'fullName' => $fullName,
            'unverifiedEmail' => 'safeEmail',
            'lastLoginAttemptIp' => fn() => null,
            'rememberToken' => fn() => null,
        ];

        if ($password !== null) {
            $hash = null;
            $users['password'] = function() use (&$hash, $password, $schema) {
                return $hash ??= $schema->hashPassword($password);
            };
        }

        return [
            'empty_tables' => [
                'searchindex',
                'searchindexqueue',
                'searchindexqueue_fields',
                'sessions',
                'phpsessions',
                'cache',
                'queue',
                'jobs',
                'failed_jobs',
                'tokens',
                'routetokens',
                'craftidtokens',
                'password_reset_tokens',
                'authenticator',
                'webauthn',
                'recoverycodes',
                'sso_identities',
                'oauth_access_tokens',
                'oauth_auth_codes',
                'oauth_refresh_tokens',
                'elementactivity',
                'activityevents',
                'agent_conversation_messages',
                ...($schema->hasLegacyContentTables() ? ['content', 'matrixcontent_*'] : []),
            ],
            'tables' => [
                'users' => $users,
                'addresses' => [
                    'firstName' => 'firstName',
                    'lastName' => 'lastName',
                    'fullName' => $fullName,
                    'organization' => 'company',
                    'organizationTaxId' => fn() => null,
                    'addressLine1' => 'streetAddress',
                    'addressLine2' => fn() => null,
                    'addressLine3' => fn() => null,
                    'postalCode' => 'postcode',
                    'sortingCode' => fn() => null,
                    'latitude' => fn() => null,
                    'longitude' => fn() => null,
                ],
                // Keeps the public schema's token, so the public GraphQL API still works in the copy.
                'gqltokens' => [
                    'accessToken' => fn(Generator $faker, $value) => $value === '__PUBLIC__' ? $value : bin2hex(random_bytes(16)),
                ],
            ],
        ];
    }

    /**
     * What each built-in closure in craftPreset() does, for the Backups utility.
     * Formatter rules need no entry: their name says it. Keep both methods in step.
     *
     * @return array<string, array<string, string>> table => column => description
     */
    public static function presetDescriptions(CraftSchema $schema): array
    {
        $removed = 'Removed';
        $fullName = 'First and last name combined';

        return [
            'users' => [
                'email' => 'Unique fake email',
                'username' => $schema->useEmailAsUsername() ? 'Same as the new email' : 'Unique fake username',
                'fullName' => $fullName,
                'lastLoginAttemptIp' => $removed,
                'rememberToken' => $removed,
            ],
            'addresses' => [
                'fullName' => $fullName,
                'organizationTaxId' => $removed,
                'addressLine2' => $removed,
                'addressLine3' => $removed,
                'sortingCode' => $removed,
                'latitude' => $removed,
                'longitude' => $removed,
            ],
            'gqltokens' => [
                'accessToken' => 'Random token; the public schema token stays',
            ],
        ];
    }

    /**
     * Applies column rules to one row and returns only the changed columns.
     * Later rules see the values earlier rules produced, so a username rule
     * can copy the new email.
     *
     * @param array<string, string|Closure> $rules
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function applyToRow(array $rules, array $row, Generator $faker): array
    {
        $changes = [];
        foreach ($rules as $column => $rule) {
            if (!array_key_exists($column, $row) || $row[$column] === null || $row[$column] === '') {
                continue;
            }
            $row[$column] = $changes[$column] = self::applyRule($rule, $row[$column], $row, $faker);
        }
        return $changes;
    }

    /**
     * Applies field rules to an `elements_sites.content` array, keyed by
     * layout element UID. Returns null when nothing changed.
     *
     * @param array<string, mixed> $content
     * @param array<string, string|Closure> $rulesByUid
     * @return array<string, mixed>|null
     */
    public static function applyToContent(array $content, array $rulesByUid, Generator $faker): ?array
    {
        $changed = false;
        foreach ($rulesByUid as $uid => $rule) {
            $value = $content[$uid] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            // A formatter returns a string, which would break fields that store arrays (Table, Link, ...).
            if (is_string($rule) && !is_scalar($value)) {
                continue;
            }
            $content[$uid] = self::applyRule($rule, $value, $content, $faker);
            $changed = true;
        }
        return $changed ? $content : null;
    }

    private static function applyRule(string|Closure $rule, mixed $value, array $row, Generator $faker): mixed
    {
        return is_string($rule) ? $faker->format($rule) : $rule($faker, $value, $row);
    }

    private static function assertRule(mixed $rule, string $path): void
    {
        if (!is_string($rule) && !$rule instanceof Closure) {
            throw new InvalidArgumentException("{$path} must be a Faker formatter name or a closure.");
        }
    }
}
