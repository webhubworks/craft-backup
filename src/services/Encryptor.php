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

    private function deriveSubkeys(string $masterKey): array
    {
        return [
            hash_hkdf('sha256', $masterKey, 32, 'craft-backup.enc'),
            hash_hkdf('sha256', $masterKey, 32, 'craft-backup.mac'),
        ];
    }
}
