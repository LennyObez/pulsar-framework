<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Guard;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tenancy\Guard\TenantId;

#[CoversClass(TenantId::class)]
final class TenantIdTest extends TestCase
{
    #[Test]
    public function test_valid_id_accepted(): void
    {
        $id = new TenantId('acme-corp_123');

        self::assertSame('acme-corp_123', $id->value);
    }

    #[Test]
    public function test_empty_id_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TenantId('');
    }

    #[Test]
    public function test_invalid_chars_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TenantId('invalid tenant!');
    }

    #[Test]
    public function test_equals_same_value(): void
    {
        $a = new TenantId('acme');
        $b = new TenantId('acme');

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function test_equals_different_value(): void
    {
        $a = new TenantId('acme');
        $b = new TenantId('other');

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function test_to_string(): void
    {
        $id = new TenantId('acme');

        self::assertSame('acme', $id->toString());
        self::assertSame('acme', (string) $id);
    }
}
