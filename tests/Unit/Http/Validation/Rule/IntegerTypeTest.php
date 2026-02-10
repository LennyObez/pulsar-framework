<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\IntegerType;

#[CoversClass(IntegerType::class)]
final class IntegerTypeTest extends TestCase
{
    private IntegerType $rule;

    protected function setUp(): void
    {
        $this->rule = new IntegerType();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function validIntegerProvider(): iterable
    {
        yield 'positive int' => [42];
        yield 'zero int' => [0];
        yield 'negative int' => [-5];
        yield 'positive numeric string' => ['42'];
        yield 'negative numeric string' => ['-5'];
        yield 'zero string' => ['0'];
        yield 'PHP_INT_MAX' => [PHP_INT_MAX];
    }

    #[Test]
    #[DataProvider('validIntegerProvider')]
    public function validIntegerPasses(mixed $value): void
    {
        self::assertNull($this->rule->validate('field', $value, []));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidIntegerProvider(): iterable
    {
        yield 'alphabetic string' => ['abc'];
        yield 'float string' => ['3.14'];
        yield 'true bool' => [true];
        yield 'false bool' => [false];
        yield 'float value' => [3.14];
        yield 'empty string' => [''];
        yield 'mixed alphanumeric' => ['12abc'];
    }

    #[Test]
    #[DataProvider('invalidIntegerProvider')]
    public function invalidIntegerFails(mixed $value): void
    {
        $violation = $this->rule->validate('field', $value, []);
        self::assertNotNull($violation);
        self::assertSame('integer', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }
}
