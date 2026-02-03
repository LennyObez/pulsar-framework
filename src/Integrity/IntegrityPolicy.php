<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Config\IntegrityPolicyMode;

/**
 * Resolved integrity policy derived from configuration.
 */
#[Api]
final readonly class IntegrityPolicy
{
    public function __construct(
        public IntegrityPolicyMode $mode,
    ) {}

    /**
     * Create an IntegrityPolicy from the integrity configuration.
     */
    #[NoDiscard]
    public static function fromConfig(IntegrityConfig $config): self
    {
        return new self(
            mode: $config->mode,
        );
    }
}
