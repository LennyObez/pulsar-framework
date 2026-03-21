<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Data;

use Pulsar\Api\Api;

/**
 * Schema for a single configuration property (name, type, and default value).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConfigPropertySchema
{
    public function __construct(
        public string $name,
        public string $type,
        public mixed $default = null,
    ) {}

    /**
     * @return array{name: string, type: string, default: mixed}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'default' => $this->default,
        ];
    }
}
