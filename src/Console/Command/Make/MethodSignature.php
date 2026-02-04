<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

/**
 * Represents a parsed method signature from an interface.
 */
final readonly class MethodSignature
{
    /**
     * @param list<array{name: string, type: string, default: string|null}> $parameters
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public string $returnType,
    ) {}
}
