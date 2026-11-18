<?php

use craft\helpers\FileHelper;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\services\targets\LocalTarget;

beforeEach(function() {
    $this->root = sys_get_temp_dir() . '/craft-backup-test-' . bin2hex(random_bytes(4));
    $this->target = new LocalTarget(['root' => $this->root], 'site');
    $this->config = (new ReflectionClass(BackupConfig::class))->newInstanceWithoutConstructor();
});

afterEach(function() {
    FileHelper::removeDirectory($this->root);
});

it('lists only its own archives', function() {
    foreach ([
        'site-2026-05-07_14-30-15-a1b2c3d4.tar.gz',
        'other-2026-05-07_14-30-15-a1b2c3d4.tar.gz',
        'site-2026-05-07_14-30-15-a1b2c3d4.tar.gz.part',
        'mysite--2026-05-07-143015--v5.4.7.sql.zip',
    ] as $name) {
        touch("{$this->root}/{$name}");
    }

    expect(array_map(fn(array $entry) => basename($entry['path']), $this->target->list()))
        ->toBe(['site-2026-05-07_14-30-15-a1b2c3d4.tar.gz']);
});

it('uploads under the final name and leaves no partial file', function() {
    $source = tempnam(sys_get_temp_dir(), 'cb');
    file_put_contents($source, 'archive');

    $this->target->upload($source, 'site-2026-05-07_14-30-15-a1b2c3d4.tar.gz', $this->config);
    unlink($source);

    expect(scandir($this->root))->toBe(['.', '..', 'site-2026-05-07_14-30-15-a1b2c3d4.tar.gz']);
});

it('leaves nothing behind when the upload fails', function() {
    expect(fn() => $this->target->upload('/does/not/exist', 'site-2026-05-07_14-30-15-a1b2c3d4.tar.gz', $this->config))
        ->toThrow(Exception::class);

    expect(scandir($this->root))->toBe(['.', '..']);
});
