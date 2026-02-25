<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Required;

#[CoversClass(Required::class)]
final class RequiredTest extends TestCase
{
    private Required $rule;

    protected function setUp(): void
    {
        $this->rule = new Required();
    }

    // ---- DataProvider-driven present values (must pass) ----

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function presentValueProvider(): iterable
    {
        yield 'non-empty string' => ['hello'];
        yield 'zero string' => ['0'];
        yield 'zero int' => [0];
        yield 'false bool' => [false];
        yield 'non-empty array' => [['a']];
        yield 'whitespace-only string' => [' '];
        yield 'negative int' => [-1];
    }

    #[Test]
    #[DataProvider('presentValueProvider')]
    public function presentValuePasses(mixed $value): void
    {
        self::assertNull($this->rule->validate('field', $value, []));
    }

    // ---- DataProvider-driven missing values (must fail) ----

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function missingValueProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'empty array' => [[]];
    }

    #[Test]
    #[DataProvider('missingValueProvider')]
    public function missingValueFails(mixed $value): void
    {
        $violation = $this->rule->validate('field', $value, []);
        self::assertNotNull($violation);
        self::assertSame('required', $violation->rule);
    }

    // ---- Existing single-case tests kept for clarity ----

    #[Test]
    public function customMessageIsUsed(): void
    {
        $rule = new Required('Custom required message');
        $violation = $rule->validate('field', null, []);
        self::assertNotNull($violation);
        self::assertSame('Custom required message', $violation->message);
    }

    #[Test]
    public function nameReturnsRequired(): void
    {
        self::assertSame('required', $this->rule->name());
    }
}
