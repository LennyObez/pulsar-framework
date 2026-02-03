<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use NoDiscard;
use Pulsar\Api\Internal;

/**
 * Determines the directory tree for a new project based on the chosen preset.
 */
#[Internal]
final class DirectoryLayout
{
    /** @var list<string> Directories shared by every preset. */
    private const array BASE_DIRECTORIES = [
        'config',
        'public',
        'src',
        'var/cache',
        'var/log',
    ];

    /** @var list<string> Additional directories for the Web preset. */
    private const array WEB_DIRECTORIES = [
        'resources/views',
        'resources/assets',
    ];

    /** @var list<string> Additional directories for the Api preset. */
    private const array API_DIRECTORIES = [
        'src/Http/Controller',
        'src/Http/Middleware',
    ];

    /**
     * Return the full directory list for a given preset.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public static function forPreset(ProjectPreset $preset): array
    {
        return match ($preset) {
            ProjectPreset::Minimal => self::BASE_DIRECTORIES,
            ProjectPreset::Web => [...self::BASE_DIRECTORIES, ...self::WEB_DIRECTORIES],
            ProjectPreset::Api => [...self::BASE_DIRECTORIES, ...self::API_DIRECTORIES],
        };
    }
}
