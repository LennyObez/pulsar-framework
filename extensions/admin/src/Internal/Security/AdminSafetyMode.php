<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Security;

use Pulsar\Api\Internal;

use function array_diff_key;
use function array_flip;

/**
 * Production safety mode for admin error responses.
 *
 * In production, strips internal details (stack traces, SQL queries)
 * from error responses to prevent information leakage.
 */
#[Internal]
final class AdminSafetyMode
{
    private const array INTERNAL_KEYS = ['trace', 'sql', 'bindings', 'file', 'line', 'class', 'function'];

    public function __construct(
        private readonly bool $debug,
    ) {}

    /**
     * Sanitize error data for the response.
     *
     * @param array<string, mixed> $errorData
     * @return array<string, mixed>
     */
    public function sanitize(array $errorData): array
    {
        if ($this->debug) {
            return $errorData;
        }

        return array_diff_key($errorData, array_flip(self::INTERNAL_KEYS));
    }

    /**
     * Get the error message appropriate for the current mode.
     */
    public function errorMessage(string $internalMessage): string
    {
        if ($this->debug) {
            return $internalMessage;
        }

        return 'An error occurred while processing your request';
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }
}
