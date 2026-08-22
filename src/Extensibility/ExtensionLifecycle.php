<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Api;

/**
 * Extension lifecycle states.
 *
 * Extensions progress through these states during the boot process:
 * Discovered -> Validated -> Registered -> Booted
 *
 * Failed can occur from any state if an error occurs.
 * @api
 */
#[Api(since: '1.0.0')]
enum ExtensionLifecycle: string
{
    case Discovered = 'discovered';
    case Validated = 'validated';
    case Registered = 'registered';
    case Booted = 'booted';
    case Failed = 'failed';

    /**
     * Check if this state allows registration.
     */
    public function canRegister(): bool
    {
        return $this === self::Validated;
    }

    /**
     * Check if this state allows booting.
     */
    public function canBoot(): bool
    {
        return $this === self::Registered;
    }

    /**
     * Check if this extension has failed.
     */
    public function hasFailed(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Check if this extension has completed booting.
     */
    public function isBooted(): bool
    {
        return $this === self::Booted;
    }
}
