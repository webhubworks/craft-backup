<?php

use craft\helpers\FileHelper;
use webhubworks\backup\events\RegisterTargetDriversEvent;
use webhubworks\backup\exceptions\BackupFailedException;
use webhubworks\backup\models\BackupConfig;
use webhubworks\backup\services\BackupMonitor;
use webhubworks\backup\services\BackupRunner;
use webhubworks\backup\tests\Unit\FakeTarget;
use yii\base\Event;

beforeEach(function() {
    $this->source = sys_get_temp_dir() . '/craft-backup-source-' . bin2hex(random_bytes(4));
    FileHelper::createDirectory($this->source);
    file_put_contents($this->source . '/hello.txt', 'hello');

    FakeTarget::$built = [];
    Event::on(BackupRunner::class, BackupRunner::EVENT_REGISTER_TARGET_DRIVERS, function(RegisterTargetDriversEvent $event) {
        $event->drivers['fake'] = FakeTarget::class;
    });

    // Records every runner event as [name, event] on an instance, so nothing leaks between tests.
    $this->runner = new BackupRunner();
    $this->events = new ArrayObject();
    foreach ((new ReflectionClass(BackupRunner::class))->getConstants() as $constant => $name) {
        if (str_starts_with($constant, 'EVENT_')) {
            $this->runner->on($name, fn(Event $event) => $this->events[] = [$name, $event]);
        }
    }
});

afterEach(function() {
    Event::off(BackupRunner::class, BackupRunner::EVENT_REGISTER_TARGET_DRIVERS);
    FileHelper::removeDirectory($this->source);
});

function runnerConfig(string $source, array $overrides = []): BackupConfig
{
    return BackupConfig::fromArray(array_merge([
        'name' => 'site',
        'source' => ['databases' => ['db'], 'include' => [$source], 'exclude' => []],
        'compression' => ['format' => 'zip'],
        'encryption' => ['enabled' => false],
        'targets' => [
            'first' => ['driver' => 'fake', 'id' => 'first'],
            'second' => ['driver' => 'fake', 'id' => 'second'],
        ],
    ], $overrides));
}

function eventNames(ArrayObject $events): array
{
    return array_column($events->getArrayCopy(), 0);
}

function eventsNamed(ArrayObject $events, string $name): array
{
    return array_values(array_map(
        fn(array $entry) => $entry[1],
        array_filter($events->getArrayCopy(), fn(array $entry) => $entry[0] === $name),
    ));
}

it('fires the run events in order with their payloads', function() {
    $config = runnerConfig($this->source);
    $result = $this->runner->run($config, ['only_files' => true]);

    expect($result->isSuccessful())->toBeTrue()
        ->and(eventNames($this->events))->toBe([
            BackupRunner::EVENT_BEFORE_RUN,
            BackupRunner::EVENT_ARCHIVE_CREATED,
            BackupRunner::EVENT_AFTER_CLEANUP,
            BackupRunner::EVENT_AFTER_CLEANUP,
            BackupRunner::EVENT_AFTER_RUN,
        ]);

    [$beforeRun] = eventsNamed($this->events, BackupRunner::EVENT_BEFORE_RUN);
    expect($beforeRun->config)->toBe($config)
        ->and($beforeRun->runId)->toBe($result->runId)
        ->and($beforeRun->flags)->toBe(['only_files' => true])
        ->and($beforeRun->dryRun)->toBeFalse();

    [$archive] = eventsNamed($this->events, BackupRunner::EVENT_ARCHIVE_CREATED);
    expect(basename($archive->path))->toBe(array_key_first($result->archiveSizes))
        ->and($archive->kind)->toBeNull()
        ->and($archive->bytes)->toBe($result->archiveBytes)
        ->and($archive->encrypted)->toBeFalse();

    $cleanups = eventsNamed($this->events, BackupRunner::EVENT_AFTER_CLEANUP);
    expect(array_column($cleanups, 'target'))->toBe(['first', 'second'])
        ->and($cleanups[0]->runId)->toBe($result->runId)
        ->and($cleanups[0]->deleted)->toBe(0);

    [$afterRun] = eventsNamed($this->events, BackupRunner::EVENT_AFTER_RUN);
    expect($afterRun->result)->toBe($result)
        ->and(FakeTarget::$built['first']->files)->toHaveCount(1)
        ->and(FakeTarget::$built['second']->files)->toHaveCount(1);
});

it('skips the whole run when a before-run handler cancels it', function() {
    $this->runner->on(BackupRunner::EVENT_BEFORE_RUN, fn($event) => $event->isValid = false);

    $result = $this->runner->run(runnerConfig($this->source));

    expect($result->skipped)->toBeTrue()
        ->and(eventNames($this->events))->toBe([BackupRunner::EVENT_BEFORE_RUN])
        ->and(FakeTarget::$built)->toBe([]);
});

