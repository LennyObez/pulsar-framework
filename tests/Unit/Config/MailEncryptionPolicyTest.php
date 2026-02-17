<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\MailEncryptionPolicy;

#[CoversClass(MailEncryptionPolicy::class)]
final class MailEncryptionPolicyTest extends TestCase
{
    #[Test]
    public function backingValues(): void
    {
        self::assertSame('require', MailEncryptionPolicy::Require->value);
        self::assertSame('prefer', MailEncryptionPolicy::Prefer->value);
        self::assertSame('none', MailEncryptionPolicy::None->value);
    }

    #[Test]
    public function allCases(): void
    {
        self::assertCount(3, MailEncryptionPolicy::cases());
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(MailEncryptionPolicy::tryFrom('ssl'));
    }
}
