<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field\Regulated;

use Override;
use Pulsar\Api\Api;

/**
 * GDPR consent checkbox with evidence capture.
 *
 * Captures full consent evidence including what the user was shown,
 * when they consented, and a hash of the policy text.
 * @api
 */
#[Api(since: '1.0.0')]
final class ConsentCheckbox extends AbstractRegulatedField
{
    #[Override]
    public function getType(): string
    {
        return 'checkbox';
    }

    /**
     * Whether consent was given.
     */
    public function isConsented(): bool
    {
        return (bool) $this->value;
    }
}
