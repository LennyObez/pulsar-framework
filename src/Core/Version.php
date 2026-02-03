<?php

declare(strict_types=1);

namespace Pulsar\Core;

/**
 * Framework version information.
 */
final class Version
{
    public const int MAJOR = 0;
    public const int MINOR = 7;
    public const int PATCH = 0;
    public const string PRERELEASE = '';

    /**
     * Get the full version string.
     */
    public static function full(): string
    {
        $version = self::MAJOR . '.' . self::MINOR . '.' . self::PATCH;

        // @phpstan-ignore notIdentical.alwaysFalse (condition is valid when PRERELEASE is set in future versions)
        if (self::PRERELEASE !== '') {
            $version .= '-' . self::PRERELEASE;
        }

        return $version;
    }

    /**
     * Get the short version string (major.minor.patch).
     */
    public static function short(): string
    {
        return self::MAJOR . '.' . self::MINOR . '.' . self::PATCH;
    }
}
