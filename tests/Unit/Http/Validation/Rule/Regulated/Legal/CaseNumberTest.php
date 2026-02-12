<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Legal;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Legal\CaseNumber;

#[CoversClass(CaseNumber::class)]
final class CaseNumberTest extends TestCase
{
    private CaseNumber $rule;

    protected function setUp(): void
    {
        $this->rule = new CaseNumber();
    }

    #[Test]
    public function validDefaultPatternPasses(): void
    {
        self::assertNull($this->rule->validate('case', '2024-CV-001234', []));
    }

    #[Test]
    public function singleLetterCourtCodePasses(): void
    {
        self::assertNull($this->rule->validate('case', '2024-C-1', []));
    }

    #[Test]
    public function fiveLetterCourtCodePasses(): void
    {
        self::assertNull($this->rule->validate('case', '2024-CRIM-12345', []));
    }

    #[Test]
    public function lowercaseCourtFails(): void
    {
        $violation = $this->rule->validate('case', '2024-cv-001234', []);
        self::assertNotNull($violation);
        self::assertSame('case_number', $violation->rule);
    }

    #[Test]
    public function noDashesFails(): void
    {
        $violation = $this->rule->validate('case', '2024CV001234', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function customPatternPasses(): void
    {
        $rule = new CaseNumber(pattern: '/^[A-Z]{2}\d{4}-\d{6}$/');
        self::assertNull($rule->validate('case', 'CV2024-001234', []));
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('case', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('case', null, []));
    }

    #[Test]
    public function nameReturnsCaseNumber(): void
    {
        self::assertSame('case_number', $this->rule->name());
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new CaseNumber(message: 'Custom case number message');
        $violation = $rule->validate('case', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Custom case number message', $violation->message);
    }

    #[Test]
    public function sixLetterCourtCodeFails(): void
    {
        $violation = $this->rule->validate('case', '2024-CRIMIN-12345', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tenDigitSequencePasses(): void
    {
        self::assertNull($this->rule->validate('case', '2024-CV-1234567890', []));
    }

    #[Test]
    public function invalidPatternThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid regular expression');

        new CaseNumber(pattern: '/[invalid');
    }

    #[Test]
    public function excessivelyLongPatternThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum length');

        new CaseNumber(pattern: '/' . str_repeat('a', 500) . '/');
    }
}
