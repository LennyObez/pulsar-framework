<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\MaxLength;

#[CoversClass(MaxLength::class)]
final class MaxLengthTest extends TestCase
{
    #[Test]
    public function atMaximumPasses(): void
    {
        $rule = new MaxLength(5);
        self::assertNull($rule->validate('field', 'abcde', []));
    }

    #[Test]
    public function belowMaximumPasses(): void
    {
        $rule = new MaxLength(5);
        self::assertNull($rule->validate('field', 'abc', []));
    }

    #[Test]
    public function aboveMaximumFails(): void
    {
        $rule = new MaxLength(5);
        $violation = $rule->validate('field', 'abcdef', []);
        self::assertNotNull($violation);
        self::assertSame('max_length', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new MaxLength(5);
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function emptyStringPasses(): void
    {
        $rule = new MaxLength(5);
        self::assertNull($rule->validate('field', '', []));
    }
}
