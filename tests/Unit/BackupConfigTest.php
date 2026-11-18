<?php

use webhubworks\backup\models\BackupConfig;

function configWithKey(string $key): BackupConfig
{
    return BackupConfig::fromArray([
        'compression' => ['format' => 'tar.gz'],
        'encryption' => ['enabled' => true, 'key' => $key],
        'targets' => ['local' => ['driver' => 'local']],
    ]);
}

it('accepts a base64-encoded 32-byte key', function() {
    expect(configWithKey(base64_encode(random_bytes(32)))->encryptionEnabled)->toBeTrue();
});

it('rejects a malformed key before any backup work starts', function(string $key) {
    expect(fn() => configWithKey($key))->toThrow(InvalidArgumentException::class, 'encryption.key must be base64-encoded 32 bytes.');
})->with([
    'too short' => base64_encode(random_bytes(16)),
    'not base64' => 'not a base64 key!',
]);
