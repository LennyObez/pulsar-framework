<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Contracts;

use Pulsar\Api\Api;

/**
 * Immutable result of a process execution.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ProcessResult
{
    public function __construct(
        public string $output,
        public bool $isError,
    ) {}

    /**
     * Create a successful result.
     */
    public static function success(string $output): self
    {
        return new self(output: $output, isError: false);
    }

    /**
     * Create an error result.
     */
    public static function error(string $output = ''): self
    {
        return new self(output: $output, isError: true);
    }
}
