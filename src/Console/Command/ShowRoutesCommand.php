<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function count;
use function gettype;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;

use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\TableFormatter;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\Kernel;

use function sprintf;

/**
 * Displays registered routes in table format.
 */
final class ShowRoutesCommand extends Command
{
    public function __construct(
        private readonly Kernel $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('show:routes')
            ->setDescription('Display all registered routes')
            ->addOption('method', 'Filter by HTTP method', 'm')
            ->addOption('path', 'Filter by path pattern', 'p');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->kernel->boot();

        $routes = $this->kernel->router()->getRoutes();

        if ($routes === []) {
            $output->writeln('No routes registered.');
            return ExitCode::Success->value;
        }

        $methodFilter = $input->getOption('method');
        $pathFilter = $input->getOption('path');

        $table = new TableFormatter();
        $table->setHeaders(['Method', 'Path', 'Name', 'Handler', 'Middleware']);

        $count = 0;
        foreach ($routes as $route) {
            $methods = implode('|', array_map(fn($m) => $m->value, $route->methods));

            // Apply filters
            if ($methodFilter !== null && is_string($methodFilter) && !str_contains(strtoupper($methods), strtoupper($methodFilter))) {
                continue;
            }

            if ($pathFilter !== null && is_string($pathFilter) && !str_contains($route->path, $pathFilter)) {
                continue;
            }

            $handler = $this->formatHandler($route->handler);
            $middleware = $this->formatMiddleware($route->middleware);

            $table->addRow([
                $methods,
                $route->path,
                $route->name ?? '-',
                $handler,
                $middleware,
            ]);
            $count++;
        }

        if ($count === 0) {
            $output->writeln('No routes match the filter criteria.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Routes (%d):', $count));
        $output->newLine();
        $table->render($output);

        return ExitCode::Success->value;
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
     * @param array<mixed> $middleware
     */
    private function formatMiddleware(array $middleware): string
    {
        if ($middleware === []) {
            return '-';
        }

        $names = array_map(function ($m) {
            if (is_string($m)) {
                // Get short class name
                $parts = explode('\\', $m);
                return end($parts);
            }
            return 'Closure';
        }, $middleware);

        return implode(', ', $names);
    }
}
