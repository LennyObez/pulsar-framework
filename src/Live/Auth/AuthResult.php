<?php

declare(strict_types=1);

namespace Pulsar\Live\Auth;

use Pulsar\Api\Api;

/**
 * Result of an authentication operation.
 */
#[Api(since: '1.0.0')]
final readonly class AuthResult
{
    public function __construct(
        public bool $success,
        public ?string $error = null,
        public bool $requiresMfa = false,
        public ?string $identityId = null,
        public ?string $redirectUrl = null,
    ) {}

    public static function ok(?string $redirectUrl = null): self
    {
        return new self(success: true, redirectUrl: $redirectUrl);
    }

    public static function failed(string $error): self
    {
        return new self(success: false, error: $error);
    }

    public static function mfaRequired(string $identityId): self
    {
        return new self(success: false, requiresMfa: true, identityId: $identityId);
    }
}
