<?php

use webhubworks\backup\services\BackupGrouper;

it('reads the run ID from combined, split, and encrypted archive names', function(string $path, ?string $runId) {
    expect(BackupGrouper::parseRunId($path))->toBe($runId);
})->with([
    ['/backups/site-2026-05-07_14-30-15-a1b2c3d4.zip', 'a1b2c3d4'],
    ['site-2026-05-07_14-30-15-a1b2c3d4-db.tar.gz', 'a1b2c3d4'],
    ['site-2026-05-07_14-30-15-a1b2c3d4-files.tar.gz.enc', 'a1b2c3d4'],
    ['site-2026-05-07_14-30-15.zip', null],
    ['site-2026-05-07_14-30-15-A1B2C3D4.zip', null],
]);

it('treats the two halves of a split run as one backup', function() {
    $groups = BackupGrouper::group([
        ['path' => 'site-2026-05-07_14-30-15-a1b2c3d4-db.zip', 'size' => 100, 'modified' => 1000],
        ['path' => 'site-2026-05-07_14-30-15-a1b2c3d4-files.zip', 'size' => 900, 'modified' => 1060],
        ['path' => 'site-2026-05-08_14-30-15-e5f6a7b8.zip', 'size' => 50, 'modified' => 2000],
    ]);

    expect($groups)->toBe([
        ['paths' => ['site-2026-05-07_14-30-15-a1b2c3d4-db.zip', 'site-2026-05-07_14-30-15-a1b2c3d4-files.zip'], 'size' => 1000, 'modified' => 1060],
        ['paths' => ['site-2026-05-08_14-30-15-e5f6a7b8.zip'], 'size' => 50, 'modified' => 2000],
    ]);
});

it('keeps archives without a run ID as separate backups', function() {
    $groups = BackupGrouper::group([
        ['path' => 'legacy-one.zip', 'size' => 1, 'modified' => 1],
        ['path' => 'legacy-two.zip', 'size' => 1, 'modified' => 2],
    ]);

    expect($groups)->toHaveCount(2);
});
