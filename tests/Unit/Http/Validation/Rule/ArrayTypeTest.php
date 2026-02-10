<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\ArrayType;

#[CoversClass(ArrayType::class)]
final class ArrayTypeTest extends TestCase
{
    private ArrayType $rule;

    protected function setUp(): void
    {
        $this->rule = new ArrayType();
    }

    #[Test]
    public function arrayPasses(): void
    {
        self::assertNull($this->rule->validate('field', [1, 2, 3], []));
    }

    #[Test]
    public function emptyArrayPasses(): void
    {
        self::assertNull($this->rule->validate('field', [], []));
    }

    #[Test]
    public function associativeArrayPasses(): void
    {
        self::assertNull($this->rule->validate('field', ['key' => 'value'], []));
    }

    #[Test]
    public function stringFails(): void
    {
        $violation = $this->rule->validate('field', 'not an array', []);
        self::assertNotNull($violation);
        self::assertSame('array', $violation->rule);
    }

    #[Test]
    public function integerFails(): void
    {
        $violation = $this->rule->validate('field', 42, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new ArrayType(message: 'Must be array.');
        $violation = $rule->validate('field', 'string', []);
        self::assertNotNull($violation);
        self::assertSame('Must be array.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('array', $this->rule->name());
    }
}
