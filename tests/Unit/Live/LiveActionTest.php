<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\LiveAction;
use ReflectionClass;

#[CoversClass(LiveAction::class)]
final class LiveActionTest extends TestCase
{
    #[Test]
    public function defaultNameIsEmpty(): void
    {
        $attr = new LiveAction();

        self::assertSame('', $attr->name);
    }

    #[Test]
    public function customNameIsStored(): void
    {
        $attr = new LiveAction(name: 'doStuff');

        self::assertSame('doStuff', $attr->name);
    }

    #[Test]
    public function attributeTargetsMethodsOnly(): void
    {
        $ref = new ReflectionClass(LiveAction::class);
        $attrs = $ref->getAttributes(Attribute::class);

        self::assertCount(1, $attrs);
        $instance = $attrs[0]->newInstance();
        self::assertSame(Attribute::TARGET_METHOD, $instance->flags);
    }
}
