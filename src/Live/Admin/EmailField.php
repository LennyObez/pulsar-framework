<?php

declare(strict_types=1);

namespace Pulsar\Live\Admin;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ValidationRule;

/**
 * Email field for admin resources. Adds email validation automatically.
 * @api
 */
#[Api(since: '1.0.0')]
final class EmailField extends Field
{
    public static function make(string $name): self
    {
        $field = new self($name, FieldType::Email);
        $field->rules[] = new ValidationRule(rule: 'email');

        return $field;
    }

    public function unique(?string $message = null): self
    {
        // Unique constraint is enforced at the database level;
        // this is a hint for the admin panel to show appropriate UI feedback.
        $this->helpTextContent = $message ?? 'Must be unique';

        return $this;
    }
}
