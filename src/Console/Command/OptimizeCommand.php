<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function assert;

use Closure;

use function count;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Cache\CacheException;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Config\ConfigRepository;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Container\Container;
use Pulsar\Core\Kernel;
use Pulsar\Routing\Router;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use SodiumException;

use function sprintf;

use Throwable;

/**
 * Writes all framework caches + manifest.
 *
 * Usage: optimize [--strict] [--encrypt]
 */
#[Internal]
final class OptimizeCommand extends Command
{
    public function __construct(
        private readonly Kernel $kernel,
        private readonly FrameworkCache $frameworkCache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'optimize';
        $this->description = 'Cache configuration, routes, and container for production';
        $this->addOption('strict', 'Fail if any closure-based route is detected', 's');
        $this->addOption('encrypt', 'Encrypt cache payloads at rest', 'e');
    }

    /**
     * @throws CacheException If cache write operations fail
     * @throws JsonException If JSON serialization fails during caching
     * @throws ReflectionException If class reflection fails during container caching
     * @throws SodiumException If a sodium cryptographic operation fails during cache signing
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $strict = $input->hasOption('strict');

        $output->writeln('Optimizing framework...');

        $repository = $this->getConfigRepository();

        if ($repository === null) {
            $output->writeln('  No configuration loaded. Nothing to cache.');

            return ExitCode::Success->value;
        }

        $routes = $this->kernel->container()->has(Router::class)
            ? $this->kernel->container()->get(Router::class)->routes
            : [];

        // Strict mode check: fail on closure routes
        if ($strict) {
            $closureRoutes = [];
            foreach ($routes as $route) {
                if ($route->handler instanceof Closure) {
                    $closureRoutes[] = $route->path;
                }
            }

            if ($closureRoutes !== []) {
                $output->writeln();
                $output->writeln('  Strict mode: all routes must use class-based handlers for caching.');
                $output->writeln(sprintf('  Found %d closure-based route(s):', count($closureRoutes)));

                foreach ($closureRoutes as $path) {
                    $output->writeln(sprintf('    - %s', $path));
                }

                $output->writeln();
                $output->writeln('  Convert all handlers to class-based or run without --strict.');

                return ExitCode::Error->value;
            }
        }

        $containerHints = $this->buildContainerHints();
        $appEnv = $this->getAppEnv();

        $result = $this->frameworkCache->warm($repository, $routes, $containerHints, $appEnv, $strict);

        $output->writeln();
        $output->writeln('  Config: cached');
        $output->writeln(sprintf('  Routes: %d cached, %d skipped', $result['routesCached'], $result['routesSkipped']));

        if ($result['skippedRoutes'] !== []) {
            foreach ($result['skippedRoutes'] as $skipped) {
                $output->writeln(sprintf('    - %s (closure handler)', $skipped));
            }
            $output->writeln();
            $output->writeln('  For best production performance, use --strict and convert all handlers to class-based.');
        }

        $output->writeln('  Container: cached');
        $output->writeln();
        $output->writeln('Framework optimized successfully.');

        return ExitCode::Success->value;
    }

    private function getConfigRepository(): ?ConfigRepository
    {
        $configManager = $this->kernel->configManager();

        return $configManager?->repository();
    }

    private function getAppEnv(): string
    {
        $configManager = $this->kernel->configManager();

        if ($configManager === null) {
            return 'production';
        }

        try {
            $env = $configManager->environment();

            return $env->get('APP_ENV') ?? 'production';
        } catch (Throwable) {
            return 'production';
        }
    }

    /**
     * Build container resolution hints by scanning bindings for constructor parameters.
     *
     * @return array<class-string, list<array{name: string, type: class-string}>>
     */
    private function buildContainerHints(): array
    {
        $container = $this->kernel->container();
        assert($container instanceof Container);

        $hints = [];

        foreach ($container->getBindings() as $id) {
            if (!class_exists($id)) {
                continue;
            }

            $ref = new ReflectionClass($id);
            $constructor = $ref->getConstructor();

            if ($constructor === null) {
                continue;
            }

            $params = [];
            $skip = false;

            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();

                // Skip if no named type, union/intersection, builtin, variadic, or self/static/parent
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || $param->isVariadic()) {
                    $skip = true;
                    break;
                }

                $typeName = $type->getName();
                /** @psalm-suppress TypeDoesNotContainType — getName() returns class-string but can be 'self'/'static'/'parent' at runtime */
                if ($typeName === 'self' || $typeName === 'static' || $typeName === 'parent') {
                    $skip = true;
                    break;
                }

                /** @var class-string $typeName */
                $params[] = ['name' => $param->getName(), 'type' => $typeName];
            }

            if (!$skip && $params !== []) {
                /** @var class-string $id */
                $hints[$id] = $params;
            }
        }

        return $hints;
    }
}
