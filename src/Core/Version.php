<?php

declare(strict_types=1);

namespace Pulsar\Core;

use Composer\InstalledVersions;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Framework version information.
 *
 * Reads the version from Composer's InstalledVersions at runtime (zero IO --
 * the data is compiled into vendor/composer/installed.php). Falls back to the
 * compile-time constants when InstalledVersions is unavailable (e.g., running
 * without the Composer autoloader).
 * @api
 */
#[Api(since: '1.0.0-rc.1')]
final class Version
{
    public const int MAJOR = 1;
    public const int MINOR = 0;
    public const int PATCH = 0;

    /** Pre-release suffix including the leading hyphen, or '' for stable releases. */
    public const string PRERELEASE_SUFFIX = '-rc.12';

    /**
     * The Composer package name of the framework itself.
     *
     * The single place this repository spells its own package name. Anything that
     * has to name it -- the runtime version lookup below, the `composer.json` that
     * `pulsar init` writes for a new project -- derives it from here rather than
     * restating it. It was restated once, in
     * {@see \Pulsar\Console\Command\NewProject\ComposerJsonGenerator}, and the copy
     * was still the pre-rename `lennyobez/pulsar` long after this one was right:
     * every project the scaffolder produced required a package that has never
     * existed under that name, and nothing compared the two strings.
     */
    public const string PACKAGE_NAME = 'pulsar/framework';

    /**
     * Get the full version string (e.g. "1.2.3-rc.4" or "1.2.3").
     */
    #[NoDiscard]
    public static function full(): string
    {
        if (class_exists(InstalledVersions::class, false)) {
            $pretty = InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);

            if ($pretty !== null) {
                return $pretty;
            }
        }

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
