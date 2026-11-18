<?php

use webhubworks\backup\models\BackupResult;
use webhubworks\backup\services\notifications\DiscordChannel;
use webhubworks\backup\services\notifications\Notification;
use webhubworks\backup\services\notifications\WebhookChannel;

function failedRun(array $errors = ['target offsite: Connection refused']): Notification
{
    return new Notification(
        event: Notification::EVENT_FAILURE,
        siteName: 'Example',
        subject: '[Craft Backup] Failure on Example',
        intro: 'A backup run did not complete successfully.',
        body: 'A backup run did not complete successfully.',
        result: new BackupResult(
            runId: 'ead13fb4',
            archivePaths: ['/var/www/storage/runtime/temp/craft-backup/run/site-2026-11-18_05-42-18-ead13fb4.zip'],
            archiveBytes: 1300000,
            durationSeconds: 42.13,
            targetStatuses: ['local' => 'ok', 'offsite' => 'failed: Connection refused'],
            errors: $errors,
            archiveSizes: ['site-2026-11-18_05-42-18-ead13fb4.zip' => 1300000],
        ),
    );
}

it('builds a red Discord embed with the run facts', function() {
    $embed = (new DiscordChannel(['webhook_url' => 'https://discord.test/hook', 'username' => 'Backups']))
        ->payload(failedRun())['embeds'][0];

    expect($embed['title'])->toBe('❌ Failure on Example')
        ->and($embed['color'])->toBe(0xC62828)
        ->and(array_column($embed['fields'], 'name'))->toBe(['Run ID', 'Duration', 'Size', 'Targets', 'Errors'])
        ->and($embed['fields'][3]['value'])->toBe("✅ local: ok\n❌ offsite: failed: Connection refused");
});

it('cuts long Discord fields off with a count of the rest', function() {
    $errors = array_map(fn(int $i) => "target t{$i}: " . str_repeat('x', 80), range(1, 50));

    $errorsField = (new DiscordChannel(['webhook_url' => 'https://discord.test/hook']))
        ->payload(failedRun($errors))['embeds'][0]['fields'][4];

    expect(mb_strlen($errorsField['value']))->toBeLessThanOrEqual(1024)
        ->and($errorsField['value'])->toMatch('/\.\.\. \d+ more$/');
});

it('sends archive basenames and parsed target results in the webhook body', function() {
    $body = (new WebhookChannel(['url' => 'https://hooks.test/backup']))->payload(failedRun());

    expect($body['schema'])->toBe(1)
        ->and($body['status'])->toBe('failure')
        ->and($body['run_id'])->toBe('ead13fb4')
        ->and($body['archives'])->toBe([['name' => 'site-2026-11-18_05-42-18-ead13fb4.zip', 'bytes' => 1300000]])
        ->and((array) $body['targets'])->toBe([
            'local' => ['status' => 'ok', 'error' => null],
            'offsite' => ['status' => 'failed', 'error' => 'Connection refused'],
        ])
        ->and(json_encode($body))->not->toContain('/var/www');
});

it('signs the webhook body when a secret is set', function() {
    $headers = new ReflectionMethod(WebhookChannel::class, 'headers');

    expect($headers->invoke(new WebhookChannel(['url' => 'https://hooks.test', 'secret' => 's3cret']), '{"a":1}'))
        ->toBe(['X-Craft-Backup-Signature' => 'sha256=' . hash_hmac('sha256', '{"a":1}', 's3cret')])
        ->and($headers->invoke(new WebhookChannel(['url' => 'https://hooks.test']), '{"a":1}'))
        ->toBe([]);
});

it('only wants events that are switched on', function() {
    $channel = new DiscordChannel(['webhook_url' => 'https://discord.test/hook', 'on_failure' => true]);

    expect($channel->wants(Notification::EVENT_FAILURE))->toBeTrue()
        ->and($channel->wants(Notification::EVENT_SUCCESS))->toBeFalse()
        ->and((new DiscordChannel(['on_failure' => true]))->wants(Notification::EVENT_FAILURE))->toBeFalse();
});

it('leaves out empty error lines, which Discord rejects', function() {
    $fields = (new DiscordChannel(['webhook_url' => 'https://discord.test/hook']))
        ->payload(failedRun(['']))['embeds'][0]['fields'];

    expect(array_column($fields, 'name'))->not->toContain('Errors');
});

it('keeps the webhook token out of delivery errors', function() {
    $channel = new WebhookChannel(['url' => 'http://user:pass@127.0.0.1:1/hooks/secret-token']);

    expect(fn() => $channel->send(failedRun()))
        ->toThrow(fn(RuntimeException $e) => expect($e->getMessage())
            ->not->toContain('secret-token')
            ->not->toContain('pass')
            ->toContain('http://127.0.0.1:1/[redacted]'));
});
