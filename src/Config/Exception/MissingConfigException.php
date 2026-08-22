<?php

declare(strict_types=1);

namespace Pulsar\Config\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function count;
use function implode;
use function sprintf;

/**
 * Thrown when a required configuration file is missing during boot.
 *
 * Provides a clear message identifying the missing file and suggesting
 * how to create it (either manually or via `pulsar new:config`).
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final class MissingConfigException extends RuntimeException
{
    /**
     * Create an exception for a single missing required config file.
     */
    #[NoDiscard]
    public static function forFile(string $name): self
    {
        return new self(sprintf(
            "Required configuration file 'config/%s.php' not found. "
            . "Create it or run 'pulsar new:config %s'.",
            $name,
            $name,
        ));
    }

    /**
     * Create an exception for multiple missing required config files.
     *
     * @param list<string> $names Config file names (without .php extension)
     */
    #[NoDiscard]
    public static function forFiles(array $names): self
    {
        if (count($names) === 1) {
            return self::forFile($names[0]);
        }

        $fileList = implode(', ', array_map(
            static fn(string $n): string => "config/{$n}.php",
            $names,
        ));

        return new self(sprintf(
            'Required configuration files missing: %s. '
            . "Create them or run 'pulsar new:config <name>' for each.",
            $fileList,
        ));
    }
}
