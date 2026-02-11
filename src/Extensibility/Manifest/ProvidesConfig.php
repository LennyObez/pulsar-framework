<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Manifest;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extensibility\Exception\ManifestException;

use function array_is_list;
use function is_array;
use function is_bool;

/**
 * Configuration for what an extension provides.
 */
#[Api(since: '1.0.0')]
readonly class ProvidesConfig
{
    /**
     * @param list<string> $services Service class names provided
     * @param list<string> $commands Command class names provided
     * @param bool $routes Whether the extension provides routes
     * @param list<string> $middleware Middleware class names provided
     */
    public function __construct(
        public array $services = [],
        public array $commands = [],
        public bool $routes = false,
        public array $middleware = [],
    ) {}

    /**
     * Create from manifest array data.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $services = $data['services'] ?? [];
        $commands = $data['commands'] ?? [];
        $middleware = $data['middleware'] ?? [];
        $routes = $data['routes'] ?? false;

        if (!is_array($services)) {
            throw ManifestException::invalidFieldType('provides.services', 'list', 'non-array', '');
        }

        if (!is_array($commands)) {
            throw ManifestException::invalidFieldType('provides.commands', 'list', 'non-array', '');
        }

        if (!is_array($middleware)) {
            throw ManifestException::invalidFieldType('provides.middleware', 'list', 'non-array', '');
        }

        if ($services !== [] && !array_is_list($services)) {
            throw ManifestException::invalidFieldType('provides.services', 'list', 'associative array', '');
        }
        /** @var list<string> $services */

        if ($commands !== [] && !array_is_list($commands)) {
            throw ManifestException::invalidFieldType('provides.commands', 'list', 'associative array', '');
        }
        /** @var list<string> $commands */

        if ($middleware !== [] && !array_is_list($middleware)) {
            throw ManifestException::invalidFieldType('provides.middleware', 'list', 'associative array', '');
        }
        /** @var list<string> $middleware */

        return new self(
            services: $services,
            commands: $commands,
            routes: is_bool($routes) ? $routes : false,
            middleware: $middleware,
        );
    }

    /**
     * Check if the extension provides any services.
     */
    public function hasServices(): bool
    {
        return $this->services !== [];
    }

    /**
     * Check if the extension provides any commands.
     */
    public function hasCommands(): bool
    {
        return $this->commands !== [];
    }

    /**
     * Check if the extension provides routes.
     */
    public function hasRoutes(): bool
    {
        return $this->routes;
    }

    /**
     * Check if the extension provides any middleware.
     */
    public function hasMiddleware(): bool
    {
        return $this->middleware !== [];
    }

    /**
     * Check if the extension provides anything.
     */
    public function providesAnything(): bool
    {
        return $this->hasServices()
            || $this->hasCommands()
            || $this->hasRoutes()
            || $this->hasMiddleware();
    }
}
