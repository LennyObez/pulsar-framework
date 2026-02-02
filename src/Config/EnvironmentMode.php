<?php

declare(strict_types=1);

namespace Pulsar\Config;

/**
 * Application environment mode.
 */
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
            self::Staging => false,
            self::Production => false,
        };
    }
}
