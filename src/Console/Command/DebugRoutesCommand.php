<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\TableFormatter;
use Pulsar\Console\OutputInterface;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;

use function array_map;
use function count;
use function end;
use function explode;
use function gettype;
use function implode;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function sprintf;
use function str_contains;
use function strtoupper;

/**
 * Debug routes with full middleware stack and handler details.
 *
 * Shows more detail than show:routes, including named middleware,
 * domain constraints, and parameter bindings.
 */
#[Internal]
final class DebugRoutesCommand extends Command
{
    public function __construct(
        private readonly RouterInterface $router,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'debug:routes';
        $this->description = 'Show routes with full middleware stack and handler details';
        $this->addOption('method', 'Filter by HTTP method', 'm');
        $this->addOption('path', 'Filter by path pattern', 'p');
        $this->addOption('name', 'Filter by route name', 'n');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = $this->router->routes();

        if ($routes === []) {
            $output->writeln('No routes registered.');
            return ExitCode::Success->value;
        }

        $methodFilter = $input->getNullableStringOption('method');
        $pathFilter = $input->getNullableStringOption('path');
        $nameFilter = $input->getNullableStringOption('name');

        $filteredRoutes = $this->filterRoutes($routes, $methodFilter, $pathFilter, $nameFilter);

        if ($filteredRoutes === []) {
            $output->writeln('No routes match the filter criteria.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Routes (%d of %d):', count($filteredRoutes), count($routes)));
        $output->newLine();

        $table = new TableFormatter();
        $table->setHeaders(['Method', 'Path', 'Name', 'Handler', 'Middleware']);

        foreach ($filteredRoutes as $route) {
            $methods = implode('|', array_map(fn($m) => $m->value, $route->methods));

            $table->addRow([
                $methods,
                $route->path,
                $route->name ?? '-',
                $this->formatHandler($route->handler),
                $this->formatMiddlewareStack($route->middleware),
            ]);
        }

        $table->render($output);
        $output->newLine();

        // Summary
        $methodCounts = [];

        foreach ($filteredRoutes as $route) {
            foreach ($route->methods as $method) {
                $key = $method->value;
                $methodCounts[$key] = ($methodCounts[$key] ?? 0) + 1;
            }
        }

        $parts = [];

        foreach ($methodCounts as $method => $count) {
            $parts[] = sprintf('%s: %d', $method, $count);
        }

        $output->writeln('By method: ' . implode(', ', $parts));

        return ExitCode::Success->value;
    }

    /**
     * @param list<Route> $routes
     * @return list<Route>
     */
    private function filterRoutes(array $routes, ?string $methodFilter, ?string $pathFilter, ?string $nameFilter): array
    {
        $filtered = [];

        foreach ($routes as $route) {
            $methods = implode('|', array_map(fn($m) => $m->value, $route->methods));

            if ($methodFilter !== null && !str_contains(strtoupper($methods), strtoupper($methodFilter))) {
                continue;
            }

            if ($pathFilter !== null && !str_contains($route->path, $pathFilter)) {
                continue;
            }

            if ($nameFilter !== null && ($route->name === null || !str_contains($route->name, $nameFilter))) {
                continue;
            }

            $filtered[] = $route;
        }

        return $filtered;
    }

    private function formatHandler(mixed $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2 && isset($handler[0], $handler[1])
            && (is_string($handler[0]) || is_object($handler[0]))
            && is_string($handler[1])
        ) {
            $class = is_object($handler[0]) ? $handler[0]::class : $handler[0];
            return $class . '::' . $handler[1];
        }

        if (is_callable($handler)) {
            return 'Closure';
        }

        return gettype($handler);
    }

    /**
     * @param list<string> $middleware
     */
    private function formatMiddlewareStack(array $middleware): string
    {
        if ($middleware === []) {
            return '-';
        }

        $names = array_map(static function (string $m): string {
            $parts = explode('\\', $m);
            return end($parts);
        }, $middleware);

        return implode(' > ', $names);
    }
}
