<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Core\Version;

use function json_encode;
use function preg_replace;
use function strtolower;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Generates a `composer.json` file tailored to the chosen project preset.
 */
#[Internal]
final class ComposerJsonGenerator
{
    /**
     * The Composer package name a scaffolded project requires.
     *
     * Derived, never restated. This constant used to carry a literal
     * `'lennyobez/pulsar'` under a comment saying it should be updated "when the
     * package migrates to `pulsar/framework`" -- a migration that had already
     * happened in `composer.json` and in {@see Version::PACKAGE_NAME}. Every
     * project `pulsar init` and `pulsar new` produced therefore required a
     * package name that has never existed, and `composer install` inside it could
     * not resolve; the comment describing the rename was the only trace, and no
     * test compared the two strings because both of them read the same constant.
     *
     * {@see \Pulsar\Tests\Unit\Console\Command\NewProject\ComposerJsonGeneratorTest}
     * now compares what is emitted against the `name` in this repository's own
     * `composer.json`, so the emitted requirement cannot name a package this
     * repository does not publish.
     */
    public const string FRAMEWORK_PACKAGE = Version::PACKAGE_NAME;

    /**
     * The version constraint a scaffolded project declares against the framework.
     *
     * Derived from the framework generating it, which matters while that version
     * is a release candidate: `^1.0` excludes `1.0.0-rc.12` outright, because
     * Composer orders a pre-release below the release it precedes. A project
     * scaffolded today would have been handed a constraint no published Pulsar
     * could ever satisfy. `^1.0.0-rc.12` resolves against the current release
     * candidate, against `1.0.0` when it is tagged, and against every 1.x after
     * it -- and carries its own stability flag, so the project needs no
     * `minimum-stability` relaxation. Once `PRERELEASE_SUFFIX` empties at GA this
     * collapses to `^1.0.0` on its own.
     */
    public const string FRAMEWORK_CONSTRAINT = '^'
        . Version::MAJOR . '.' . Version::MINOR . '.' . Version::PATCH
        . Version::PRERELEASE_SUFFIX;

    /**
     * Generate `composer.json` content.
     *
     * @throws JsonException If JSON encoding fails
     */
    public function generate(string $appName, ProjectPreset $preset): string
    {
        $slug = $this->slugify($appName);

        $composer = [
            'name' => 'app/' . $slug,
            'description' => 'A Pulsar Framework application (' . $preset->value . ' preset)',
            'type' => 'project',
            'license' => 'proprietary',
            'require' => [
                'php' => '>=8.5',
                self::FRAMEWORK_PACKAGE => self::FRAMEWORK_CONSTRAINT,
            ],
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'tests/',
                ],
            ],
            'scripts' => [
                'serve' => 'php -S localhost:8000 -t public',
                'test' => 'vendor/bin/phpunit',
            ],
            'config' => [
                'sort-packages' => true,
            ],
        ];

        return json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Convert a project name to a lowercase, hyphenated slug.
     */
    private function slugify(string $name): string
    {
        // Insert hyphen before uppercase letters
        $hyphenated = preg_replace('/[A-Z]/', '-$0', $name) ?? $name;

        // Lowercase and normalize separators
        $slug = strtolower(trim($hyphenated, '-'));
        $slug = str_replace(['_', ' '], '-', $slug);

        // Collapse consecutive hyphens
        return (string) preg_replace('/-+/', '-', $slug);
    }
}
