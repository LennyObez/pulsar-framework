<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Phone;

#[CoversClass(Phone::class)]
final class PhoneTest extends TestCase
{
    private Phone $rule;

    protected function setUp(): void
    {
        $this->rule = new Phone();
    }

    #[Test]
    public function validE164Passes(): void
    {
        self::assertNull($this->rule->validate('phone', '+14155552671', []));
    }

    #[Test]
    public function minLengthPasses(): void
    {
        self::assertNull($this->rule->validate('phone', '+12', []));
    }

    #[Test]
    public function maxLengthPasses(): void
    {
        self::assertNull($this->rule->validate('phone', '+123456789012345', []));
    }

    #[Test]
    public function missingPlusFails(): void
    {
        $violation = $this->rule->validate('phone', '14155552671', []);
        self::assertNotNull($violation);
        self::assertSame('phone', $violation->rule);
    }

    #[Test]
    public function startingWithZeroFails(): void
    {
        $violation = $this->rule->validate('phone', '+0123456789', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tooLongFails(): void
    {
        $violation = $this->rule->validate('phone', '+1234567890123456', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function withSpacesFails(): void
    {
        $violation = $this->rule->validate('phone', '+1 415 555 2671', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('phone', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('phone', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new Phone(message: 'Bad phone.');
        $violation = $rule->validate('phone', 'abc', []);
        self::assertNotNull($violation);
        self::assertSame('Bad phone.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('phone', $this->rule->name());
    }
}
