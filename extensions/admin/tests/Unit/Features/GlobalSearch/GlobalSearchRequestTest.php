<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\GlobalSearch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchRequest;

#[CoversClass(GlobalSearchRequest::class)]
final class GlobalSearchRequestTest extends TestCase
{
    #[Test]
    public function constructorSetsQuery(): void
    {
        $request = new GlobalSearchRequest(query: 'john doe');

        self::assertSame('john doe', $request->query);
        self::assertSame(5, $request->limitPerResource);
    }

    #[Test]
    public function constructorWithCustomLimit(): void
    {
        $request = new GlobalSearchRequest(query: 'test', limitPerResource: 10);

        self::assertSame('test', $request->query);
        self::assertSame(10, $request->limitPerResource);
    }
}
