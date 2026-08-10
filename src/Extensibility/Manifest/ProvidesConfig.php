<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Manifest;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extensibility\Exception\ManifestException;

use function array_filter;
use function array_is_list;
use function array_values;
use function is_array;
use function is_bool;

/**
 * Configuration for what an extension provides.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ProvidesConfig
{
    /**
     * @param list<string> $services Service class names provided
     * @param list<string> $commands Command class names provided
     * @param bool $routes Whether the extension contributes HTTP routes.
     *        This field is **declarative metadata** that tooling
     *        (route-cache compiler, dependency graph, documentation
     *        generator) uses to decide whether to scan the extension's
     *        `routes/` directory at build / boot time. It does NOT
     *        prevent an extension whose manifest sets `routes: false`
     *        from calling `Router::get()` at runtime — that would
     *        require hooking every Router write through the registry,
     *        which the architecture does not do. Treat the field as a
     *        contract the operator self-attests to: setting it to
     *        `false` while the extension does register routes is a
     *        manifest bug, not a runtime safeguard.
     * @param list<string> $middleware Middleware class names provided
     * @param list<string> $migrations Relative paths to migration directories
     */
    public function __construct(
        public array $services = [],
        public array $commands = [],
        public bool $routes = false,
        public array $middleware = [],
        public array $migrations = [],
    ) {}

    /**
     * Create from manifest array data.
     *
     * Typed loosely because the input is the `provides` section of an
     * untrusted pulsar.json on disk; each list is validated below.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $services = $data['services'] ?? [];
        $commands = $data['commands'] ?? [];
        $middleware = $data['middleware'] ?? [];
        $migrations = $data['migrations'] ?? [];

        if (!is_array($services)) {
            throw ManifestException::invalidFieldType('provides.services', 'list', 'non-array', '');
        }

        if (!is_array($commands)) {
            throw ManifestException::invalidFieldType('provides.commands', 'list', 'non-array', '');
        }

        if (!is_array($middleware)) {
            throw ManifestException::invalidFieldType('provides.middleware', 'list', 'non-array', '');
        }

        if (!is_array($migrations)) {
            throw ManifestException::invalidFieldType('provides.migrations', 'list', 'non-array', '');
        }

        if ($services !== [] && !array_is_list($services)) {
            throw ManifestException::invalidFieldType('provides.services', 'list', 'associative array', '');
        }

        if ($commands !== [] && !array_is_list($commands)) {
            throw ManifestException::invalidFieldType('provides.commands', 'list', 'associative array', '');
        }

        if ($middleware !== [] && !array_is_list($middleware)) {
            throw ManifestException::invalidFieldType('provides.middleware', 'list', 'associative array', '');
        }

        if ($migrations !== [] && !array_is_list($migrations)) {
            throw ManifestException::invalidFieldType('provides.migrations', 'list', 'associative array', '');
        }

        $services = array_values(array_filter($services, 'is_string'));
        $commands = array_values(array_filter($commands, 'is_string'));
        $middleware = array_values(array_filter($middleware, 'is_string'));
        $migrations = array_values(array_filter($migrations, 'is_string'));
        /** @var mixed $routes */
        $routes = $data['routes'] ?? false;

        return new self(
            services: $services,
            commands: $commands,
            routes: is_bool($routes) ? $routes : false,
            middleware: $middleware,
            migrations: $migrations,
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
     * Check if the extension provides migrations.
     */
    public function hasMigrations(): bool
    {
        return $this->migrations !== [];
    }

    /**
     * Check if the extension provides anything.
     */
    public function providesAnything(): bool
    {
        return $this->hasServices()
            || $this->hasCommands()
            || $this->hasRoutes()
            || $this->hasMiddleware()
            || $this->hasMigrations();
    }
}
