<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Manifest;

/**
 * Configuration for what an extension provides.
 */
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
     * @param array{services?: list<string>, commands?: list<string>, routes?: bool, middleware?: list<string>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            services: $data['services'] ?? [],
            commands: $data['commands'] ?? [],
            routes: $data['routes'] ?? false,
            middleware: $data['middleware'] ?? [],
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
