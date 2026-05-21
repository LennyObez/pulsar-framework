<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use JsonException;
use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\TableFormatter;
use Pulsar\Console\OutputInterface;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Core\KernelInterface;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\FeatureFlag\Exception\FeatureFlagException;
use Pulsar\Routing\RoutingException;
use ReflectionException;
use SodiumException;

use function count;
use function gettype;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Displays registered routes in table format.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class ShowRoutesCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'show:routes';
        $this->description = 'Display all registered routes';
        $this->addOption('method', 'Filter by HTTP method', 'm');
        $this->addOption('path', 'Filter by path pattern', 'p');
    }

    /**
     * @throws ContainerException
     * @throws ExtensionException If extension registration or boot fails
     * @throws NotFoundException
     * @throws FeatureFlagException
     * @throws JsonException
     * @throws ReflectionException If class reflection fails during autowiring
     * @throws RoutingException If the router is locked in strict cache mode
     * @throws SodiumException If a sodium cryptographic operation fails during boot
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->kernel->boot();

        $routes = $this->kernel->router()->routes();

        if ($routes === []) {
            $output->writeln('No routes registered.');
            return ExitCode::Success->value;
        }

        $methodFilter = $input->getNullableStringOption('method');
        $pathFilter = $input->getNullableStringOption('path');

        $table = new TableFormatter();
        $table->setHeaders(['Method', 'Path', 'Name', 'Handler', 'Middleware']);

        $count = 0;
        foreach ($routes as $route) {
            $methods = implode('|', array_map(fn($m) => $m->value, $route->methods));

            // Apply filters
            if ($methodFilter !== null && !str_contains(strtoupper($methods), strtoupper($methodFilter))) {
                continue;
            }

            if ($pathFilter !== null && !str_contains($route->path, $pathFilter)) {
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
