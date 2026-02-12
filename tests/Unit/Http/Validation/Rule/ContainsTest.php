<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Contains;

#[CoversClass(Contains::class)]
final class ContainsTest extends TestCase
{
    #[Test]
    public function stringContainingNeedlePasses(): void
    {
        $rule = new Contains('world');
        self::assertNull($rule->validate('field', 'hello world', []));
    }

    #[Test]
    public function stringNotContainingNeedleFails(): void
    {
        $rule = new Contains('world');
        $violation = $rule->validate('field', 'hello there', []);
        self::assertNotNull($violation);
        self::assertSame('contains', $violation->rule);
    }

    #[Test]
    public function caseSensitive(): void
    {
        $rule = new Contains('World');
        $violation = $rule->validate('field', 'hello world', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function needleAtStartPasses(): void
    {
        $rule = new Contains('hello');
        self::assertNull($rule->validate('field', 'hello world', []));
    }

    #[Test]
    public function needleAtEndPasses(): void
    {
        $rule = new Contains('world');
        self::assertNull($rule->validate('field', 'hello world', []));
    }

    #[Test]
    public function exactMatchPasses(): void
    {
        $rule = new Contains('hello');
        self::assertNull($rule->validate('field', 'hello', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Contains('test');
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Contains('xyz', message: 'Must contain xyz');
        $violation = $rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
        self::assertSame('Must contain xyz', $violation->message);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $rule = new Contains('1');
        $violation = $rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }
}
