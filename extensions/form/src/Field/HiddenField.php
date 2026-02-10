<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Hidden input field.
 */
#[Api(since: '1.0.0')]
final class HiddenField extends AbstractField
{
    #[Override]
    public function getType(): string
    {
        return 'hidden';
    }
}
