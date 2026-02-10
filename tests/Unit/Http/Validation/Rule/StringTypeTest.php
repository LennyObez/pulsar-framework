<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\StringType;

#[CoversClass(StringType::class)]
final class StringTypeTest extends TestCase
{
    private StringType $rule;

    protected function setUp(): void
    {
        $this->rule = new StringType();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function validStringProvider(): iterable
    {
        yield 'non-empty string' => ['hello'];
        yield 'empty string' => [''];
        yield 'whitespace' => [' '];
        yield 'numeric string' => ['42'];
        yield 'unicode string' => ['こんにちは'];
        yield 'multi-line string' => ["line1\nline2"];
    }

    #[Test]
    #[DataProvider('validStringProvider')]
    public function validStringPasses(mixed $value): void
    {
        self::assertNull($this->rule->validate('field', $value, []));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringProvider(): iterable
    {
        yield 'integer' => [42];
        yield 'float' => [3.14];
        yield 'true bool' => [true];
        yield 'false bool' => [false];
        yield 'array' => [['a']];
        yield 'empty array' => [[]];
    }

    #[Test]
    #[DataProvider('nonStringProvider')]
    public function nonStringFails(mixed $value): void
    {
        $violation = $this->rule->validate('field', $value, []);
        self::assertNotNull($violation);
        self::assertSame('string', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }
}
