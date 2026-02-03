<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regex;

#[CoversClass(Regex::class)]
final class RegexTest extends TestCase
{
    #[Test]
    public function matchingPatternPasses(): void
    {
        $rule = new Regex('/^\d{3}-\d{4}$/');
        self::assertNull($rule->validate('field', '123-4567', []));
    }

    #[Test]
    public function nonMatchingPatternFails(): void
    {
        $rule = new Regex('/^\d{3}-\d{4}$/');
        $violation = $rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
        self::assertSame('regex', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Regex('/^\d+$/');
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function nonStringFails(): void
    {
        $rule = new Regex('/^\d+$/');
        $violation = $rule->validate('field', 123, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new Regex('/^\d+$/', 'Must be digits only.');
        $violation = $rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
        self::assertSame('Must be digits only.', $violation->message);
    }
}
