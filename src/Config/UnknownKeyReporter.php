<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Surfaces a config section's unrecognized keys as operator-facing boot warnings.
 *
 * {@see ConfigManager} audits the sections it builds into its repository, but a
 * large part of the config surface — `edge`, `marketplace`, `repl`, and every
 * other file a wiring loads for itself — is built outside that repository and so
 * was never swept. A typo in one of those files resolved to a default with no
 * signal anywhere.
 *
 * This is the shared reporting path those wirings call after building their DTO,
 * so a typo in config/edge.php surfaces at boot exactly as one in
 * config/security.php does. The message string lives here alone: ConfigManager's
 * own sweep formats through {@see self::describe()} too, so the two populations
 * can never drift into two different warnings for the same defect.
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
