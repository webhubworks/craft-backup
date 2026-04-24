<?php

namespace webhubworks\backup\services;

use webhubworks\backup\exceptions\BackupFailedException;

/**
 * Streaming, authenticated encryption for backup archives.
 *
 * File layout: [magic "CBK1"][cipher-len|cipher][iv-len|iv][ciphertext][32-byte HMAC-SHA256]
 *
 * The key in config is a base64-encoded 32-byte secret (pastable into any
 * password manager). Encryption and MAC use separate subkeys derived via HKDF.
 */
class Encryptor
{
    private const MAGIC = "CBK1";
    private const CHUNK_BYTES = 1024 * 1024; // 1 MB plaintext chunks

    public function encrypt(string $inputFile, string $outputFile, string $cipher, string $base64Key): string
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new BackupFailedException('Encryption key must be base64-encoded 32 bytes.');
        }

        [$encKey, $macKey] = $this->deriveSubkeys($key);

        $ivLength = openssl_cipher_iv_length($cipher) ?: 16;
        $iv = random_bytes($ivLength);

        $in = fopen($inputFile, 'rb');
        $out = fopen($outputFile, 'wb');
        if ($in === false || $out === false) {
            throw new BackupFailedException('Could not open files for encryption.');
        }

        $hmac = hash_init('sha256', HASH_HMAC, $macKey);

        $header = self::MAGIC
            . pack('n', strlen($cipher)) . $cipher
            . pack('n', strlen($iv)) . $iv;
        fwrite($out, $header);
        hash_update($hmac, $header);

        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK_BYTES);
                if ($chunk === '' || $chunk === false) {
                    break;
                }

                $ciphertext = openssl_encrypt(
                    $chunk,
                    $cipher,
                    $encKey,
                    OPENSSL_RAW_DATA,
                    $iv,
                );
                if ($ciphertext === false) {
                    throw new BackupFailedException('openssl_encrypt failed.');
                }

                $framed = pack('N', strlen($ciphertext)) . $ciphertext;
                fwrite($out, $framed);
                hash_update($hmac, $framed);

                // Chain IV deterministically so each chunk has a unique IV.
                $iv = substr(hash('sha256', $iv . $ciphertext, true), 0, $ivLength);
            }

            fwrite($out, hash_final($hmac, true));
        } finally {
            fclose($in);
            fclose($out);
        }

        return $outputFile;
    }

    public function decrypt(string $inputFile, string $outputFile, string $base64Key): string
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new BackupFailedException('Encryption key must be base64-encoded 32 bytes.');
        }

        [$encKey, $macKey] = $this->deriveSubkeys($key);

        $totalSize = filesize($inputFile);
        if ($totalSize === false || $totalSize < strlen(self::MAGIC) + 4 + 32) {
            throw new BackupFailedException('Input file is too small to be a valid Craft Backup archive.');
        }

        $in = fopen($inputFile, 'rb');
        $out = fopen($outputFile, 'wb');
        if ($in === false || $out === false) {
            throw new BackupFailedException('Could not open files for decryption.');
        }

        $hmac = hash_init('sha256', HASH_HMAC, $macKey);
        $bodyEnd = $totalSize - 32; // last 32 bytes are the HMAC tag

        try {
            // --- header ---
            $magic = fread($in, strlen(self::MAGIC));
            if ($magic !== self::MAGIC) {
                throw new BackupFailedException('Not a Craft Backup archive (bad magic).');
            }

            $cipherLen = $this->readPackN($in, 'n');
            $cipher = fread($in, $cipherLen);
            $ivLen = $this->readPackN($in, 'n');
            $iv = fread($in, $ivLen);

            $header = self::MAGIC
                . pack('n', $cipherLen) . $cipher
                . pack('n', $ivLen) . $iv;
            hash_update($hmac, $header);

            // --- framed chunks ---
            while (ftell($in) < $bodyEnd) {
                $ctLen = $this->readPackN($in, 'N');
                if (ftell($in) + $ctLen > $bodyEnd) {
                    throw new BackupFailedException('Archive is truncated or corrupt.');
                }

                $ciphertext = fread($in, $ctLen);
                $framed = pack('N', $ctLen) . $ciphertext;
                hash_update($hmac, $framed);

                $plain = openssl_decrypt(
                    $ciphertext,
                    $cipher,
                    $encKey,
                    OPENSSL_RAW_DATA,
                    $iv,
                );
                if ($plain === false) {
                    throw new BackupFailedException('openssl_decrypt failed; wrong key?');
                }

                fwrite($out, $plain);

                // Same IV chain as encryption.
                $iv = substr(hash('sha256', $iv . $ciphertext, true), 0, $ivLen);
            }

            // --- authenticate ---
            $expectedTag = hash_final($hmac, true);
            $actualTag = fread($in, 32);
            if (! hash_equals($expectedTag, $actualTag)) {
                throw new BackupFailedException('Authentication failed; the file was tampered with or the key is wrong.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $outputFile;
    }

    private function readPackN($stream, string $format): int
    {
        $width = $format === 'N' ? 4 : 2;
        $bytes = fread($stream, $width);
        if ($bytes === false || strlen($bytes) !== $width) {
            throw new BackupFailedException('Unexpected end of archive.');
        }
        /** @var array<int,int> $unpacked */
        $unpacked = unpack($format, $bytes);
        return (int) $unpacked[1];
    }

    private function deriveSubkeys(string $masterKey): array
    {
        return [
            hash_hkdf('sha256', $masterKey, 32, 'craft-backup.enc'),
            hash_hkdf('sha256', $masterKey, 32, 'craft-backup.mac'),
        ];
    }
}
