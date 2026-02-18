<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Color picker input field.
 */
#[Api(since: '1.0.0')]
final class ColorField extends AbstractField
{
    #[Override]
    public function getType(): string
    {
        return 'color';
    }
}
