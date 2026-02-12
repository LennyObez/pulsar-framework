<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Financial;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Financial\RoutingNumber;

#[CoversClass(RoutingNumber::class)]
final class RoutingNumberTest extends TestCase
{
    private RoutingNumber $rule;

    protected function setUp(): void
    {
        $this->rule = new RoutingNumber();
    }

    #[Test]
    public function validRoutingNumberPasses(): void
    {
        // 021000021 (JPMorgan Chase) - known valid
        self::assertNull($this->rule->validate('routing', '021000021', []));
    }

    #[Test]
    public function anotherValidRoutingNumberPasses(): void
    {
        // 011401533 - known valid
        self::assertNull($this->rule->validate('routing', '011401533', []));
    }

    #[Test]
    public function invalidChecksumFails(): void
    {
        $violation = $this->rule->validate('routing', '021000022', []);
        self::assertNotNull($violation);
        self::assertSame('routing_number', $violation->rule);
    }

    #[Test]
    public function tooFewDigitsFails(): void
    {
        $violation = $this->rule->validate('routing', '02100002', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tooManyDigitsFails(): void
    {
        $violation = $this->rule->validate('routing', '0210000210', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonDigitsFails(): void
    {
        $violation = $this->rule->validate('routing', '0210000AB', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('routing', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('routing', null, []));
    }

    #[Test]
    public function nameReturnsRoutingNumber(): void
    {
        self::assertSame('routing_number', $this->rule->name());
    }

    #[Test]
    public function validBankOfAmericaPasses(): void
    {
        // 026009593 (Bank of America)
        self::assertNull($this->rule->validate('routing', '026009593', []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new RoutingNumber(message: 'Custom routing message');
        $violation = $rule->validate('routing', '123456789', []);
        self::assertNotNull($violation);
        self::assertSame('Custom routing message', $violation->message);
    }

    #[Test]
    public function anotherInvalidChecksumFails(): void
    {
        // 3*1 + 7*2 + 3 + 3*4 + 7*5 + 6 + 3*7 + 7*8 + 9 = 159, 159 mod 10 != 0
        $violation = $this->rule->validate('routing', '123456789', []);
        self::assertNotNull($violation);
    }
}
