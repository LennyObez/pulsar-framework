<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Deprecated;

#[CoversClass(Deprecated::class)]
final class DeprecatedTest extends TestCase
{
    public function testMessageWithAllFields(): void
    {
        $deprecated = new Deprecated(
            since: '1.1',
            removeIn: '2.0',
            replacement: 'newMethod()',
            reason: 'Performance regression',
        );

        $message = $deprecated->message('OldClass::oldMethod');

        self::assertStringContainsString('OldClass::oldMethod is deprecated', $message);
        self::assertStringContainsString('since 1.1', $message);
        self::assertStringContainsString('removed in 2.0', $message);
        self::assertStringContainsString('Use newMethod() instead', $message);
        self::assertStringContainsString('Performance regression', $message);
    }

    public function testMessageWithMinimalFields(): void
    {
        $deprecated = new Deprecated();
        $message = $deprecated->message('SomeClass');

        self::assertSame('SomeClass is deprecated.', $message);
    }

    public function testMessageWithSinceOnly(): void
    {
        $deprecated = new Deprecated(since: '1.2');
        $message = $deprecated->message('MyMethod');

        self::assertStringContainsString('since 1.2', $message);
        self::assertStringNotContainsString('removed', $message);
    }

    public function testMessageWithReplacementOnly(): void
    {
        $deprecated = new Deprecated(replacement: 'BetterClass');
        $message = $deprecated->message('OldClass');

        self::assertStringContainsString('Use BetterClass instead', $message);
    }

    public function testProperties(): void
    {
        $deprecated = new Deprecated(
            since: '1.0',
            removeIn: '3.0',
            replacement: 'newThing()',
            reason: 'Redesigned',
        );

        self::assertSame('1.0', $deprecated->since);
        self::assertSame('3.0', $deprecated->removeIn);
        self::assertSame('newThing()', $deprecated->replacement);
        self::assertSame('Redesigned', $deprecated->reason);
    }
}
