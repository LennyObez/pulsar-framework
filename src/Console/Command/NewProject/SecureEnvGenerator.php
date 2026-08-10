<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use InvalidArgumentException;
use Pulsar\Api\Internal;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function base64_encode;
use function bin2hex;
use function preg_match;
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
     * App names that contain newlines / `=` / quotes would let
     * a hostile / careless caller inject extra env keys into the
     * generated `.env` file (`MyApp\nINJECTED=evil` becomes a real
     * `INJECTED` line on the next read). The check below restricts
     * the value to the alphanumeric / dash / dot / underscore /
     * single-space set that covers every real-world project name
     * while ruling out every shell-special character.
     */
    private const string APP_NAME_PATTERN = '/^[A-Za-z0-9 ._\-]+$/';

    /**
     * Generate `.env` file content.
     *
     * @throws RandomException
     * @throws InvalidArgumentException When $appName contains shell-injection-prone characters.
     */
    public function generate(string $appName, EnvironmentPreset $env): string
    {
        if ($appName === '' || preg_match(self::APP_NAME_PATTERN, $appName) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'App name must be non-empty and contain only alphanumeric, space, dot, dash, underscore. Got: "%s"',
                $appName,
            ));
        }

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

                DB_CONNECTION=pgsql
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
