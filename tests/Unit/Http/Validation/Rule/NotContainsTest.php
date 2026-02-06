<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\NotContains;

#[CoversClass(NotContains::class)]
final class NotContainsTest extends TestCase
{
    #[Test]
    public function stringNotContainingNeedlePasses(): void
    {
        $rule = new NotContains('forbidden');
        self::assertNull($rule->validate('field', 'hello world', []));
    }

    #[Test]
    public function stringContainingNeedleFails(): void
    {
        $rule = new NotContains('world');
        $violation = $rule->validate('field', 'hello world', []);
        self::assertNotNull($violation);
        self::assertSame('not_contains', $violation->rule);
    }

    #[Test]
    public function caseSensitivePasses(): void
    {
        $rule = new NotContains('World');
        self::assertNull($rule->validate('field', 'hello world', []));
    }

    #[Test]
    public function emptyStringPasses(): void
    {
        $rule = new NotContains('test');
        self::assertNull($rule->validate('field', '', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new NotContains('test');
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new NotContains('bad', message: 'No bad words');
        $violation = $rule->validate('field', 'very bad thing', []);
        self::assertNotNull($violation);
        self::assertSame('No bad words', $violation->message);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $rule = new NotContains('1');
        $violation = $rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }
}
