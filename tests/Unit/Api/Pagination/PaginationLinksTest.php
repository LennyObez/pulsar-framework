<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Pagination;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationLinks;

#[CoversClass(PaginationLinks::class)]
final class PaginationLinksTest extends TestCase
{
    #[Test]
    public function defaultLinksAreAllNull(): void
    {
        $links = new PaginationLinks();

        self::assertNull($links->first);
        self::assertNull($links->last);
        self::assertNull($links->next);
        self::assertNull($links->prev);
    }

    #[Test]
    public function toArrayOmitsNullLinks(): void
    {
        $links = new PaginationLinks();

        self::assertSame([], $links->toArray());
    }

    #[Test]
    public function toArrayIncludesOnlySetLinks(): void
    {
        $links = new PaginationLinks(
            first: '/api/users?page=1',
            next: '/api/users?page=3',
        );

        $array = $links->toArray();

        self::assertSame('/api/users?page=1', $array['first']);
        self::assertSame('/api/users?page=3', $array['next']);
        self::assertArrayNotHasKey('last', $array);
        self::assertArrayNotHasKey('prev', $array);
    }

    #[Test]
    public function toArrayIncludesAllLinksWhenSet(): void
    {
        $links = new PaginationLinks(
            first: '/api/items?page=1',
            last: '/api/items?page=10',
            next: '/api/items?page=4',
            prev: '/api/items?page=2',
        );

        $array = $links->toArray();

        self::assertCount(4, $array);
        self::assertSame('/api/items?page=1', $array['first']);
        self::assertSame('/api/items?page=10', $array['last']);
        self::assertSame('/api/items?page=4', $array['next']);
        self::assertSame('/api/items?page=2', $array['prev']);
    }
}
