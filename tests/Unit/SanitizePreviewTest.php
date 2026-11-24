<?php

use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\services\sanitize\SanitizePreview;

function previewFor(array $sanitize): array
{
    return (new SanitizePreview())->describe(BackupConfig::fromArray([
        'compression' => ['format' => 'zip'],
        'encryption' => ['enabled' => false],
        'targets' => ['local' => ['driver' => 'local'], 'offsite' => ['driver' => 'local']],
        'sanitize' => $sanitize,
    ]));
}

it('describes the built-in rules against the live schema without warnings', function() {
    $preview = previewFor([]);

    expect($preview['targets'])->toBe(['local', 'offsite'])
        ->and($preview['password'])->toBe('password')
        ->and($preview['keepUsers'])->toBe('admins')
        ->and($preview['users'])->toHaveKey('email')
        ->and($preview['users'])->not->toHaveKey('password')
        ->and($preview['users']['firstName'])->toBe(['formatter' => 'firstName', 'description' => null, 'custom' => false])
        ->and($preview['users']['lastLoginAttemptIp'])->toBe(['formatter' => null, 'description' => 'Removed', 'custom' => false])
        ->and($preview['tables'])->toHaveKey('addresses')
        ->and(array_column($preview['emptied'], 'pattern'))->toContain('searchindex')
        ->and($preview['emptiedCount'])->toBeGreaterThan(0)
        ->and($preview['warnings'])->toBe([]);
});

it('marks project rules as custom and closures without a formatter', function() {
    $preview = previewFor([
        'tables' => ['users' => ['photoId' => fn() => null]],
        'keep_users' => null,
        'password' => null,
    ]);

    expect($preview['users']['photoId'])->toBe(['formatter' => null, 'description' => null, 'custom' => true])
        ->and($preview['keepUsers'])->toBe('none')
        ->and($preview['password'])->toBeNull();
});

it('warns about targets, tables, columns, patterns, and fields that don’t exist', function() {
    $preview = previewFor([
        'targets' => ['local', 'nope'],
        'tables' => ['missing_table' => ['email' => 'safeEmail'], 'addresses' => ['missingColumn' => 'word']],
        'empty_tables' => ['missing_*'],
        'fields' => ['missingField' => 'word'],
    ]);

    expect($preview['targets'])->toBe(['local'])
        ->and($preview['tables'])->not->toHaveKey('missing_table')
        ->and($preview['fields'])->toBe([])
        ->and($preview['warnings'])->toBe([
            'Targets in sanitize.targets aren’t configured: nope',
            'No table matches missing_* in sanitize.empty_tables.',
            'Columns in sanitize.tables.addresses don’t exist: missingColumn',
            'Table missing_table in sanitize.tables doesn’t exist.',
            'Field handles in sanitize.fields aren’t in any field layout: missingField',
        ]);
});

it('counts every table a pattern matches', function() {
    $preview = previewFor(['empty_tables' => ['search*']]);

    $entry = collect($preview['emptied'])->firstWhere('pattern', 'search*');
    expect($entry['tables'])->toContain('searchindex');
});
