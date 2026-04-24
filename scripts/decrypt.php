<?php

/**
 * Standalone decrypter for Craft Backup archives (.tar.gz.enc).
 *
 * No Composer, no Craft, no dependencies beyond a PHP binary with openssl+hash.
 * Copy this file next to your encrypted archive and run:
 *
 *     php decrypt.php <input.tar.gz.enc> <output.tar.gz> [base64-key]
 *
 * If you omit the key, the script reads it from the BACKUP_ENCRYPTION_KEY
 * env var. Extract the resulting .tar.gz with `tar -xzf`.
 */

const MAGIC = "CBK1";

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function readExact($stream, int $length): string
{
    $buffer = '';
    while (strlen($buffer) < $length) {
        $chunk = fread($stream, $length - strlen($buffer));
        if ($chunk === false || $chunk === '') {
            fail('Unexpected end of archive.');
        }
        $buffer .= $chunk;
    }
    return $buffer;
}

function unpackInt($stream, string $format): int
{
    $width = $format === 'N' ? 4 : 2;
    $unpacked = unpack($format, readExact($stream, $width));
    return (int) $unpacked[1];
}

if ($argc < 3) {
    fail("Usage: php decrypt.php <input.enc> <output.tar.gz> [base64-key]");
}

$input = $argv[1];
$output = $argv[2];
$base64Key = $argv[3] ?? getenv('BACKUP_ENCRYPTION_KEY') ?: null;

if (! is_file($input)) {
    fail("Input file not found: {$input}");
}
if (! $base64Key) {
    fail('No key provided. Pass it as the third argument or set BACKUP_ENCRYPTION_KEY.');
}

$key = base64_decode($base64Key, true);
if ($key === false || strlen($key) !== 32) {
    fail('Key must be base64-encoded 32 bytes.');
}

$encKey = hash_hkdf('sha256', $key, 32, 'craft-backup.enc');
$macKey = hash_hkdf('sha256', $key, 32, 'craft-backup.mac');

$totalSize = filesize($input);
if ($totalSize === false || $totalSize < strlen(MAGIC) + 4 + 32) {
    fail('Input file is too small to be a valid archive.');
}
$bodyEnd = $totalSize - 32;

$in = fopen($input, 'rb');
$out = fopen($output, 'wb');
if ($in === false || $out === false) {
    fail('Could not open input/output files.');
}

$hmac = hash_init('sha256', HASH_HMAC, $macKey);

try {
    $magic = readExact($in, strlen(MAGIC));
    if ($magic !== MAGIC) {
        fail('Not a Craft Backup archive (bad magic).');
    }

    $cipherLen = unpackInt($in, 'n');
    $cipher = readExact($in, $cipherLen);
    $ivLen = unpackInt($in, 'n');
    $iv = readExact($in, $ivLen);

    $header = MAGIC . pack('n', $cipherLen) . $cipher . pack('n', $ivLen) . $iv;
    hash_update($hmac, $header);

    while (ftell($in) < $bodyEnd) {
        $ctLen = unpackInt($in, 'N');
        if (ftell($in) + $ctLen > $bodyEnd) {
            fail('Archive is truncated or corrupt.');
        }

        $ciphertext = readExact($in, $ctLen);
        hash_update($hmac, pack('N', $ctLen) . $ciphertext);

        $plain = openssl_decrypt($ciphertext, $cipher, $encKey, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            fail('openssl_decrypt failed; wrong key?');
        }

        fwrite($out, $plain);
        $iv = substr(hash('sha256', $iv . $ciphertext, true), 0, $ivLen);
    }

    $expectedTag = hash_final($hmac, true);
    $actualTag = readExact($in, 32);
    if (! hash_equals($expectedTag, $actualTag)) {
        fail('Authentication failed; the file was tampered with or the key is wrong.');
    }
} finally {
    fclose($in);
    fclose($out);
}

fwrite(STDOUT, "Decrypted to {$output}" . PHP_EOL);
fwrite(STDOUT, "Extract with: tar -xzf {$output}" . PHP_EOL);
