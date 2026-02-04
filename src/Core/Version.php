<?php

declare(strict_types=1);

namespace Pulsar\Core;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Framework version information.
 */
#[Api(since: '1.0.0-rc.1')]
final class Version
{
    public const int MAJOR = 1;
    public const int MINOR = 0;
    public const int PATCH = 0;

    /** Pre-release suffix including the leading hyphen, or '' for stable releases. */
    public const string PRERELEASE_SUFFIX = '-rc.5';

    /**
     * Get the full version string (e.g. "1.0.0-rc.1" or "1.0.0").
     */
    #[NoDiscard]
    public static function full(): string
    {
        return self::short() . self::PRERELEASE_SUFFIX;
    }

    /**
     * Get the short version string (major.minor.patch).
     */
    #[NoDiscard]
    public static function short(): string
    {
        return self::MAJOR . '.' . self::MINOR . '.' . self::PATCH;
    }
}
