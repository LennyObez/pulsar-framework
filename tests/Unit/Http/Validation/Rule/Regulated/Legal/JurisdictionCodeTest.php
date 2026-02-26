<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Legal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Legal\JurisdictionCode;

#[CoversClass(JurisdictionCode::class)]
final class JurisdictionCodeTest extends TestCase
{
    private JurisdictionCode $rule;

    protected function setUp(): void
    {
        $this->rule = new JurisdictionCode();
    }

    #[Test]
    public function validStatePasses(): void
    {
        self::assertNull($this->rule->validate('jurisdiction', 'CA', []));
    }

    #[Test]
    public function dcPasses(): void
    {
        self::assertNull($this->rule->validate('jurisdiction', 'DC', []));
    }

    #[Test]
    public function puertoRicoPasses(): void
    {
        self::assertNull($this->rule->validate('jurisdiction', 'PR', []));
    }

    #[Test]
    public function guamPasses(): void
    {
        self::assertNull($this->rule->validate('jurisdiction', 'GU', []));
    }

    #[Test]
    public function virginIslandsPasses(): void
    {
        self::assertNull($this->rule->validate('jurisdiction', 'VI', []));
    }

    #[Test]
    public function americanSamoaPasses(): void
    {
        self::assertNull($this->rule->validate('jurisdiction', 'AS', []));
    }

    #[Test]
    public function lowercaseAccepted(): void
    {
        self::assertNull($this->rule->validate('jurisdiction', 'ca', []));
    }

    #[Test]
    public function invalidCodeFails(): void
    {
        $violation = $this->rule->validate('jurisdiction', 'XX', []);
        self::assertNotNull($violation);
        self::assertSame('jurisdiction_code', $violation->rule);
    }

    #[Test]
    public function threeCharactersFails(): void
    {
        $violation = $this->rule->validate('jurisdiction', 'CAL', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function singleCharacterFails(): void
    {
        $violation = $this->rule->validate('jurisdiction', 'C', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function numericFails(): void
    {
        $violation = $this->rule->validate('jurisdiction', '12', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('jurisdiction', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('jurisdiction', null, []));
    }

    #[Test]
    public function nameReturnsJurisdictionCode(): void
    {
        self::assertSame('jurisdiction_code', $this->rule->name());
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new JurisdictionCode(message: 'Custom jurisdiction message');
        $violation = $rule->validate('jurisdiction', 'XX', []);
        self::assertNotNull($violation);
        self::assertSame('Custom jurisdiction message', $violation->message);
    }

    #[Test]
    public function mixedCaseAccepted(): void
    {
        self::assertNull($this->rule->validate('jurisdiction', 'Ca', []));
    }

    #[Test]
    public function northernMarianaPasses(): void
    {
        self::assertNull($this->rule->validate('jurisdiction', 'MP', []));
    }

    #[Test]
    public function allFiftyStatesPlusTerritoriesCount(): void
    {
        // Verify that at least 56 codes are valid (50 states + DC + 5 territories)
        $codes = [
            'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
            'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
            'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
            'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
            'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
            'DC', 'AS', 'GU', 'MP', 'PR', 'VI',
        ];

        foreach ($codes as $code) {
            self::assertNull(
                $this->rule->validate('jurisdiction', $code, []),
                "Expected '{$code}' to be a valid jurisdiction code",
            );
        }
    }
}
