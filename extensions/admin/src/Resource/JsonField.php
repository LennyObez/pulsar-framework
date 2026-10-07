<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Resource;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Domain\FieldType;

/**
 * JSON field for admin resources. Renders as a code editor in the form.
 * @api
 */
#[Api(since: '1.0.0')]
final class JsonField extends Field
{
    public static function make(string $name): self
    {
        return new self($name, FieldType::Json);
    }
}
