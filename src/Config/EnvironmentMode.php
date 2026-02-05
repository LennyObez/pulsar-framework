<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Application environment mode.
 */
#[Api]
enum EnvironmentMode: string
{
    case Local = 'local';
    case Staging = 'staging';
    case Production = 'production';

    /**
     * Whether debug mode should be enabled by default for this environment.
     */
    public function isDebugByDefault(): bool
    {
        return match ($this) {
            self::Local => true,
            self::Staging, self::Production => false,
        };
    }
}
