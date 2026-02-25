<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regex;

#[CoversClass(Regex::class)]
final class RegexTest extends TestCase
{
    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function matchingProvider(): iterable
    {
        yield 'phone format' => ['/^\d{3}-\d{4}$/', '123-4567'];
        yield 'digits only' => ['/^\d+$/', '42'];
        yield 'uppercase letters' => ['/^[A-Z]+$/', 'ABC'];
        yield 'slug pattern' => ['/^[a-z0-9-]+$/', 'my-slug-123'];
        yield 'hex color' => ['/^#[0-9a-f]{6}$/i', '#FF5733'];
    }

    #[Test]
    #[DataProvider('matchingProvider')]
    public function matchingPatternPasses(string $pattern, mixed $value): void
    {
        $rule = new Regex($pattern);
        self::assertNull($rule->validate('field', $value, []));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function nonMatchingProvider(): iterable
    {
        yield 'letters in digit-only' => ['/^\d{3}-\d{4}$/', 'abc'];
        yield 'partial match not full' => ['/^\d+$/', '12abc'];
        yield 'empty string vs pattern' => ['/^\d+$/', ''];
        yield 'integer input (non-string)' => ['/^\d+$/', 123];
        yield 'array input' => ['/^\d+$/', ['1', '2']];
        yield 'float input' => ['/^\d+$/', 1.5];
    }

    #[Test]
    #[DataProvider('nonMatchingProvider')]
    public function nonMatchingPatternFails(string $pattern, mixed $value): void
    {
        $rule = new Regex($pattern);
        $violation = $rule->validate('field', $value, []);
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
    public function customMessage(): void
    {
        $rule = new Regex('/^\d+$/', 'Must be digits only.');
        $violation = $rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
        self::assertSame('Must be digits only.', $violation->message);
    }
}
