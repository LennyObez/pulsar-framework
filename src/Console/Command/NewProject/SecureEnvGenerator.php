<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use function base64_encode;
use function bin2hex;

use Pulsar\Api\Internal;

use function random_bytes;
use function sprintf;

/**
 * Generates a cryptographically secure `.env` file for a new project.
 *
 * Every generated file contains unique APP_KEY and PULSAR_MASTER_KEY
 * values derived from `random_bytes()`. Keys are never printed to stdout.
 */
#[Internal]
final class SecureEnvGenerator
{
    /**
     * Generate `.env` file content.
     *
     * @throws \Random\RandomException
     */
    public function generate(string $appName, EnvironmentPreset $env): string
    {
        $debug = $env === EnvironmentPreset::Local ? 'true' : 'false';
        $url = $env === EnvironmentPreset::Local
            ? 'http://localhost:8000'
            : 'https://example.com';

        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $masterKey = bin2hex(random_bytes(32));

        return sprintf(
            <<<'ENV'
                APP_NAME=%s
                APP_ENV=%s
                APP_DEBUG=%s
                APP_URL=%s

                APP_KEY=%s
                PULSAR_MASTER_KEY=%s
                ENV,
            $appName,
            $env->value,
            $debug,
            $url,
            $appKey,
            $masterKey,
        ) . "\n";
    }
}
