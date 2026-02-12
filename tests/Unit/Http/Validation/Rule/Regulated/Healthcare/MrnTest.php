<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Healthcare;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Healthcare\Mrn;

#[CoversClass(Mrn::class)]
final class MrnTest extends TestCase
{
    private Mrn $rule;

    protected function setUp(): void
    {
        $this->rule = new Mrn();
    }

    #[Test]
    public function validMrnPasses(): void
    {
        self::assertNull($this->rule->validate('mrn', 'MRN12345', []));
    }

    #[Test]
    public function numericOnlyMrnPasses(): void
    {
        self::assertNull($this->rule->validate('mrn', '12345678', []));
    }

    #[Test]
    public function fourCharMinimumPasses(): void
    {
        self::assertNull($this->rule->validate('mrn', 'AB12', []));
    }

    #[Test]
    public function twentyCharMaximumPasses(): void
    {
        self::assertNull($this->rule->validate('mrn', 'ABCDEFGHIJ1234567890', []));
    }

    #[Test]
    public function tooShortFails(): void
    {
        $violation = $this->rule->validate('mrn', 'AB1', []);
        self::assertNotNull($violation);
        self::assertSame('mrn', $violation->rule);
    }

    #[Test]
    public function tooLongFails(): void
    {
        $violation = $this->rule->validate('mrn', 'ABCDEFGHIJ12345678901', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function specialCharactersFails(): void
    {
        $violation = $this->rule->validate('mrn', 'MRN-1234', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('mrn', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('mrn', null, []));
    }

    #[Test]
    public function nameReturnsMrn(): void
    {
        self::assertSame('mrn', $this->rule->name());
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Mrn(message: 'Custom MRN message');
        $violation = $rule->validate('mrn', 'bad!', []);
        self::assertNotNull($violation);
        self::assertSame('Custom MRN message', $violation->message);
    }
}
