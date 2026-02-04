<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\RouteContext;

#[CoversClass(RouteContext::class)]
final class RouteContextTest extends TestCase
{
    #[Test]
    public function labelReturnsNameWhenSet(): void
    {
        $ctx = new RouteContext();
        $ctx->name = 'users.show';
        $ctx->pattern = '/users/{id}';

        self::assertSame('users.show', $ctx->label());
    }

    #[Test]
    public function labelReturnsPatternWhenNameIsNull(): void
    {
        $ctx = new RouteContext();
        $ctx->pattern = '/users/{id}';

        self::assertSame('/users/{id}', $ctx->label());
    }

    #[Test]
    public function labelReturnsUnmatchedWhenBothNull(): void
    {
        $ctx = new RouteContext();

        self::assertSame('unmatched', $ctx->label());
    }

    #[Test]
    public function resetClearsBothFields(): void
    {
        $ctx = new RouteContext();
        $ctx->name = 'users.show';
        $ctx->pattern = '/users/{id}';

        $ctx->reset();

        self::assertNull($ctx->pattern);
        self::assertNull($ctx->name);
        self::assertSame('unmatched', $ctx->label());
    }
}
