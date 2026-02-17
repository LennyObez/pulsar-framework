<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\ChatRole;
use ValueError;

#[CoversClass(ChatRole::class)]
final class ChatRoleTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        self::assertSame('system', ChatRole::System->value);
        self::assertSame('user', ChatRole::User->value);
        self::assertSame('assistant', ChatRole::Assistant->value);
        self::assertSame('tool', ChatRole::Tool->value);
    }

    #[Test]
    public function enumHasExactlyFourCases(): void
    {
        self::assertCount(4, ChatRole::cases());
    }

    #[Test]
    #[DataProvider('validStringValues')]
    public function fromCreatesEnumFromValidString(string $value, ChatRole $expected): void
    {
        self::assertSame($expected, ChatRole::from($value));
    }

    /**
     * @return iterable<string, array{string, ChatRole}>
     */
    public static function validStringValues(): iterable
    {
        yield 'system' => ['system', ChatRole::System];
        yield 'user' => ['user', ChatRole::User];
        yield 'assistant' => ['assistant', ChatRole::Assistant];
        yield 'tool' => ['tool', ChatRole::Tool];
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(ChatRole::tryFrom('invalid'));
        self::assertNull(ChatRole::tryFrom(''));
        self::assertNull(ChatRole::tryFrom('SYSTEM'));
    }

    #[Test]
    public function fromThrowsForInvalidValue(): void
    {
        $this->expectException(ValueError::class);

        ChatRole::from('unknown');
    }
}
