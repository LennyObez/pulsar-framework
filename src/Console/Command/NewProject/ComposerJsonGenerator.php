<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;

use function preg_replace;

use Pulsar\Api\Internal;

use function strtolower;

/**
 * Generates a `composer.json` file tailored to the chosen project preset.
 */
#[Internal]
final class ComposerJsonGenerator
{
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
                'pulsar/framework' => '^1.0',
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
