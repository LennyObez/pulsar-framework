<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\Encrypted;
use ReflectionClass;

final class EncryptedTest extends TestCase
{
    #[Test]
    public function isInstantiable(): void
    {
        $attr = new Encrypted();

        self::assertInstanceOf(Encrypted::class, $attr);
    }

    #[Test]
    public function targetsProperties(): void
    {
        $reflection = new ReflectionClass(Encrypted::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        self::assertNotEmpty($attributes);
        $attrInstance = $attributes[0]->newInstance();
        self::assertSame(Attribute::TARGET_PROPERTY, $attrInstance->flags);
    }
}
