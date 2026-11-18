<?php

use webhubworks\backup\services\BackupGrouper;

it('recognizes archives written for its own name', function(string $filename) {
    expect(BackupGrouper::isArchiveOf($filename, 'craft-backup'))->toBeTrue();
})->with([
    'craft-backup-2026-05-07_14-30-15-a1b2c3d4.zip',
    'craft-backup-2026-05-07_14-30-15-a1b2c3d4.tar.gz',
    'craft-backup-2026-05-07_14-30-15-a1b2c3d4.tar.gz.enc',
    'craft-backup-2026-05-07_14-30-15-a1b2c3d4-db.zip',
    'craft-backup-2026-05-07_14-30-15-a1b2c3d4-files.tar.gz.enc',
]);

it('ignores files that are not its own archives', function(string $filename) {
    expect(BackupGrouper::isArchiveOf($filename, 'craft-backup'))->toBeFalse();
})->with([
    'another site with a longer name' => 'craft-backup-shop-2026-05-07_14-30-15-a1b2c3d4.zip',
    'another site' => 'shop-2026-05-07_14-30-15-a1b2c3d4.zip',
    'Craft db/backup --zip' => 'mysite--2026-05-07-143015--v5.4.7.sql.zip',
    'unfinished upload' => 'craft-backup-2026-05-07_14-30-15-a1b2c3d4.zip.part',
]);
