<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
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

    #[Test]
    public function stringPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'hello', []));
    }

    #[Test]
    public function emptyStringPasses(): void
    {
        self::assertNull($this->rule->validate('field', '', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function intFails(): void
    {
        $violation = $this->rule->validate('field', 42, []);
        self::assertNotNull($violation);
        self::assertSame('string', $violation->rule);
    }

    #[Test]
    public function boolFails(): void
    {
        $violation = $this->rule->validate('field', true, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function arrayFails(): void
    {
        $violation = $this->rule->validate('field', ['a'], []);
        self::assertNotNull($violation);
    }
}
