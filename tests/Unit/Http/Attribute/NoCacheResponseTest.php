<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Attribute;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Attribute\NoCacheResponse;
use ReflectionClass;

#[CoversClass(NoCacheResponse::class)]
final class NoCacheResponseTest extends TestCase
{
    #[Test]
    public function isInstantiable(): void
    {
        $attr = new NoCacheResponse();

        self::assertInstanceOf(NoCacheResponse::class, $attr);
    }

    #[Test]
    public function targetsMethodAndClass(): void
    {
        $ref = new ReflectionClass(NoCacheResponse::class);
        $attributes = $ref->getAttributes(Attribute::class);

        self::assertCount(1, $attributes);

        $attrInstance = $attributes[0]->newInstance();
        self::assertSame(
            Attribute::TARGET_METHOD | Attribute::TARGET_CLASS,
            $attrInstance->flags,
        );
    }
}
