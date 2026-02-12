<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\TableFormatter;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\KernelInterface;

use function array_map;
use function count;
use function end;
use function explode;
use function implode;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function sprintf;
use function str_contains;
use function strtoupper;

/**
 * Lists registered API endpoints with their documentation metadata.
 *
 * Usage: api:routes [--method=GET] [--path=/api] [--tag=users]
 */
final class ApiRoutesCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'api:routes';
        $this->description = 'List registered API endpoints';
        $this->addOption('method', 'Filter by HTTP method', 'm');
        $this->addOption('path', 'Filter by path pattern', 'p');
        $this->addOption('tag', 'Filter by documentation tag', 't');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->kernel->boot();

        $routes = $this->kernel->router()->routes();

        if ($routes === []) {
            $output->writeln('No routes registered.');
            return ExitCode::Success->value;
        }

        $methodFilter = $input->getOption('method');
        $pathFilter = $input->getOption('path');

        $table = new TableFormatter();
        $table->setHeaders(['Method', 'Path', 'Name', 'Handler', 'Middleware']);

        $displayed = 0;
        foreach ($routes as $route) {
            $methods = implode('|', array_map(static fn($m) => $m->value, $route->methods));

            if (is_string($methodFilter) && !str_contains(strtoupper($methods), strtoupper($methodFilter))) {
                continue;
            }

            if (is_string($pathFilter) && !str_contains($route->path, $pathFilter)) {
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
            $displayed++;
        }

        if ($displayed === 0) {
            $output->writeln('No routes match the filter criteria.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('API Routes (%d):', $displayed));
        $output->newLine();
        $table->render($output);

        return ExitCode::Success->value;
    }

    /**
     * @param array{0: class-string, 1: string}|callable|class-string $handler
     */
    private function formatHandler(array|string|callable $handler): string
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

        return 'unknown';
    }

    /**
     * @param list<string> $middleware
     */
    private function formatMiddleware(array $middleware): string
    {
        if ($middleware === []) {
            return '-';
        }

        $names = array_map(static function (string $m): string {
            $parts = explode('\\', $m);
            return end($parts);
        }, $middleware);

        return implode(', ', $names);
    }
}