it('skips only the database dump a before-dump handler cancels', function() {
    $this->runner->on(BackupRunner::EVENT_BEFORE_DB_DUMP, fn($event) => $event->isValid = false);

    $result = $this->runner->run(runnerConfig($this->source));

    [$dump] = eventsNamed($this->events, BackupRunner::EVENT_BEFORE_DB_DUMP);
    expect($result->isSuccessful())->toBeTrue()
        ->and($dump->connectionId)->toBe('db')
        ->and(basename($dump->path))->toBe('db-db.sql')
        ->and(eventsNamed($this->events, BackupRunner::EVENT_ARCHIVE_CREATED))->toHaveCount(1);
});

it('builds no database archive in a split run when every dump is skipped', function() {
    $this->runner->on(BackupRunner::EVENT_BEFORE_DB_DUMP, fn($event) => $event->isValid = false);

    $result = $this->runner->run(runnerConfig($this->source, [
        'source' => ['databases' => ['db'], 'include' => [$this->source], 'split_db_and_files' => true],
    ]));

    expect($result->isSuccessful())->toBeTrue()
        ->and(array_column(eventsNamed($this->events, BackupRunner::EVENT_ARCHIVE_CREATED), 'kind'))->toBe(['files']);
});

it('keeps uploading to the other targets when one target config is broken', function() {
    $result = $this->runner->run(runnerConfig($this->source, ['targets' => [
        'broken' => ['driver' => 'nope'],
        'second' => ['driver' => 'fake', 'id' => 'second'],
    ]]), ['only_files' => true]);

    expect($result->targetStatuses)->toBe([
        'broken' => "failed: Unknown target driver 'nope'.",
        'second' => 'ok',
    ])
        ->and(FakeTarget::$built['second']->files)->toHaveCount(1)
        ->and(array_column(eventsNamed($this->events, BackupRunner::EVENT_AFTER_CLEANUP), 'target'))->toBe(['second'])
        ->and(array_slice(eventNames($this->events), -2))->toBe([BackupRunner::EVENT_AFTER_RUN, BackupRunner::EVENT_RUN_FAILED]);
});

it('runs no cleanup on a target whose upload failed', function() {
    $this->runner->run(runnerConfig($this->source, ['targets' => [
        'first' => ['driver' => 'fake', 'id' => 'first', 'fail' => true],
        'second' => ['driver' => 'fake', 'id' => 'second'],
    ]]), ['only_files' => true]);

    expect(array_column(eventsNamed($this->events, BackupRunner::EVENT_AFTER_CLEANUP), 'target'))->toBe(['second'])
        ->and(eventsNamed($this->events, BackupRunner::EVENT_RUN_FAILED))->toHaveCount(1);
});

it('fires low disk space after the run with every low target', function() {
    $disk = ['total' => 100 * 1024 * 1024, 'free' => 1024 * 1024];
    $this->runner->run(runnerConfig($this->source, [
        'targets' => ['first' => ['driver' => 'fake', 'id' => 'first', 'disk' => $disk]],
        'monitor_backups' => [['target' => 'first', 'warn_when_disk_space_is_lower_than' => '10MB']],
    ]), ['only_files' => true]);

    [$event] = eventsNamed($this->events, BackupRunner::EVENT_LOW_DISK_SPACE);
    expect(array_slice(eventNames($this->events), -2))->toBe([BackupRunner::EVENT_AFTER_RUN, BackupRunner::EVENT_LOW_DISK_SPACE])
        ->and(array_keys($event->result->lowDiskTargets))->toBe(['first']);
});

it('fires only the before-run and archive events on a dry run', function() {
    $result = $this->runner->run(runnerConfig($this->source), ['only_files' => true, 'dry_run' => true]);
    FileHelper::removeDirectory(dirname($result->archivePaths[0]));

    expect(eventNames($this->events))->toBe([BackupRunner::EVENT_BEFORE_RUN, BackupRunner::EVENT_ARCHIVE_CREATED])
        ->and(eventsNamed($this->events, BackupRunner::EVENT_ARCHIVE_CREATED)[0]->dryRun)->toBeTrue();
});

it('cleans the other targets and fires cleanup events when one target is broken', function() {
    $config = runnerConfig($this->source, ['targets' => [
        'broken' => ['driver' => 'nope'],
        'second' => ['driver' => 'fake', 'id' => 'second'],
    ]]);

    expect(fn() => $this->runner->clean($config))->toThrow(BackupFailedException::class, "Cleanup failed on target broken: Unknown target driver 'nope'.");

    [$failed] = eventsNamed($this->events, BackupRunner::EVENT_CLEANUP_FAILED);
    [$cleaned] = eventsNamed($this->events, BackupRunner::EVENT_AFTER_CLEANUP);
    expect($failed->target)->toBe('broken')
        ->and($failed->exception)->toBeInstanceOf(BackupFailedException::class)
        ->and($cleaned->target)->toBe('second')
        ->and($cleaned->runId)->toBeNull();
});

it('fires no cleanup events on a dry-run clean', function() {
    $this->runner->clean(runnerConfig($this->source), ['dry_run' => true]);

    expect(eventNames($this->events))->toBe([]);
});

it('lets the monitor use a registered driver', function() {
    $result = (new BackupMonitor())->check(runnerConfig($this->source, [
        'monitor_backups' => [['target' => 'first', 'min_number_of_backups' => 0]],
    ]));

    expect($result['status'])->toBe('ok');
});
