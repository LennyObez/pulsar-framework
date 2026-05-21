<?php

declare(strict_types=1);

namespace Pulsar\Live;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a property as a tracked live property.
 *
 * Live properties are automatically serialized to the frontend
 * and deserialized back when the component is re-rendered.
 *
 * Usage:
 *   #[LiveProp]
 *   public string $name = '';
 *
 *   #[LiveProp(writable: true)]
 *   public int $count = 0;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class LiveProp
{
    /**
     * @param bool $writable Whether this property can be updated from the frontend
     * @param string $fieldName Custom wire:model field name (defaults to property name)
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public bool $writable = false,
        public string $fieldName = '',
    ) {}
}
