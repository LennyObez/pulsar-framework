<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Features\ExchangeCode;

/**
 * Request DTO for exchanging an OAuth authorization code for tokens.
 */
final readonly class ExchangeCodeRequest
{
    public function __construct(
        public string $providerName,
        public string $code,
        public string $state,
    ) {}
}
