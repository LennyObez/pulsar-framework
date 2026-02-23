<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use Pulsar\Api\Internal;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function base64_encode;
use function bin2hex;
use function sprintf;

/**
 * Generates a cryptographically secure `.env` file for a new project.
 *
 * Every generated file contains unique APP_KEY and PULSAR_MASTER_KEY
 * values derived from `random_bytes()`. Keys are never printed to stdout.
 */
#[Internal]
final readonly class SecureEnvGenerator
{
    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Generate `.env` file content.
     *
     * @throws RandomException
     */
    public function generate(string $appName, EnvironmentPreset $env): string
    {
        $debug = $env === EnvironmentPreset::Local ? 'true' : 'false';
        $url = $env === EnvironmentPreset::Local
            ? 'http://localhost:8000'
            : 'https://example.com';

        $appKey = 'base64:' . base64_encode($this->randomizer->getBytes(32));
        $masterKey = bin2hex($this->randomizer->getBytes(32));

        return sprintf(
            <<<'ENV'
                APP_NAME=%s
                APP_ENV=%s
                APP_DEBUG=%s
                APP_URL=%s

                APP_KEY=%s
                PULSAR_MASTER_KEY=%s

                DB_DRIVER=pgsql
                DB_HOST=127.0.0.1
                DB_PORT=5432
                DB_DATABASE=pulsar
                DB_USERNAME=pulsar
                DB_PASSWORD=
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
