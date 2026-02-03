<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\Request;

/**
 * Context for evaluating a feature flag.
 */
#[Api]
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
    public static function fromRequest(Request $request): self
    {
        /** @var ?string $tenantId */
        $tenantId = $request->attribute('_tenant_id');

        /** @var ?string $userId */
        $userId = $request->attribute('_user_id');

        return new self(
            tenantId: $tenantId,
            userId: $userId,
        );
    }
}
