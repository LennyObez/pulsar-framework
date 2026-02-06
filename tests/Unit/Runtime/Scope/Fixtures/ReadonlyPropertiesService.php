<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Scope\Fixtures;

final class ReadonlyPropertiesService
{
    public function __construct(
        public readonly string $name,
        private readonly int $id,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
