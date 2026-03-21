<?php

declare(strict_types=1);

namespace Pulsar\Live\Admin;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Domain\FieldType;

/**
 * Textarea (long text) field for admin resources.
 * @api
 */
#[Api(since: '1.0.0')]
final class TextareaField extends Field
{
    public static function make(string $name): self
    {
        return new self($name, FieldType::Text);
    }
}
