<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Size;

#[CoversClass(Size::class)]
final class SizeTest extends TestCase
{
    #[Test]
    public function arrayWithExactCountPasses(): void
    {
        $rule = new Size(3);
        self::assertNull($rule->validate('field', [1, 2, 3], []));
    }

    #[Test]
    public function arrayWithWrongCountFails(): void
    {
        $rule = new Size(3);
        $violation = $rule->validate('field', [1, 2], []);
        self::assertNotNull($violation);
        self::assertSame('size', $violation->rule);
    }

    #[Test]
    public function stringWithExactLengthPasses(): void
    {
        $rule = new Size(5);
        self::assertNull($rule->validate('field', 'hello', []));
    }

    #[Test]
    public function stringWithWrongLengthFails(): void
    {
        $rule = new Size(5);
        $violation = $rule->validate('field', 'hi', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function multibyteLengthCheck(): void
    {
        $rule = new Size(3);
        self::assertNull($rule->validate('field', "\u{00E9}\u{00E0}\u{00FC}", []));
    }

    #[Test]
    public function emptyArraySizeZero(): void
    {
        $rule = new Size(0);
        self::assertNull($rule->validate('field', [], []));
    }

    #[Test]
    public function unsupportedTypeFails(): void
    {
        $rule = new Size(1);
        $violation = $rule->validate('field', 42, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Size(3);
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new Size(2, message: 'Wrong size.');
        $violation = $rule->validate('field', [1], []);
        self::assertNotNull($violation);
        self::assertSame('Wrong size.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('size', new Size(1)->name());
    }
}
