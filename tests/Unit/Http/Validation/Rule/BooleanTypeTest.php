<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\BooleanType;

#[CoversClass(BooleanType::class)]
final class BooleanTypeTest extends TestCase
{
    private BooleanType $rule;

    protected function setUp(): void
    {
        $this->rule = new BooleanType();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function validBooleans(): iterable
    {
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'int 1' => [1];
        yield 'int 0' => [0];
        yield 'string 1' => ['1'];
        yield 'string 0' => ['0'];
    }

    #[Test]
    #[DataProvider('validBooleans')]
    public function validBooleanPasses(mixed $value): void
    {
        self::assertNull($this->rule->validate('field', $value, []));
    }

    #[Test]
    public function stringYesFails(): void
    {
        $violation = $this->rule->validate('field', 'yes', []);
        self::assertNotNull($violation);
        self::assertSame('boolean', $violation->rule);
    }

    #[Test]
    public function intTwoFails(): void
    {
        $violation = $this->rule->validate('field', 2, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('field', '', []);
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
        $rule = new BooleanType(message: 'Must be bool.');
        $violation = $rule->validate('field', 'nope', []);
        self::assertNotNull($violation);
        self::assertSame('Must be bool.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('boolean', $this->rule->name());
    }
}
