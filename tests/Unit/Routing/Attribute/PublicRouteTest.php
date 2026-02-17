<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Attribute;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\Attribute\PublicRoute;
use ReflectionClass;

#[CoversClass(PublicRoute::class)]
final class PublicRouteTest extends TestCase
{
    #[Test]
    public function constructWithDefaultReason(): void
    {
        $attr = new PublicRoute();

        self::assertSame('', $attr->reason);
    }

    #[Test]
    public function constructWithExplicitReason(): void
    {
        $attr = new PublicRoute(reason: 'Health check endpoint — no auth needed');

        self::assertSame('Health check endpoint — no auth needed', $attr->reason);
    }

    #[Test]
    public function attributeTargetsMethodAndClass(): void
    {
        $ref = new ReflectionClass(PublicRoute::class);
        $attrs = $ref->getAttributes(Attribute::class);

        self::assertNotEmpty($attrs);

        $attrInstance = $attrs[0]->newInstance();
        self::assertSame(
            Attribute::TARGET_METHOD | Attribute::TARGET_CLASS,
            $attrInstance->flags,
        );
    }

    #[Test]
    public function attributeIsReadonly(): void
    {
        $ref = new ReflectionClass(PublicRoute::class);

        self::assertTrue($ref->isReadonly());
    }
}
