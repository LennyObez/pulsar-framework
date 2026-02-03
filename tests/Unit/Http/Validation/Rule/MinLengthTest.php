<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\MinLength;

#[CoversClass(MinLength::class)]
final class MinLengthTest extends TestCase
{
    #[Test]
    public function atMinimumPasses(): void
    {
        $rule = new MinLength(3);
        self::assertNull($rule->validate('field', 'abc', []));
    }

    #[Test]
    public function aboveMinimumPasses(): void
    {
        $rule = new MinLength(3);
        self::assertNull($rule->validate('field', 'abcdef', []));
    }

    #[Test]
    public function belowMinimumFails(): void
    {
        $rule = new MinLength(3);
        $violation = $rule->validate('field', 'ab', []);
        self::assertNotNull($violation);
        self::assertSame('min_length', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new MinLength(3);
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function multibyteLengthIsUsed(): void
    {
        $rule = new MinLength(3);
        // 3 multibyte characters
        self::assertNull($rule->validate('field', "\u{00E9}\u{00E9}\u{00E9}", []));
    }
}
