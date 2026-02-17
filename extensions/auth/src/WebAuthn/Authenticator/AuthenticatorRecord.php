<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Authenticator;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Authenticator management record.
 *
 * Human-friendly representation of a registered authenticator
 * with display name, type info, and active status.
 */
#[Api(since: '1.0.0')]
final readonly class AuthenticatorRecord
{
    public function __construct(
        public string $credentialId,
        public string $userId,
        public string $displayName,
        public AuthenticatorType $type,
        public string $aaguid,
        public bool $active,
        public DateTimeImmutable $registeredAt,
        public ?DateTimeImmutable $lastUsedAt = null,
    ) {}
}
