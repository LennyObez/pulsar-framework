<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Identity\Ein;

#[CoversClass(Ein::class)]
final class EinTest extends TestCase
{
    private Ein $rule;

    protected function setUp(): void
    {
        $this->rule = new Ein();
    }

    #[Test]
    public function validEinPasses(): void
    {
        self::assertNull($this->rule->validate('ein', '12-3456789', []));
    }

    #[Test]
    public function anotherValidPrefixPasses(): void
    {
        self::assertNull($this->rule->validate('ein', '95-1234567', []));
    }

    #[Test]
    public function prefix10Passes(): void
    {
        self::assertNull($this->rule->validate('ein', '10-1234567', []));
    }

    #[Test]
    public function prefix99Passes(): void
    {
        self::assertNull($this->rule->validate('ein', '99-1234567', []));
    }

    #[Test]
    public function invalidPrefixFails(): void
    {
        // 01 is not a valid campus prefix
        $violation = $this->rule->validate('ein', '01-1234567', []);
        self::assertNotNull($violation);
        self::assertSame('ein', $violation->rule);
    }

    #[Test]
    public function noDashFails(): void
    {
        $violation = $this->rule->validate('ein', '123456789', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tooFewDigitsFails(): void
    {
        $violation = $this->rule->validate('ein', '12-123456', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tooManyDigitsFails(): void
    {
        $violation = $this->rule->validate('ein', '12-12345678', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonDigitsFails(): void
    {
        $violation = $this->rule->validate('ein', 'AB-CDEFGHI', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('ein', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('ein', null, []));
    }

    #[Test]
    public function nameReturnsEin(): void
    {
        self::assertSame('ein', $this->rule->name());
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Ein(message: 'Custom EIN message');
        $violation = $rule->validate('ein', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Custom EIN message', $violation->message);
    }

    #[Test]
    public function prefix11NotInValidListFails(): void
    {
        $violation = $this->rule->validate('ein', '11-1234567', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function prefix00Fails(): void
    {
        $violation = $this->rule->validate('ein', '00-1234567', []);
        self::assertNotNull($violation);
    }
}
