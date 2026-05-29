<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;

use function array_keys;
use function array_map;
use function class_exists;
use function count;
use function enum_exists;
use function get_debug_type;
use function hrtime;
use function implode;
use function interface_exists;
use function is_array;
use function is_object;
use function is_scalar;
use function is_string;
use function memory_get_usage;
use function number_format;
use function sprintf;

/**
 * Interactive helper functions available in the REPL session.
 *
 * Provides `dump()`, `model()`, `route()`, `sql()`, `doc()`,
 * `bench()`, and `profile()` for developer convenience.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ReplHelpers
{
    public function __construct(
        private ContainerInterface $container,
        private ResultPrinter $printer,
        private ?SecretRedactor $redactor = null,
    ) {}

    /**
     * Enhanced var_dump: shows type, value, and structure with colors.
     */
    #[NoDiscard]
    public function dump(mixed $value): string
    {
        return $this->printer->format($value);
    }

    /**
     * Look up a route by name and display its details.
     *
     * Shows HTTP method(s), path, handler, middleware, and constraints.
     */
    #[NoDiscard]
    public function route(string $name): string
    {
        if (!$this->container->has(RouterInterface::class)) {
            return 'Router not available in container.';
        }

        /** @var RouterInterface $router */
        $router = $this->container->get(RouterInterface::class);

        foreach ($router->routes() as $route) {
            if ($route->name === $name) {
                return $this->formatRouteDetail($route);
            }
        }

        return sprintf('Route "%s" not found.', $name);
    }

    /**
     * Execute raw SQL and return results as a formatted table.
     *
     * The SQL is validated by the connection (which may be read-only in
     * safe mode). Bindings are passed as named parameters.
     *
     * @param array<string, mixed> $bindings
     */
    #[NoDiscard]
    public function sql(string $query, array $bindings = []): string
    {
        if (!$this->container->has(ConnectionInterface::class)) {
            return 'Database connection not available.';
        }

        /** @var ConnectionInterface $connection */
        $connection = $this->container->get(ConnectionInterface::class);

        try {
            $result = $connection->query($query, $bindings);
        } catch (Throwable $e) {
            return sprintf('SQL Error: %s', $e->getMessage());
        }

        if ($result->isEmpty()) {
            return '(empty result set)';
        }

        $rows = array_map(
            static fn(Row $row): array => array_map(
                static fn(mixed $v): string => match (true) {
                    $v === null => 'NULL',
                    is_scalar($v) => (string) $v,
                    default => get_debug_type($v),
                },
                $row->toArray(),
            ),
            $result->rows,
        );

        $headers = array_keys($result->rows[0]->toArray());

        /** @var list<array<string, string>> $stringRows */
        $stringRows = $rows;

        $output = $this->printer->formatTable($stringRows, $headers);

        if ($this->redactor !== null) {
            $output = $this->redactor->redactOutput($output);
        }

        return $output . "\n" . sprintf('%d row(s)', $result->rowCount);
    }

    /**
     * Show PHPDoc documentation for a class or class method.
     *
     * @param class-string $className
     */
    #[NoDiscard]
    public function doc(string $className, ?string $methodName = null): string
    {
        if (!class_exists($className) && !interface_exists($className) && !enum_exists($className)) {
            return sprintf('Class "%s" not found.', $className);
        }

        $reflection = new ReflectionClass($className);

        if ($methodName !== null) {
            return $this->formatMethodDoc($reflection, $methodName);
        }

        return $this->formatClassDoc($reflection);
    }

    /**
     * Benchmark a callable with the given number of iterations.
     *
     * Returns timing statistics: total time, average, min, max, and
     * memory delta.
     *
     * @param callable(): mixed $callback
     */
    #[NoDiscard]
    public function bench(callable $callback, int $iterations = 1000): string
    {
        if ($iterations < 1) {
            return 'Iterations must be >= 1.';
        }

        $times = [];
        $memBefore = memory_get_usage();

        // Warmup run
        $callback();

        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $callback();
            $end = hrtime(true);
            $times[] = ($end - $start) / 1_000_000; // Convert ns to ms
        }

        $memAfter = memory_get_usage();

        $total = array_sum($times);
        $avg = $total / count($times);
        $min = min($times);
        $max = max($times);
        $memDelta = $memAfter - $memBefore;

        return implode("\n", [
            sprintf('Benchmark: %s iterations', number_format($iterations)),
            sprintf('  Total:   %s ms', number_format($total, 4)),
            sprintf('  Average: %s ms', number_format($avg, 4)),
            sprintf('  Min:     %s ms', number_format($min, 4)),
            sprintf('  Max:     %s ms', number_format($max, 4)),
            sprintf('  Memory:  %s bytes delta', number_format($memDelta)),
        ]);
    }

    /**
     * Profile a single execution: time and memory usage.
     *
     * @param callable(): mixed $callback
     */
    #[NoDiscard]
    public function profile(callable $callback): string
    {
        $memBefore = memory_get_usage();
        $start = hrtime(true);

        try {
            /** @var mixed $result */
            $result = $callback();
        } catch (Throwable $e) {
            $end = hrtime(true);

            return implode("\n", [
                sprintf('Exception: %s', $e->getMessage()),
                sprintf('  Time:   %s ms', number_format(($end - $start) / 1_000_000, 4)),
                sprintf('  Memory: %s bytes', number_format(memory_get_usage() - $memBefore)),
            ]);
        }

        $end = hrtime(true);
        $memAfter = memory_get_usage();

        $timeMs = ($end - $start) / 1_000_000;
        $memDelta = $memAfter - $memBefore;

        return implode("\n", [
            sprintf('Result:  %s', $this->printer->summary($result)),
            sprintf('  Time:   %s ms', number_format($timeMs, 4)),
            sprintf('  Memory: %s bytes delta', number_format($memDelta)),
        ]);
    }

    /**
     * Get the list of available helper function names.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public static function helperNames(): array
    {
        return ['dump', 'route', 'sql', 'doc', 'bench', 'profile'];
    }

    /**
     * Format a Route object's details.
     */
    private function formatRouteDetail(Route $route): string
    {
        $methods = implode('|', array_map(
            static fn(\Pulsar\Http\Method $m): string => $m->value,
            $route->methods,
        ));

        $handler = match (true) {
            is_object($route->handler) => $route->handler::class,
            is_string($route->handler) => $route->handler,
            is_array($route->handler) => implode('::', array_map(
                static fn(mixed $v): string => is_string($v) ? $v : get_debug_type($v),
                $route->handler,
            )),
            default => get_debug_type($route->handler),
        };

        $lines = [
            sprintf('Route: %s', $route->name ?? '(unnamed)'),
            sprintf('  Methods:     %s', $methods),
            sprintf('  Path:        %s', $route->path),
            sprintf('  Handler:     %s', $handler),
        ];

        if ($route->middleware !== []) {
            $lines[] = sprintf('  Middleware:  %s', implode(', ', $route->middleware));
        }

        if ($route->constraints !== []) {
            $constraintStrs = [];

            foreach ($route->constraints as $param => $regex) {
                $constraintStrs[] = $param . '=' . $regex;
            }

            $lines[] = sprintf('  Constraints: %s', implode(', ', $constraintStrs));
        }

        if ($route->host !== null) {
            $lines[] = sprintf('  Host:        %s', $route->host);
        }

        return implode("\n", $lines);
    }

    /**
     * Format PHPDoc for a class.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function formatClassDoc(ReflectionClass $reflection): string
    {
        $lines = [sprintf('Class: %s', $reflection->getName())];

        $docComment = $reflection->getDocComment();

        if ($docComment !== false) {
            $lines[] = '';
            $lines[] = $this->cleanDocComment($docComment);
        }

        // List public methods
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        if ($methods !== []) {
            $lines[] = '';
            $lines[] = 'Public methods:';

            foreach ($methods as $method) {
                $params = [];

                foreach ($method->getParameters() as $param) {
                    $type = $param->getType();
                    $paramStr = $type !== null ? $type . ' $' . $param->getName() : '$' . $param->getName();

                    if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                        $paramStr .= ' = ' . var_export($param->getDefaultValue(), true);
                    }

                    $params[] = $paramStr;
                }

                $returnType = $method->getReturnType();
                $returnStr = $returnType !== null ? ': ' . $returnType : '';

                $lines[] = sprintf(
                    '  %s(%s)%s',
                    $method->getName(),
                    implode(', ', $params),
                    $returnStr,
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format PHPDoc for a specific method.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function formatMethodDoc(ReflectionClass $reflection, string $methodName): string
    {
        try {
            $method = $reflection->getMethod($methodName);
        } catch (ReflectionException) {
            return sprintf('Method "%s::%s" not found.', $reflection->getName(), $methodName);
        }

        $lines = [sprintf('Method: %s::%s', $reflection->getName(), $methodName)];

        // Signature
        $params = [];

        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            $paramStr = $type !== null ? $type . ' $' . $param->getName() : '$' . $param->getName();

            if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                $paramStr .= ' = ' . var_export($param->getDefaultValue(), true);
            }

            $params[] = $paramStr;
        }

        $returnType = $method->getReturnType();
        $returnStr = $returnType !== null ? ': ' . $returnType : '';

        $lines[] = sprintf('  Signature: %s(%s)%s', $methodName, implode(', ', $params), $returnStr);

        // Visibility
        $visibility = match (true) {
            $method->isPublic() => 'public',
            $method->isProtected() => 'protected',
            default => 'private',
        };

        $modifiers = [];

        if ($method->isStatic()) {
            $modifiers[] = 'static';
        }

        if ($method->isAbstract()) {
            $modifiers[] = 'abstract';
        }

        if ($method->isFinal()) {
            $modifiers[] = 'final';
        }

        $lines[] = sprintf('  Visibility: %s%s', $visibility, $modifiers !== [] ? ' (' . implode(', ', $modifiers) . ')' : '');

        // Doc comment
        $docComment = $method->getDocComment();

        if ($docComment !== false) {
            $lines[] = '';
            $lines[] = $this->cleanDocComment($docComment);
        }

        return implode("\n", $lines);
    }

    /**
     * Strip leading `* ` and `/** ... * /` markers from a doc comment.
     */
    private function cleanDocComment(string $docComment): string
    {
        $lines = explode("\n", $docComment);
        $cleaned = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Remove opening/closing markers
            if ($line === '/**' || $line === '*/') {
                continue;
            }

            // Strip leading `* `
            if (str_starts_with($line, '* ')) {
                $line = substr($line, 2);
            } elseif ($line === '*') {
                $line = '';
            }

            $cleaned[] = '  ' . $line;
        }

        return implode("\n", $cleaned);
    }
}
