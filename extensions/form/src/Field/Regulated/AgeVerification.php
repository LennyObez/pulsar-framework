<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field\Regulated;

use Override;
use Pulsar\Api\Api;

/**
 * Age verification field with consent evidence capture.
 *
 * Requires the user to confirm they meet the minimum age requirement.
 */
#[Api(since: '1.0.0')]
final class AgeVerification extends AbstractRegulatedField
{
    public function __construct(
        string $name,
        string $label,
        string $purpose,
        string $policyVersion,
        string $policyText,
        private readonly int $minimumAge = 18,
    ) {
        parent::__construct($name, $label, $purpose, $policyVersion, $policyText);
    }

    #[Override]
    public function getType(): string
    {
        return 'checkbox';
    }

    public function getMinimumAge(): int
    {
        return $this->minimumAge;
    }

    /**
     * Whether age was verified.
     */
    public function isVerified(): bool
    {
        return (bool) $this->value;
    }
}
