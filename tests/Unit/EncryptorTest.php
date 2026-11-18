<?php

use craft\helpers\FileHelper;
use webhubworks\backup\exceptions\BackupFailedException;
use webhubworks\backup\services\Encryptor;

beforeEach(function() {
    $this->dir = sys_get_temp_dir() . '/craft-backup-enc-' . bin2hex(random_bytes(4));
    FileHelper::createDirectory($this->dir);
    $this->key = base64_encode(random_bytes(32));
    // 2.5 MB, so the archive spans three 1 MB chunks.
    $this->plain = random_bytes(2_500_000);
    file_put_contents("{$this->dir}/archive.tar.gz", $this->plain);
    (new Encryptor())->encrypt("{$this->dir}/archive.tar.gz", "{$this->dir}/archive.tar.gz.enc", 'aes-256-cbc', $this->key);
});

afterEach(function() {
    FileHelper::removeDirectory($this->dir);
});

it('decrypts what it encrypted, across several chunks', function() {
    (new Encryptor())->decrypt("{$this->dir}/archive.tar.gz.enc", "{$this->dir}/out.tar.gz", $this->key);

    expect(file_get_contents("{$this->dir}/out.tar.gz"))->toBe($this->plain);
});

it('produces archives the standalone decrypt script can open', function() {
    $script = dirname((new ReflectionClass(Encryptor::class))->getFileName(), 3) . '/scripts/decrypt.php';
    exec(sprintf(
        '%s %s %s %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($script),
        escapeshellarg("{$this->dir}/archive.tar.gz.enc"),
        escapeshellarg("{$this->dir}/out.tar.gz"),
        escapeshellarg($this->key),
    ), $output, $exitCode);

    expect($exitCode)->toBe(0, implode("\n", $output))
        ->and(file_get_contents("{$this->dir}/out.tar.gz"))->toBe($this->plain);
});

it('rejects a tampered archive', function() {
    $encrypted = file_get_contents("{$this->dir}/archive.tar.gz.enc");
    $encrypted[1000] = chr(ord($encrypted[1000]) ^ 1);
    file_put_contents("{$this->dir}/archive.tar.gz.enc", $encrypted);

    expect(fn() => (new Encryptor())->decrypt("{$this->dir}/archive.tar.gz.enc", "{$this->dir}/out.tar.gz", $this->key))
        ->toThrow(BackupFailedException::class);
});

it('rejects the wrong key', function() {
    expect(fn() => (new Encryptor())->decrypt("{$this->dir}/archive.tar.gz.enc", "{$this->dir}/out.tar.gz", base64_encode(random_bytes(32))))
        ->toThrow(BackupFailedException::class);
});
