<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Exception;

use Pulsar\Api\Api;

/**
 * Thrown when CSRF token validation fails.
 */
#[Api(since: '1.0.0')]
final class CsrfException extends FormException
{
    public static function tokenMissing(): self
    {
        return new self('CSRF token is missing from the form submission');
    }

    public static function tokenInvalid(): self
    {
        return new self('CSRF token is invalid or has expired');
    }

    public static function tokenExpired(): self
    {
        return new self('CSRF token has expired');
    }
}
