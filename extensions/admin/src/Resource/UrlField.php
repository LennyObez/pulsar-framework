<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Resource;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ValidationRule;

/**
 * URL field for admin resources. Adds URL validation automatically.
 * @api
 */
#[Api(since: '1.0.0')]
final class UrlField extends Field
{
    public static function make(string $name): self
    {
        $field = new self($name, FieldType::Url);
        $field->rules[] = new ValidationRule(rule: 'url');

        return $field;
    }
}
