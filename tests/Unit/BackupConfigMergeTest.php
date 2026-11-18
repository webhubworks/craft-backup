<?php

use webhubworks\backup\models\BackupConfig;

function mergeConfig(array $defaults, array $project): array
{
    return (new ReflectionMethod(BackupConfig::class, 'deepMerge'))->invoke(null, $defaults, $project);
}

function validConfig(array $overrides = []): BackupConfig
{
    return BackupConfig::fromArray(array_replace_recursive([
        'compression' => ['format' => 'zip'],
        'encryption' => ['enabled' => false],
        'targets' => ['local' => ['driver' => 'local']],
    ], $overrides));
}

it('merges maps key by key, so a project only overrides what it sets', function() {
    $merged = mergeConfig(
        ['retention' => ['keep_all_for_days' => 7, 'keep_daily_for_days' => 16]],
        ['retention' => ['keep_all_for_days' => 3]],
    );

    expect($merged['retention'])->toBe(['keep_all_for_days' => 3, 'keep_daily_for_days' => 16]);
});

it('replaces lists wholesale instead of appending to the defaults', function() {
    $merged = mergeConfig(
        ['source' => ['include' => ['@webroot/media', '@webroot/uploads']], 'monitor_backups' => [['target' => 'local']]],
        ['source' => ['include' => ['@webroot/files']], 'monitor_backups' => [['target' => 'offsite']]],
    );

    expect($merged['source']['include'])->toBe(['@webroot/files'])
        ->and($merged['monitor_backups'])->toBe([['target' => 'offsite']]);
});

it('lets a project switch a channel off with null', function() {
    $merged = mergeConfig(['notifications' => ['slack' => ['webhook_url' => 'https://hooks.test']]], ['notifications' => ['slack' => ['webhook_url' => null]]]);

    expect($merged['notifications']['slack']['webhook_url'])->toBeNull();
});

it('rejects combinations the archiver cannot produce', function(array $overrides, string $message) {
    expect(fn() => validConfig($overrides))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown format' => [['compression' => ['format' => 'rar']], "Unsupported compression.format 'rar'"],
    'password on tar.gz' => [['compression' => ['format' => 'tar.gz', 'password' => 'secret']], 'compression.password is only supported'],
    'zip with encryption' => [['encryption' => ['enabled' => true, 'key' => base64_encode(random_bytes(32))]], "compression.format = 'zip' uses its own"],
    'bad x_send_file' => [['download' => ['x_send_file' => 'X-Foo']], 'download.x_send_file must be'],
]);

it('requires at least one target', function() {
    expect(fn() => BackupConfig::fromArray(['compression' => ['format' => 'zip'], 'encryption' => ['enabled' => false]]))
        ->toThrow(InvalidArgumentException::class, 'At least one backup target');
});
