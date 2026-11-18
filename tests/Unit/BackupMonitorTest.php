<?php

use craft\helpers\FileHelper;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\services\BackupMonitor;

beforeEach(function() {
    $this->root = sys_get_temp_dir() . '/craft-backup-monitor-' . bin2hex(random_bytes(4));
    FileHelper::createDirectory($this->root);
});

afterEach(function() {
    FileHelper::removeDirectory($this->root);
});

function monitorWith(string $root, array $rules): array
{
    $config = BackupConfig::fromArray([
        'name' => 'site',
        'compression' => ['format' => 'zip'],
        'encryption' => ['enabled' => false],
        'targets' => ['local' => ['driver' => 'local', 'root' => $root]],
        'monitor_backups' => $rules,
    ]);
    return (new BackupMonitor())->check($config);
}

function archiveAged(string $root, int $ageSeconds, string $suffix = ''): void
{
    $time = time() - $ageSeconds;
    $path = sprintf('%s/site-%s-%s%s.zip', $root, date('Y-m-d_H-i-s', $time), substr(md5((string) $ageSeconds), 0, 8), $suffix);
    // Not empty: ZipArchive::open() deprecates opening empty files.
    file_put_contents($path, 'x');
    touch($path, $time);
}

function itemStatus(array $report, string $id): ?string
{
    foreach ($report['checks'][0]['items'] as $item) {
        if ($item['id'] === $id) {
            return $item['status'];
        }
    }
    return null;
}

it('fails without any rules', function() {
    expect(monitorWith($this->root, []))->toMatchArray(['status' => 'failure']);
});

it('fails a rule whose target is not defined', function() {
    $report = monitorWith($this->root, [['target' => 'offsite', 'min_number_of_backups' => 1]]);

    expect($report['status'])->toBe('failure')
        ->and(itemStatus($report, 'target_reachable'))->toBe('failure')
        ->and(itemStatus($report, 'min_backups'))->toBe('skipped');
});

it('counts the two halves of a split run as one backup', function() {
    archiveAged($this->root, 60, '-db');
    // Same run ID and timestamp as the db half.
    $db = glob("{$this->root}/*-db.zip")[0];
    file_put_contents(str_replace('-db.zip', '-files.zip', $db), 'x');

    expect(itemStatus(monitorWith($this->root, [['target' => 'local', 'min_number_of_backups' => 2]]), 'min_backups'))->toBe('failure')
        ->and(itemStatus(monitorWith($this->root, [['target' => 'local', 'min_number_of_backups' => 1]]), 'min_backups'))->toBe('ok');
});

it('allows the default 15 minute grace on top of the maximum age', function(int $ageSeconds, string $status) {
    archiveAged($this->root, $ageSeconds);

    expect(itemStatus(monitorWith($this->root, [['target' => 'local', 'youngest_backup_should_be_within_the_last' => '24h']]), 'youngest_age'))
        ->toBe($status);
})->with([
    'within the maximum' => [23 * 3600, 'ok'],
    'within the grace' => [24 * 3600 + 10 * 60, 'ok'],
    'past the grace' => [24 * 3600 + 20 * 60, 'failure'],
]);

it('uses a custom grace when one is set', function() {
    archiveAged($this->root, 24 * 3600 + 10 * 60);

    expect(itemStatus(monitorWith($this->root, [[
        'target' => 'local',
        'youngest_backup_should_be_within_the_last' => '24h',
        'youngest_backup_grace' => '5m',
    ]]), 'youngest_age'))->toBe('failure');
});

it('fails the age check when the target has no backups', function() {
    expect(itemStatus(monitorWith($this->root, [['target' => 'local', 'youngest_backup_should_be_within_the_last' => '1d']]), 'youngest_age'))
        ->toBe('failure');
});

it('reports an unreadable duration instead of crashing', function() {
    archiveAged($this->root, 60);

    expect(itemStatus(monitorWith($this->root, [['target' => 'local', 'youngest_backup_should_be_within_the_last' => 'soon']]), 'youngest_age'))
        ->toBe('failure');
});
