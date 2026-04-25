<?php

namespace webhubworks\backup\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use webhubworks\backup\services\Encryptor;
use yii\console\ExitCode;

/**
 * ./craft backup/decrypt <input> [output]
 *
 * Reverses a Craft Backup .enc archive into plain .tar.gz. Extract the result
 * with `tar -xzf <output>`.
 */
class DecryptController extends Controller
{
    use LoadsConfig;

    public ?string $key = null;

    public $defaultAction = 'index';

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'key'];
    }

    public function actionIndex(string $input, ?string $output = null): int
    {
        if (!is_file($input)) {
            $this->stderr("Input file not found: {$input}\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }

        $base64Key = $this->key ?? $this->loadConfig()->encryptionKey;
        if (!is_string($base64Key) || $base64Key === '') {
            $this->stderr("No key provided. Pass --key=<base64> or set encryption.key in config/backup.php.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $output ??= preg_replace('/\.enc$/', '', $input) ?: $input . '.decrypted';

        try {
            (new Encryptor())->decrypt($input, $output, $base64Key);
        } catch (\Throwable $e) {
            $this->stderr('Decrypt failed: ' . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Decrypted to {$output}\n", Console::FG_GREEN);
        $this->stdout("Extract with: tar -xzf {$output}\n");

        return ExitCode::OK;
    }
}
