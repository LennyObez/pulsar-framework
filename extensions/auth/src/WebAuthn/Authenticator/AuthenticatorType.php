<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Authenticator;

use Pulsar\Api\Api;

/**
 * Type of WebAuthn authenticator.
 */
#[Api(since: '1.0.0')]
enum AuthenticatorType: string
{
    /** Platform authenticator (Touch ID, Windows Hello, Face ID). */
    case Platform = 'platform';

    /** Cross-platform authenticator (security key, USB, NFC, BLE). */
    case CrossPlatform = 'cross-platform';
}
