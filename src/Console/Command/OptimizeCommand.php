<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Closure;
use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\CacheException;
use Pulsar\Cache\FrameworkCacheInterface;
use Pulsar\Config\ConfigRepository;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Event\Exception\EventException;
use Pulsar\Event\Internal\EventMapCompiler;
use Pulsar\Event\Internal\ListenerProvider;
use Pulsar\Routing\Router;
use Random\RandomException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use SodiumException;
use Throwable;

use function count;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;

/**
 * Writes all framework caches + manifest.
 *
 * Usage: optimize [--strict] [--encrypt]
 */
#[Internal]
final class OptimizeCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ?FrameworkCacheInterface $frameworkCache = null,
    ) {
        parent::__construct();
    }

    #[Override]
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
     * @throws RandomException If random byte generation fails during cache signing
     * @throws ReflectionException If class reflection fails during container caching
     * @throws SodiumException If a sodium cryptographic operation fails during cache signing
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->frameworkCache === null) {
            $output->errorln('PULSAR_MASTER_KEY is required for FrameworkCache integrity (HMAC/encryption).');
            $output->errorln('Set it via shell environment or a .env file.');
            $output->errorln('Generate one with: php bin/pulsar key:generate');

            return ExitCode::Error->value;
        }

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

        // Compile event map if ListenerProvider is available
        $this->compileEventMap($output);

        $output->writeln();
        $output->writeln('Framework optimized successfully.');

        return ExitCode::Success->value;
    }

    /**
     * Compile event listener map if the event system is registered.
     *
     * @throws EventException If event class naming lint fails
     */
    private function compileEventMap(OutputInterface $output): void
    {
        $container = $this->kernel->container();

        if (!$container->has(ListenerProvider::class)) {
            return;
        }

        /** @var ListenerProvider $provider */
        $provider = $container->get(ListenerProvider::class);

        $compiler = new EventMapCompiler();
        $compiledMap = $compiler->compile($provider);
        $eventTypeCount = count($compiledMap);

        if ($eventTypeCount === 0) {
            $output->writeln('  Events: no listeners registered');

            return;
        }

        $configManager = $this->kernel->configManager();
        $configPath = $configManager?->configPath();

        if ($configPath !== null && $this->frameworkCache !== null) {
            $mapCode = $compiler->export($compiledMap);
            $cacheDir = $configPath . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache';

            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0o755, true);
            }

            $written = file_put_contents($cacheDir . DIRECTORY_SEPARATOR . 'events_map.php', $mapCode);
            if ($written === false) {
                $output->errorln('  Events: failed to write compiled event map');

                return;
            }
        }

        $output->writeln(sprintf('  Events: %d event type(s) compiled', $eventTypeCount));
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
                /** @psalm-suppress TypeDoesNotContainType: getName() returns class-string but can be 'self'/'static'/'parent' at runtime */
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
