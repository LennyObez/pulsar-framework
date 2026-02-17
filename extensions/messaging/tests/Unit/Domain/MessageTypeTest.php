<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Domain\MessageType;

#[CoversClass(MessageType::class)]
final class MessageTypeTest extends TestCase
{
    #[DataProvider('validCasesProvider')]
    public function testFromValidValue(string $value, MessageType $expected): void
    {
        self::assertSame($expected, MessageType::from($value));
    }

    /**
     * @return iterable<string, array{string, MessageType}>
     */
    public static function validCasesProvider(): iterable
    {
        yield 'text' => ['text', MessageType::Text];
        yield 'image' => ['image', MessageType::Image];
        yield 'file' => ['file', MessageType::File];
        yield 'system' => ['system', MessageType::System];
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(MessageType::tryFrom('audio'));
    }

    public function testAllCasesExist(): void
    {
        self::assertCount(4, MessageType::cases());
    }
}
