<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\ValueObject;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity;
use stdClass;

final class ValueObjectTest extends TestCase
{
    #[Test]
    public function storesClassAndDefaultPrefix(): void
    {
        $attr = new ValueObject(class: stdClass::class);

        self::assertSame(stdClass::class, $attr->class);
        self::assertSame('', $attr->prefix);
    }

    #[Test]
    public function storesCustomPrefix(): void
    {
        $attr = new ValueObject(class: UserEntity::class, prefix: 'billing_');

        self::assertSame(UserEntity::class, $attr->class);
        self::assertSame('billing_', $attr->prefix);
    }
}
