<?php

declare(strict_types=1);

namespace Pulsar\Benchmark\Memory;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Container\Container;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Hydration\EntityHydrator;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Method;
use Pulsar\Routing\Router;
use RuntimeException;
use stdClass;

use function memory_get_peak_usage;
use function memory_get_usage;

/**
 * Memory profiling benchmarks for common framework operations.
 *
 * Tracks memory allocation patterns for routing, DI resolution,
 * entity hydration, and response construction.
 *
 * These benchmarks run with 1 rev and few iterations because
 * memory measurements are deterministic and do not benefit from
 * statistical sampling the way timing benchmarks do.
 */
#[BeforeMethods('setUp')]
#[Revs(1)]
#[Iterations(3)]
#[Warmup(0)]
final class MemoryProfileBench
{
    private Router $router;

    public function setUp(): void
    {
        $this->router = new Router();

        // Register 200 routes (mix of static and dynamic)
        for ($i = 0; $i < 100; $i++) {
            $this->router->get("/api/v1/resource-$i", fn() => null, "resource.$i");
        }

        for ($i = 0; $i < 100; $i++) {
            $this->router->get("/api/v1/dynamic-$i/{id}", fn() => null);
        }
    }

    /**
     * Memory cost of registering 200 routes.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 30 seconds')]
    public function benchRouteRegistrationMemory(): void
    {
        $before = memory_get_usage(true);
        $router = new Router();

        for ($i = 0; $i < 200; $i++) {
            $router->get("/route-$i/{id}", fn() => null, "route.$i");
        }

        $after = memory_get_usage(true);
        $delta = $after - $before;

        // Assert < 512 KB for 200 routes
        if ($delta > 524_288) {
            throw new RuntimeException(
                "Route registration consumed {$delta} bytes (> 512 KB budget for 200 routes)",
            );
        }
    }

    /**
     * Memory cost of matching a dynamic route in a 200-route router.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 30 seconds')]
    public function benchRouteMatchMemory(): void
    {
        $before = memory_get_usage(true);

        for ($i = 0; $i < 100; $i++) {
            $this->router->match(Method::GET, '/api/v1/dynamic-50/42');
        }

        $after = memory_get_usage(true);
        $delta = $after - $before;

        // Assert < 256 KB for 100 route matches
        if ($delta > 262_144) {
            throw new RuntimeException(
                "Route matching consumed {$delta} bytes (> 256 KB budget for 100 matches)",
            );
        }
    }

    /**
     * Memory cost of DI container with 100 bindings.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 30 seconds')]
    public function benchContainerResolutionMemory(): void
    {
        $before = memory_get_usage(true);

        $container = new Container();

        for ($i = 0; $i < 100; $i++) {
            $container->bind("service.$i", fn() => new stdClass());
        }

        // Consume each resolution: discarding it lets the engine treat the loop as
        // dead, and a benchmark that can be optimised away measures nothing.
        $resolved = 0;

        for ($i = 0; $i < 100; $i++) {
            $resolved += $container->get("service.$i") instanceof stdClass ? 1 : 0;
        }

        if ($resolved !== 100) {
            throw new RuntimeException("Container resolved {$resolved} of 100 services");
        }

        $after = memory_get_usage(true);
        $delta = $after - $before;

        // Assert < 256 KB for 100 bindings + resolutions
        if ($delta > 262_144) {
            throw new RuntimeException(
                "Container resolution consumed {$delta} bytes (> 256 KB budget for 100 services)",
            );
        }
    }

    /**
     * Memory cost of hydrating 1000 entities.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 30 seconds')]
    public function benchEntityHydrationMemory(): void
    {
        $metadata = new EntityMetadata(
            entityClass: MemoryBenchEntity::class,
            tableName: 'bench',
            schema: null,
            primaryKey: new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
            columns: [
                'id' => new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
                'name' => new ColumnMetadata('name', 'name', ColumnType::String),
                'email' => new ColumnMetadata('email', 'email', ColumnType::String),
            ],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: false,
            tenantColumn: null,
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: [],
        );

        $registry = new class ($metadata) implements MetadataRegistryInterface {
            public function __construct(private readonly EntityMetadata $metadata) {}

            public function get(string $entityClass): EntityMetadata
            {
                return $this->metadata;
            }

            public function has(string $entityClass): bool
            {
                return true;
            }
        };

        $hydrator = new EntityHydrator($registry);

        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = new Row([
                'id' => $i + 1,
                'name' => "User $i",
                'email' => "user$i@example.com",
            ]);
        }

        $before = memory_get_usage(true);
        $entities = $hydrator->hydrateAll(MemoryBenchEntity::class, $rows);
        $after = memory_get_usage(true);
        $delta = $after - $before;

        // Assert < 1 MB for 1000 entities
        if ($delta > 1_048_576) {
            throw new RuntimeException(
                "Entity hydration consumed {$delta} bytes (> 1 MB budget for 1000 entities)",
            );
        }

        // Prevent dead-code elimination
        if ($entities === []) {
            throw new RuntimeException('Unexpected empty result');
        }
    }

    /**
     * Memory cost of constructing 100 JSON responses.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 30 seconds')]
    public function benchJsonResponseMemory(): void
    {
        $data = [
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'roles' => ['admin', 'editor', 'viewer'],
            'metadata' => ['created' => '2026-01-01', 'updated' => '2026-03-01'],
        ];

        $before = memory_get_usage(true);

        $responses = [];
        for ($i = 0; $i < 100; $i++) {
            $responses[] = Response::json($data);
        }

        $after = memory_get_usage(true);
        $delta = $after - $before;

        // Assert < 512 KB for 100 JSON responses
        if ($delta > 524_288) {
            throw new RuntimeException(
                "JSON response construction consumed {$delta} bytes (> 512 KB budget for 100 responses)",
            );
        }

        // Prevent DCE. Comparing against [] cannot serve: the analyser proves the
        // list non-empty, so the guard was already dead code. Touching each response
        // is a check it cannot fold away.
        $bytes = 0;

        foreach ($responses as $response) {
            $bytes += $response->getBody()->getSize() ?? 0;
        }

        if ($bytes === 0) {
            throw new RuntimeException('Unexpected empty result');
        }
    }

    /**
     * Peak memory after full request simulation.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 30 seconds')]
    public function benchPeakMemoryFullCycle(): void
    {
        $peakBefore = memory_get_peak_usage(true);

        // Simulate: route match + container resolution + response
        $router = new Router();
        for ($i = 0; $i < 50; $i++) {
            $router->get("/api/v1/users/{id}/posts/$i", fn() => null);
        }

        $router->match(Method::GET, '/api/v1/users/42/posts/25');

        $container = new Container();
        $resolved = 0;

        for ($i = 0; $i < 20; $i++) {
            $container->bind("svc.$i", fn() => new stdClass());
            $resolved += $container->get("svc.$i") instanceof stdClass ? 1 : 0;
        }

        if ($resolved !== 20) {
            throw new RuntimeException("Container resolved {$resolved} of 20 services");
        }

        $response = Response::json(['status' => 'ok', 'data' => range(1, 50)]);
        $_ = (string) $response->getBody();

        $peakAfter = memory_get_peak_usage(true);
        $delta = $peakAfter - $peakBefore;

        // Full request cycle should stay under 2 MB peak growth
        if ($delta > 2_097_152) {
            throw new RuntimeException(
                "Full cycle peak growth: {$delta} bytes (> 2 MB budget)",
            );
        }
    }
}

/**
 * @internal Benchmark-only entity
 */
final class MemoryBenchEntity
{
    public function __construct(
        public readonly int $id = 0,
        public readonly string $name = '',
        public readonly string $email = '',
    ) {}
}
