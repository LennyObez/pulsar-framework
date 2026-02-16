<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Context for evaluating a feature flag.
 */
#[Api(since: '1.0.0')]
readonly class FlagContext
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public ?string $tenantId = null,
        public ?string $userId = null,
        public ?string $environment = null,
        public array $attributes = [],
    ) {}

    /**
     * Build a FlagContext from an HTTP request.
     */
    #[NoDiscard]
    public static function fromRequest(ServerRequestInterface $request): self
    {
        /** @var ?string $tenantId */
        $tenantId = $request->getAttribute('_tenant_id');

        /** @var ?string $userId */
        $userId = $request->getAttribute('_user_id');

        return new self(
            tenantId: $tenantId,
            userId: $userId,
        );
    }
}
