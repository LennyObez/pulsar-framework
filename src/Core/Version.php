<?php

declare(strict_types=1);

namespace Pulsar\Core;

/**
 * Framework version information.
 */
final class Version
{
    public const MAJOR = 0;
    public const MINOR = 0;
    public const PATCH = 1;
    public const PRERELEASE = 'alpha';

    /**
     * Get the full version string.
     */
    public static function full(): string
    {
        $version = self::MAJOR . '.' . self::MINOR . '.' . self::PATCH;

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
