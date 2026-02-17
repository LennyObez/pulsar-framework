<?php

declare(strict_types=1);

namespace Pulsar\Live\Admin;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Domain\FieldType;

/**
 * Date field for admin resources.
 */
#[Api(since: '1.0.0')]
final class DateField extends Field
{
    public static function make(string $name): self
    {
        return new self($name, FieldType::Date);
    }
}
