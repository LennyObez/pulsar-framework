<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Domain\ConversationType;

#[CoversNothing]
final class ConversationTypeTest extends TestCase
{
    #[DataProvider('validCasesProvider')]
    public function testFromValidValue(string $value, ConversationType $expected): void
    {
        self::assertSame($expected, ConversationType::from($value));
    }

    /**
     * @return iterable<string, array{string, ConversationType}>
     */
    public static function validCasesProvider(): iterable
    {
        yield 'direct' => ['direct', ConversationType::Direct];
        yield 'group' => ['group', ConversationType::Group];
        yield 'channel' => ['channel', ConversationType::Channel];
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(ConversationType::tryFrom('invalid'));
    }

    public function testAllCasesExist(): void
    {
        self::assertCount(3, ConversationType::cases());
    }
}
