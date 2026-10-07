<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field\Regulated;

use Override;
use Pulsar\Api\Api;

/**
 * Signature capture field with consent evidence.
 *
 * Captures a text-based signature (typed name) along with
 * full consent evidence for regulated compliance.
 * @api
 */
#[Api(since: '1.0.0')]
final class SignatureField extends AbstractRegulatedField
{
    #[Override]
    public function getType(): string
    {
        return 'text';
    }

    /**
     * Get the signature value (typed name).
     */
    public function getSignature(): string
    {
        if ($this->value === null) {
            return '';
        }

        /** @var string|int|float|bool $val */
        $val = $this->value;

        return (string) $val;
    }

    /**
     * Whether a signature was provided.
     */
    public function isSigned(): bool
    {
        return $this->getSignature() !== '';
    }
}
