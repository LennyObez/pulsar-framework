<?php

declare(strict_types=1);

namespace Pulsar\Live\Admin;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Domain\FieldType;

/**
 * Numeric (integer or float) field for admin resources.
 * @api
 */
#[Api(since: '1.0.0')]
final class NumberField extends Field
{
    public static function make(string $name, bool $float = false): self
    {
        return new self($name, $float ? FieldType::Float : FieldType::Integer);
    }
}
