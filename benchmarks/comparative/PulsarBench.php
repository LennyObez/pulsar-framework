<?php

declare(strict_types=1);

namespace Pulsar\Benchmark;

use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Method;
use Pulsar\Routing\CompiledRouteTree;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteCompiler;
use Pulsar\Routing\Router;
use Pulsar\View\Engine\TemplateEngine;

use function hrtime;
use function json_encode;
use function memory_get_peak_usage;
use function number_format;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Comparative benchmark suite for Pulsar framework.
 *
 * Runs reproducible benchmarks measuring:
 *   - Hello World (minimal overhead)
 *   - JSON API response construction
 *   - Routing (static + dynamic, compiled + dynamic)
 *   - Template rendering
 *
 * Usage:
 *   php benchmarks/comparative/PulsarBench.php [--iterations=10000] [--warmup=100] [--json]
 */
final class PulsarBench
{
    private int $iterations;
    private int $warmup;
    private bool $jsonOutput;

    /** @var array<string, array{ops_per_sec: float, avg_ns: float, p50_ns: float, p95_ns: float, p99_ns: float, memory_bytes: int}> */
    private array $results = [];

    public function __construct(int $iterations = 10_000, int $warmup = 100, bool $jsonOutput = false)
    {
        $this->iterations = $iterations;
        $this->warmup = $warmup;
        $this->jsonOutput = $jsonOutput;
    }

    public function run(): void
    {
        $this->benchHelloWorld();
        $this->benchJsonResponse();
        $this->benchStaticRouteMatch();
        $this->benchDynamicRouteMatch();
        $this->benchCompiledRouteMatch();
        $this->benchRouteCompilation();

        if ($this->jsonOutput) {
            echo json_encode($this->results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } else {
            $this->printResults();
        }
    }

    private function benchHelloWorld(): void
    {
        $this->benchmark('hello_world', function (): void {
            $response = new Response(
                statusCode: 200,
                body: 'Hello, World!',
            );

            // Simulate reading the response
            $_ = (string) $response->getBody();
        });
    }

    private function benchJsonResponse(): void
    {
        $data = [
            'id' => 1,
            'name' => 'Benchmark User',
            'email' => 'bench@pulsar.dev',
            'roles' => ['admin', 'editor'],
            'metadata' => ['last_login' => '2026-01-01T00:00:00Z'],
        ];

        $this->benchmark('json_response', function () use ($data): void {
            $response = Response::json($data);
            $_ = (string) $response->getBody();
        });
    }

    private function benchStaticRouteMatch(): void
    {
        $router = new Router();

        // Register 100 static routes
        for ($i = 0; $i < 100; $i++) {
            $router->get("/api/v1/resource-{$i}", 'Controller::index', "resource.{$i}");
        }

        $router->get('/api/v1/target', 'Controller::target', 'target');

        $this->benchmark('static_route_match', function () use ($router): void {
            $router->match(Method::GET, '/api/v1/target');
        });
    }

    private function benchDynamicRouteMatch(): void
    {
        $router = new Router();

        for ($i = 0; $i < 50; $i++) {
            $router->get("/api/v1/resource-{$i}/{id}", 'Controller::show');
        }

        $router->get('/api/v1/users/{id}', 'Controller::showUser', 'user.show');

        $this->benchmark('dynamic_route_match', function () use ($router): void {
            $router->match(Method::GET, '/api/v1/users/42');
        });
    }

    private function benchCompiledRouteMatch(): void
    {
        $router = new Router();

        // Mix of static and dynamic routes
        for ($i = 0; $i < 50; $i++) {
            $router->get("/api/v1/static-{$i}", 'Controller::index');
        }

        for ($i = 0; $i < 50; $i++) {
            $router->get("/api/v1/dynamic-{$i}/{id}", 'Controller::show');
        }

        $router->get('/api/v1/users/{id}', 'Controller::showUser', 'user.show');

        $compiler = new RouteCompiler();
        $tree = $compiler->compile($router->routes);

        // Benchmark static match on compiled tree
        $this->benchmark('compiled_static_match', function () use ($tree): void {
            $tree->match(Method::GET, '/api/v1/static-25');
        });

        // Benchmark dynamic match on compiled tree
        $this->benchmark('compiled_dynamic_match', function () use ($tree): void {
            $tree->match(Method::GET, '/api/v1/users/42');
        });
    }

    private function benchRouteCompilation(): void
    {
        $routes = [];

        for ($i = 0; $i < 100; $i++) {
            $routes[] = Route::get("/api/v1/resource-{$i}", 'Controller::index', "r.{$i}");
        }

        for ($i = 0; $i < 50; $i++) {
            $routes[] = Route::get("/api/v1/dynamic-{$i}/{id}", 'Controller::show');
        }

        $compiler = new RouteCompiler();

        $this->benchmark('route_compilation', function () use ($compiler, $routes): void {
            $compiler->compile($routes);
        });
    }

    /**
     * @param callable(): void $fn
     */
    private function benchmark(string $name, callable $fn): void
    {
        // Warmup phase
        for ($i = 0; $i < $this->warmup; $i++) {
            $fn();
        }

        // Measurement phase
        /** @var list<int> $timings */
        $timings = [];
        $memBefore = memory_get_peak_usage(true);

        for ($i = 0; $i < $this->iterations; $i++) {
            $start = hrtime(true);
            $fn();
            $timings[] = hrtime(true) - $start;
        }

        $memAfter = memory_get_peak_usage(true);

        sort($timings);

        $totalNs = array_sum($timings);
        $avgNs = $totalNs / $this->iterations;
        $opsPerSec = 1_000_000_000 / $avgNs;

        $this->results[$name] = [
            'ops_per_sec' => round($opsPerSec, 1),
            'avg_ns' => round($avgNs, 1),
            'p50_ns' => (float) $timings[(int) ($this->iterations * 0.50)],
            'p95_ns' => (float) $timings[(int) ($this->iterations * 0.95)],
            'p99_ns' => (float) $timings[(int) ($this->iterations * 0.99)],
            'memory_bytes' => $memAfter - $memBefore,
        ];
    }

    private function printResults(): void
    {
        echo PHP_EOL;
        echo "Pulsar Framework Benchmarks" . PHP_EOL;
        echo str_repeat('=', 80) . PHP_EOL;
        echo sprintf(
            "%-30s %12s %10s %10s %10s %8s" . PHP_EOL,
            'Benchmark',
            'ops/sec',
            'avg (ns)',
            'p50 (ns)',
            'p95 (ns)',
            'mem',
        );
        echo str_repeat('-', 80) . PHP_EOL;

        foreach ($this->results as $name => $result) {
            echo sprintf(
                "%-30s %12s %10s %10s %10s %8s" . PHP_EOL,
                $name,
                number_format($result['ops_per_sec']),
                number_format($result['avg_ns']),
                number_format($result['p50_ns']),
                number_format($result['p95_ns']),
                $this->formatBytes((int) $result['memory_bytes']),
            );
        }

        echo str_repeat('=', 80) . PHP_EOL;
        echo sprintf("Iterations: %s, Warmup: %s" . PHP_EOL, number_format($this->iterations), number_format($this->warmup));
        echo PHP_EOL;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . 'KB';
        }

        return round($bytes / (1024 * 1024), 1) . 'MB';
    }
}

// --- CLI entry point ---
$options = getopt('', ['iterations:', 'warmup:', 'json']);

$bench = new PulsarBench(
    iterations: (int) ($options['iterations'] ?? 10_000),
    warmup: (int) ($options['warmup'] ?? 100),
    jsonOutput: isset($options['json']),
);

$bench->run();
