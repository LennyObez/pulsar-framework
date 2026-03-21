<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Exception;

use Pulsar\Api\Api;
use RuntimeException;

/**
 * Base exception for the form extension.
 * @api
 */
#[Api(since: '1.0.0')]
class FormException extends RuntimeException
{
    public static function invalidField(string $name): self
    {
        return new self("Field '$name' does not exist in this form");
    }

    public static function alreadySubmitted(): self
    {
        return new self('Form has already been submitted');
    }

    public static function notSubmitted(): self
    {
        return new self('Form has not been submitted yet');
    }

    public static function configurationError(string $message): self
    {
        return new self("Form configuration error: $message");
    }
}
