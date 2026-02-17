<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field\Regulated;

use Override;
use Pulsar\Api\Api;

/**
 * Data processing agreement acceptance field.
 *
 * Captures evidence that the user reviewed and accepted
 * the data processing agreement.
 */
#[Api(since: '1.0.0')]
final class DataProcessingAgreement extends AbstractRegulatedField
{
    #[Override]
    public function getType(): string
    {
        return 'checkbox';
    }

    /**
     * Whether the agreement was accepted.
     */
    public function isAccepted(): bool
    {
        return (bool) $this->value;
    }
}
