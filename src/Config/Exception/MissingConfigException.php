<?php

declare(strict_types=1);

namespace Pulsar\Config\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function array_map;
use function count;
use function dirname;
use function implode;
use function is_file;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Thrown when a required configuration file is missing during boot.
 *
 * The message names the missing file and the stub Pulsar ships for it, so the
 * operator can recover with one copy.
 *
 * It used to end with "or run 'pulsar new:config <name>'". No such command has
 * ever existed — `pulsar list` has no `new:config` — so the one line a stuck
 * operator was handed sent them to a command that would answer "Unknown
 * command". A recovery instruction that does not resolve is worse than none: it
 * spends the reader's trust before it spends their time.
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
            "Required configuration file 'config/%s.php' not found. %s",
            $name,
            self::recovery([$name]),
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
            'Required configuration files missing: %s. %s',
            $fileList,
            self::recovery($names),
        ));
    }

    /**
     * The one instruction that actually recovers the boot.
     *
     * Every required config file has a stub inside the framework package, so the
     * fix is a copy from a path this method resolves rather than guesses —
     * `dirname(__DIR__, 3)` is the package root whether Pulsar is a vendored
     * dependency or the checkout itself. The stubs are only named when they are
     * genuinely on disk; a missing stub directory (a partial install, a package
     * trimmed by an archive filter) gets the honest shorter sentence instead of
     * a path that would not resolve either.
     *
     * @param list<string> $names Config file names (without .php extension)
     */
    private static function recovery(array $names): string
    {
        $stubDirectory = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config';
        $present = [];

        foreach ($names as $name) {
            $stub = $stubDirectory . DIRECTORY_SEPARATOR . $name . '.php';

            if (is_file($stub)) {
                $present[] = $stub;
            }
        }

        if ($present === []) {
            return count($names) === 1
                ? 'Create it in the config directory ConfigManager was pointed at.'
                : 'Create them in the config directory ConfigManager was pointed at.';
        }

        return count($present) === 1
            ? sprintf('Copy the stub Pulsar ships at %s into your config directory.', $present[0])
            : sprintf('Copy the stubs Pulsar ships (%s) into your config directory.', implode(', ', $present));
    }
}
