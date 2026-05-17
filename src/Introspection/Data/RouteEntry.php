<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Data;

use Pulsar\Api\Api;

/**
 * Describes a single registered route (methods, path, handler, middleware).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RouteEntry
{
    /**
     * @param list<string> $methods
     * @param list<string> $middleware
     */
    public function __construct(
        public array $methods,
        public string $path,
        public string $handler,
        public ?string $name = null,
        public array $middleware = [],
    ) {}

    /**
     * @return array{methods: list<string>, path: string, handler: string, name: string|null, middleware: list<string>}
     */
    public function toArray(): array
    {
        return [
            'methods' => $this->methods,
            'path' => $this->path,
            'handler' => $this->handler,
            'name' => $this->name,
            'middleware' => $this->middleware,
        ];
    }
}
