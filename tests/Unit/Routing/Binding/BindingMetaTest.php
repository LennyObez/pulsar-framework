<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\Binding\BindingMeta;
use ReflectionClass;
use stdClass;

#[CoversClass(BindingMeta::class)]
final class BindingMetaTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $meta = new BindingMeta(class: stdClass::class);

        self::assertSame(stdClass::class, $meta->class);
        self::assertSame('id', $meta->keyName);
        self::assertSame('int', $meta->keyType);
        self::assertFalse($meta->scoped);
        self::assertNull($meta->parentRelation);
        self::assertNull($meta->authzPolicy);
        self::assertNull($meta->customResolver);
    }

    #[Test]
    public function allValuesCanBeSet(): void
    {
        $meta = new BindingMeta(
            class: stdClass::class,
            keyName: 'uuid',
            keyType: 'string',
            scoped: true,
            parentRelation: 'posts',
            authzPolicy: 'post.view',
            customResolver: stdClass::class,
        );

        self::assertSame(stdClass::class, $meta->class);
        self::assertSame('uuid', $meta->keyName);
        self::assertSame('string', $meta->keyType);
        self::assertTrue($meta->scoped);
        self::assertSame('posts', $meta->parentRelation);
        self::assertSame('post.view', $meta->authzPolicy);
        self::assertSame(stdClass::class, $meta->customResolver);
    }

    #[Test]
    public function isReadonly(): void
    {
        $meta = new BindingMeta(class: stdClass::class);

        $reflection = new ReflectionClass($meta);

        self::assertTrue($reflection->isReadonly());
    }
}
