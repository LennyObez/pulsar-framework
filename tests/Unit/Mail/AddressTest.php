<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Address;
use ReflectionClass;

#[CoversClass(Address::class)]
final class AddressTest extends TestCase
{
    #[Test]
    public function it_constructs_with_email_only(): void
    {
        $address = new Address('user@test.com');

        self::assertSame('user@test.com', $address->email);
        self::assertSame('', $address->name);
    }

    #[Test]
    public function it_constructs_with_email_and_name(): void
    {
        $address = new Address('user@test.com', 'John Doe');

        self::assertSame('user@test.com', $address->email);
        self::assertSame('John Doe', $address->name);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $reflection = new ReflectionClass(Address::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
