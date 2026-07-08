<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\ListResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceRequest;

#[CoversClass(ListResourceRequest::class)]
final class ListResourceRequestTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $request = new ListResourceRequest(
            resourceName: 'users',
            filters: ['status' => 'active'],
            sort: ['name' => 'asc'],
            page: 2,
            perPage: 50,
        );

        self::assertSame('users', $request->resourceName);
        self::assertSame(['status' => 'active'], $request->filters);
        self::assertSame(['name' => 'asc'], $request->sort);
        self::assertSame(2, $request->page);
        self::assertSame(50, $request->perPage);
    }

    #[Test]
    public function defaultsForOptionalProperties(): void
    {
        $request = new ListResourceRequest(resourceName: 'orders');

        self::assertSame([], $request->filters);
        self::assertSame([], $request->sort);
        self::assertSame(1, $request->page);
        self::assertSame(25, $request->perPage);
    }
}
