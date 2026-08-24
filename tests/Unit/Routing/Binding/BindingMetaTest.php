<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingScope;
use Pulsar\Routing\Binding\ModelBindingException;
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
        self::assertSame(BindingScope::Path, $meta->scope);
        self::assertFalse($meta->scoped);
        self::assertNull($meta->parentRelation);
        self::assertNull($meta->parentParameter);
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
            scope: BindingScope::Contained,
            parentRelation: 'posts',
            authzPolicy: 'post.view',
            customResolver: stdClass::class,
            parentParameter: 'user',
        );

        self::assertSame(stdClass::class, $meta->class);
        self::assertSame('uuid', $meta->keyName);
        self::assertSame('string', $meta->keyType);
        self::assertSame(BindingScope::Contained, $meta->scope);
        self::assertTrue($meta->scoped);
        self::assertSame('posts', $meta->parentRelation);
        self::assertSame('user', $meta->parentParameter);
        self::assertSame('post.view', $meta->authzPolicy);
        self::assertSame(stdClass::class, $meta->customResolver);
    }

    #[Test]
    public function aContainedBindingWithoutARelationCannotBeBuilt(): void
    {
        // `scoped` used to be a bare bool, so "resolve through the parent" and
        // "through which relation?" could disagree, and the binder quietly took
        // the unscoped branch when they did.
        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        (void) new BindingMeta(class: stdClass::class, scope: BindingScope::Contained);
    }

    #[Test]
    public function anUnscopedBindingCannotCarryAParent(): void
    {
        // The other half of the invariant: a root binding with a relation
        // hanging off it reads as scoped to anything that glances at the fields
        // rather than at the scope.
        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        (void) new BindingMeta(
            class: stdClass::class,
            scope: BindingScope::Root,
            parentRelation: 'posts',
        );
    }

    #[Test]
    public function isReadonly(): void
    {
        $meta = new BindingMeta(class: stdClass::class);

        $reflection = new ReflectionClass($meta);

        self::assertTrue($reflection->isReadonly());
    }
}
