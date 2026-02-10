<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Same;

#[CoversClass(Same::class)]
final class SameTest extends TestCase
{
    #[Test]
    public function matchingFieldsPasses(): void
    {
        $rule = new Same('other');
        self::assertNull($rule->validate('field', 'value', ['other' => 'value']));
    }

    #[Test]
    public function differentValuesFails(): void
    {
        $rule = new Same('other');
        $violation = $rule->validate('field', 'foo', ['other' => 'bar']);
        self::assertNotNull($violation);
        self::assertSame('same', $violation->rule);
    }

    #[Test]
    public function strictComparisonFails(): void
    {
        $rule = new Same('other');
        $violation = $rule->validate('field', '1', ['other' => 1]);
        self::assertNotNull($violation);
    }

    #[Test]
    public function missingOtherFieldFails(): void
    {
        $rule = new Same('other');
        $violation = $rule->validate('field', 'value', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Same('other');
        self::assertNull($rule->validate('field', null, ['other' => 'value']));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new Same('other', message: 'Must match.');
        $violation = $rule->validate('field', 'a', ['other' => 'b']);
        self::assertNotNull($violation);
        self::assertSame('Must match.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('same', new Same('other')->name());
    }
}
