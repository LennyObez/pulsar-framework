<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Surfaces a config section's unrecognized keys as operator-facing boot warnings.
 *
 * {@see ConfigManager} builds every first-party section into its repository and
 * sweeps them all after load, formatting each unrecognized key through
 * {@see self::describe()}. Keeping the message string here alone means a typo in
 * config/edge.php surfaces at boot with exactly the wording as one in
 * config/security.php — the populations can never drift into two warnings for the
 * same defect.
 *
 * {@see self::report()} is the same formatting bound to a logger, for a wiring
 * that builds its config outside ConfigManager's sweep and already holds a
 * logger — chiefly a third-party extension wiring that loads its own config file.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class UnknownKeyReporter
{
    /**
     * The operator-facing descriptions for a section's unrecognized keys, empty
     * when the section recognized every key it was given.
     *
     * @return list<string>
     */
    public static function describe(string $section, ReportsUnknownKeys $config): array
    {
        $descriptions = [];

        foreach ($config->unknownConfigKeys() as $key) {
            $descriptions[] = sprintf('config section "%s": unrecognized key "%s" (ignored)', $section, $key);
        }

        return $descriptions;
    }

    /**
     * Log each unrecognized key as a boot warning through the caller's own logger.
     *
     * For a section built by its wiring rather than by {@see ConfigManager}: the
     * wiring already holds a logger, so it calls this immediately after building
     * the DTO. A section that recognized every key logs nothing.
     */
    public static function report(LoggerInterface $logger, string $section, ReportsUnknownKeys $config): void
    {
        foreach (self::describe($section, $config) as $description) {
            $logger->warning($description);
        }
    }
}
