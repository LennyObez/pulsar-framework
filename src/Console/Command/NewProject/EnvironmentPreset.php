<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Target environment for new project scaffolding.
 *
 * Controls debug flags, APP_URL defaults, and security posture
 * in the generated `.env` file.
 */
#[Api(since: '1.0.0')]
enum EnvironmentPreset: string
{
    case Local = 'local';
    case Staging = 'staging';
    case Production = 'production';

    /**
     * Resolve an environment preset from user input, defaulting to Local.
     */
    #[NoDiscard]
    public static function fromInput(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Local;
        }

        return self::tryFrom($value) ?? self::Local;
    }
}
