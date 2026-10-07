<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Resource;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Domain\FieldType;

/**
 * Text (short string) field for admin resources.
 * @api
 */
#[Api(since: '1.0.0')]
final class TextField extends Field
{
    public static function make(string $name): self
    {
        return new self($name, FieldType::String);
    }
}
