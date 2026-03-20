<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Token;

use Pulsar\Api\Api;

/**
 * OAuth2 scope definition.
 */
#[Api(since: '1.0.0')]
final readonly class Scope
{
    public function __construct(
        public string $id,
        public string $description = '',
    ) {}

    public function __toString(): string
    {
        return $this->id;
    }
}
