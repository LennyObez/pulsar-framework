<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use Pulsar\Api\Api;

/**
 * Contract for CSRF token management.
 *
 * Abstracts token operations for testability while allowing
 * the concrete implementation to remain final.
 * @api
 */
#[Api(since: '1.0.0')]
interface CsrfTokenManagerInterface
{
    /**
     * Generate a new CSRF token and store it.
     */
    public function generate(): string;

    /**
     * Get the current CSRF token, generating one if none exists.
     */
    public function getToken(): string;

    /**
     * Validate a submitted token against the stored token.
     */
    public function validate(string $submittedToken): bool;

    /**
     * Rotate the CSRF token (generate a new one, invalidating the old).
     */
    public function rotate(): string;
}
