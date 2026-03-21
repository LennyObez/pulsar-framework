<?php

declare(strict_types=1);

namespace Pulsar\Live\Admin;

use BackedEnum;
use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Domain\FieldType;

/**
 * Select/dropdown field for admin resources.
 *
 * Supports plain string options or PHP backed enums:
 *   SelectField::make('status')->options(Status::cases())
 *   SelectField::make('category')->options(['tech', 'science', 'art'])
 * @api
 */
#[Api(since: '1.0.0')]
final class SelectField extends Field
{
    public static function make(string $name): self
    {
        $field = new self($name, FieldType::Enum);

        return $field;
    }

    /**
     * @param list<string>|list<BackedEnum> $options
     */
    public function options(array $options): self
    {
        $this->enumValues = array_map(
            static fn(string|BackedEnum $opt): string => $opt instanceof BackedEnum ? (string) $opt->value : $opt,
            $options,
        );

        return $this;
    }
}
