<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;

/**
 * Contract for tokenizing sensitive data such as PANs (Primary Account Numbers).
 *
 * Replaces sensitive values with non-sensitive tokens that can be safely stored
 * and transmitted. Supports PCI-DSS Requirement 3.4 compliance by rendering
 * cardholder data unreadable in storage.
 * @api
 */
#[Api(since: '1.0.0')]
interface TokenizationServiceInterface
{
    /**
     * Replace a sensitive value with a non-reversible-looking token.
     *
     * The token preserves no information about the original value (unless
     * format-preserving mode is used for PANs, which retains first 6 / last 4).
     *
     * @param string $sensitiveData The value to tokenize
     * @param string $context       A domain context for scoping (e.g. 'pan', 'ssn')
     *
     * @return string The generated token
     *
     * @throws SecurityException If tokenization fails
     */
    public function tokenize(string $sensitiveData, string $context): string;

    /**
     * Retrieve the original sensitive value for a given token.
     *
     * @param string $token The token returned by tokenize()
     *
     * @return string The original sensitive data
     *
     * @throws SecurityException If the token is unknown or detokenization fails
     */
    public function detokenize(string $token): string;

    /**
     * Check whether a value is a token produced by this service.
     *
     * @param string $value The value to check
     */
    public function isTokenized(string $value): bool;
}
