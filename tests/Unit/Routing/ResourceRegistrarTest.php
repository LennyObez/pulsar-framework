<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\ResourceRegistrar;
use Pulsar\Routing\Route;

#[CoversClass(ResourceRegistrar::class)]
final class ResourceRegistrarTest extends TestCase
{
    /**
     * @param list<Route> $routes
     * @return array<string, Route> keyed by route name
     */
    private function byName(array $routes): array
    {
        $map = [];
        foreach ($routes as $route) {
            self::assertNotNull($route->name);
            $map[$route->name] = $route;
        }

        return $map;
    }

    #[Test]
    public function resource_routes_define_the_seven_restful_actions(): void
    {
        $routes = ResourceRegistrar::resourceRoutes('photos', 'PhotoController', ['auth']);

        self::assertCount(7, $routes);
        $byName = $this->byName($routes);

        self::assertEqualsCanonicalizing(
            ['photos.index', 'photos.create', 'photos.store', 'photos.show', 'photos.edit', 'photos.update', 'photos.destroy'],
            array_keys($byName),
        );

        // Paths use the singularized parameter name.
        self::assertSame('/photos', $byName['photos.index']->path);
        self::assertSame('/photos/create', $byName['photos.create']->path);
        self::assertSame('/photos', $byName['photos.store']->path);
        self::assertSame('/photos/{photo}', $byName['photos.show']->path);
        self::assertSame('/photos/{photo}/edit', $byName['photos.edit']->path);
        self::assertSame('/photos/{photo}', $byName['photos.update']->path);
        self::assertSame('/photos/{photo}', $byName['photos.destroy']->path);

        // Methods + handler + middleware wired correctly on a representative subset.
        self::assertSame([Method::GET, Method::HEAD], $byName['photos.index']->methods);
        self::assertSame([Method::POST], $byName['photos.store']->methods);
        self::assertSame([Method::PUT, Method::PATCH], $byName['photos.update']->methods);
        self::assertSame([Method::DELETE], $byName['photos.destroy']->methods);
        self::assertSame(['PhotoController', 'show'], $byName['photos.show']->handler);
        self::assertSame(['auth'], $byName['photos.index']->middleware);
    }

    #[Test]
    public function api_resource_routes_omit_create_and_edit(): void
    {
        $routes = ResourceRegistrar::apiResourceRoutes('photos', 'PhotoController', []);

        self::assertCount(5, $routes);
        self::assertEqualsCanonicalizing(
            ['photos.index', 'photos.store', 'photos.show', 'photos.update', 'photos.destroy'],
            array_keys($this->byName($routes)),
        );
    }

    #[Test]
    #[DataProvider('singularizeCases')]
    public function singularize_handles_common_english_plurals(string $plural, string $expected): void
    {
        self::assertSame($expected, ResourceRegistrar::singularize($plural));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function singularizeCases(): iterable
    {
        yield 'simple -s' => ['photos', 'photo'];
        yield '-ies -> -y' => ['categories', 'category'];
        yield '-xes' => ['boxes', 'box'];
        yield '-ses' => ['buses', 'bus'];
        yield '-ches' => ['matches', 'match'];
        yield '-shes' => ['dishes', 'dish'];
        yield 'double-s preserved' => ['class', 'class'];
        yield 'nested takes last segment' => ['admin/photos', 'photo'];
        yield 'irregular unchanged' => ['data', 'data'];
    }
}
