<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\LiveProp;
use ReflectionClass;

#[CoversClass(LiveProp::class)]
final class LivePropTest extends TestCase
{
    #[Test]
    public function defaultsAreNotWritableAndNoFieldName(): void
    {
        $prop = new LiveProp();

        self::assertFalse($prop->writable);
        self::assertSame('', $prop->fieldName);
    }

    #[Test]
    public function writableCanBeEnabled(): void
    {
        $prop = new LiveProp(writable: true);

        self::assertTrue($prop->writable);
    }

    #[Test]
    public function customFieldNameIsStored(): void
    {
        $prop = new LiveProp(fieldName: 'user_name');

        self::assertSame('user_name', $prop->fieldName);
    }

    #[Test]
    public function attributeTargetsPropertiesOnly(): void
    {
        $ref = new ReflectionClass(LiveProp::class);
        $attrs = $ref->getAttributes(Attribute::class);

        self::assertCount(1, $attrs);
        $instance = $attrs[0]->newInstance();
        self::assertSame(Attribute::TARGET_PROPERTY, $instance->flags);
    }
}
