<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Cache\RouteCache;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Routing\RouterInterface;
use Throwable;

use function sprintf;

/**
 * Compile routes to a cached file for production.
 *
 * Serializes all registered routes through the RouteCache, skipping
 * closure-based handlers that cannot be serialized. Run this during
 * deployment to eliminate route registration overhead on every request.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class RoutesCacheCommand extends Command
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly RouteCache $cache,
        private readonly string $cachePath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'routes:cache';
        $this->description = 'Compile routes to a cached file for production';

        $this->addOption('encrypt', 'Encrypt the cache file', 'e');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = $this->router->routes();

        if ($routes === []) {
            $output->warning('No routes registered; nothing to cache.');

            return ExitCode::Success->value;
        }

        $encrypt = $input->hasOption('encrypt');

        try {
            $result = $this->cache->write($this->cachePath, $routes, $encrypt);
        } catch (Throwable $e) {
            $output->errorln(sprintf('Failed to cache routes: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        $output->success(sprintf(
            'Routes cached: %d compiled, %d skipped (closure-based).',
            $result['cached'],
            $result['skipped'],
        ));

        if ($result['skippedRoutes'] !== []) {
            $output->newLine();
            $output->warning('Skipped routes (closure handlers cannot be cached):');

            foreach ($result['skippedRoutes'] as $path) {
                $output->writeln(sprintf('  %s', $path));
            }
        }

        return ExitCode::Success->value;
    }
}
