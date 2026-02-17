<?php

declare(strict_types=1);

namespace Pulsar\Live\Admin;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Domain\FieldType;

/**
 * Relation (foreign key) field for admin resources.
 *
 * Displays as a searchable select that references another admin resource:
 *   RelationField::make('author_id')->resource('users')
 */
#[Api(since: '1.0.0')]
final class RelationField extends Field
{
    public static function make(string $name): self
    {
        return new self($name, FieldType::Relation);
    }

    /**
     * Set the related admin resource name.
     */
    public function resource(string $resourceName): self
    {
        $this->relationResource = $resourceName;

        return $this;
    }
}
