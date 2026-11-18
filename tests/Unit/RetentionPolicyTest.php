<?php

use webhubworks\backup\services\RetentionPolicy;
use webhubworks\backup\tests\Unit\FakeTarget;

function bundledRetention(): array
{
    $bundled = dirname((new ReflectionClass(RetentionPolicy::class))->getFileName(), 2) . '/config.php';
    $retention = (require $bundled)['*']['retention'];
    unset($retention['delete_oldest_backups_when_using_more_megabytes_than']);
    return $retention;
}

function backupsEvery(int $hours, int $days): FakeTarget
{
    $target = new FakeTarget();
    $now = new DateTimeImmutable();
    for ($h = 0; $h < $days * 24; $h += $hours) {
        $date = $now->modify("-{$h} hours");
        $target->add(sprintf('site-%s-%s.tar.gz', $date->format('Y-m-d_H-i-s'), substr(md5((string) $h), 0, 8)));
    }
    return $target;
}

function oldestKeptAgeInDays(FakeTarget $target): int
{
    $oldest = min(array_map(
        fn(string $path) => DateTimeImmutable::createFromFormat('Y-m-d_H-i-s', substr($path, 5, 19)),
        array_keys($target->files),
    ));
    return (new DateTimeImmutable())->diff($oldest)->days;
}

it('reaches back about 2.5 years with the bundled defaults', function(int $hours) {
    $target = backupsEvery($hours, 3 * 365);

    (new RetentionPolicy())->apply($target, bundledRetention());

    // The yearly range starts 7 + 16 days, 8 weeks, 4 months, and 2 years ago. It keeps the newest
    // backup per calendar year, so the oldest survivor is the last backup of the year the range starts in.
    $yearlyFrom = (new DateTimeImmutable())->modify('-23 days')->modify('-8 weeks')->modify('-4 months')->modify('-2 years');
    $lastOfThatYear = new DateTimeImmutable($yearlyFrom->format('Y') . '-12-31 23:59:59');
    expect(oldestKeptAgeInDays($target))->toBeIn([
        (new DateTimeImmutable())->diff($lastOfThatYear)->days,
        (new DateTimeImmutable())->diff($lastOfThatYear)->days + 1,
    ]);
})->with([
    'one run per day' => 24,
    'four runs per day' => 6,
]);

it('keeps one backup per day in the daily range', function() {
    $target = backupsEvery(6, 30);

    (new RetentionPolicy())->apply($target, bundledRetention());

    $days = array_map(fn(string $path) => substr($path, 5, 10), array_keys($target->files));
    // Days fully inside the daily range; the days at its edges can share a calendar day with a neighbor range.
    $dailyRangeDays = array_filter($days, fn(string $day) => $day < (new DateTimeImmutable('-8 days'))->format('Y-m-d')
        && $day > (new DateTimeImmutable('-22 days'))->format('Y-m-d'));
    expect($dailyRangeDays)->toHaveCount(13)->and(array_unique($dailyRangeDays))->toHaveCount(13);
});

it('never deletes the newest backup', function() {
    $target = new FakeTarget();
    $target->add('site-2001-01-01_00-00-00-aaaaaaaa.tar.gz');
    $target->add('site-2000-01-01_00-00-00-bbbbbbbb.tar.gz');

    (new RetentionPolicy())->apply($target, bundledRetention());

    expect(array_keys($target->files))->toBe(['site-2001-01-01_00-00-00-aaaaaaaa.tar.gz']);
});
