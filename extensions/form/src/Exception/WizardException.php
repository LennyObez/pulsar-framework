<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Exception;

use Pulsar\Api\Api;

/**
 * Thrown when wizard state machine operations fail.
 */
#[Api(since: '1.0.0')]
final class WizardException extends FormException
{
    public static function expired(): self
    {
        return new self('Wizard session has expired; please start over');
    }

    public static function invalidResumeToken(): self
    {
        return new self('Resume token is invalid or has already been used');
    }

    public static function stepReplay(int $step): self
    {
        return new self("Step {$step} has already been completed and cannot be re-submitted");
    }

    public static function invalidTransition(int $current, int $requested): self
    {
        return new self("Cannot transition from step {$current} to step {$requested}");
    }

    public static function decryptionFailed(): self
    {
        return new self('Failed to decrypt wizard state; session may have been tampered with');
    }
}
