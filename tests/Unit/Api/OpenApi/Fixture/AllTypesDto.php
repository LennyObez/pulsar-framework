<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Fixture;

final class AllTypesDto
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $name,
        public int $age,
        public float $score = 0.0,
        public bool $active = false,
        public array $tags = [],
    ) {}
}
