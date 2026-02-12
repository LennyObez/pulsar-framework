<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\ABTest;

use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
final readonly class ExperimentVariant
{
    public function __construct(
        public string $id,
        public string $experimentId,
        public string $name,
        public string $contentId,
        public int $weight,
    ) {}
}
