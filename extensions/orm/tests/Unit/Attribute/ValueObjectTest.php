<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\ValueObject;

final class ValueObjectTest extends TestCase
{
    #[Test]
    public function storesClassAndDefaultPrefix(): void
    {
        $attr = new ValueObject(class: 'App\\VO\\Address');

        self::assertSame('App\\VO\\Address', $attr->class);
        self::assertSame('', $attr->prefix);
    }

    #[Test]
    public function storesCustomPrefix(): void
    {
        $attr = new ValueObject(class: 'App\\VO\\Money', prefix: 'billing_');

        self::assertSame('App\\VO\\Money', $attr->class);
        self::assertSame('billing_', $attr->prefix);
    }
}
