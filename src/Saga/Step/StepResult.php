<?php

declare(strict_types=1);

namespace Pulsar\Saga\Step;

use Pulsar\Api\Api;
use Throwable;

/**
 * Result of executing a single saga step (forward or compensation).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StepResult
{
    /**
     * @param array<string, mixed> $output
     */
    private function __construct(
        public string $stepName,
        public bool $success,
        public array $output,
        public ?Throwable $error,
    ) {}

    /**
     * @param array<string, mixed> $output
     */
    public static function success(string $stepName, array $output = []): self
    {
        return new self(stepName: $stepName, success: true, output: $output, error: null);
    }

    public static function failure(string $stepName, Throwable $error): self
    {
        return new self(stepName: $stepName, success: false, output: [], error: $error);
    }
}
