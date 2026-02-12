<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Different;

#[CoversClass(Different::class)]
final class DifferentTest extends TestCase
{
    #[Test]
    public function differentValuesPasses(): void
    {
        $rule = new Different('other');
        self::assertNull($rule->validate('field', 'foo', ['other' => 'bar']));
    }

    #[Test]
    public function sameValuesFails(): void
    {
        $rule = new Different('other');
        $violation = $rule->validate('field', 'same', ['other' => 'same']);
        self::assertNotNull($violation);
        self::assertSame('different', $violation->rule);
    }

    #[Test]
    public function strictComparisonPasses(): void
    {
        $rule = new Different('other');
        self::assertNull($rule->validate('field', '1', ['other' => 1]));
    }

    #[Test]
    public function missingOtherFieldPasses(): void
    {
        $rule = new Different('other');
        self::assertNull($rule->validate('field', 'value', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Different('other');
        self::assertNull($rule->validate('field', null, ['other' => 'value']));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new Different('other', message: 'Must differ.');
        $violation = $rule->validate('field', 'x', ['other' => 'x']);
        self::assertNotNull($violation);
        self::assertSame('Must differ.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('different', new Different('other')->name());
    }
}
