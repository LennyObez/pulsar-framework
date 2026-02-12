<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Healthcare;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Healthcare\FhirResourceId;

#[CoversClass(FhirResourceId::class)]
final class FhirResourceIdTest extends TestCase
{
    private FhirResourceId $rule;

    protected function setUp(): void
    {
        $this->rule = new FhirResourceId();
    }

    #[Test]
    public function simpleAlphanumericPasses(): void
    {
        self::assertNull($this->rule->validate('id', 'abc123', []));
    }

    #[Test]
    public function withDotAndDashPasses(): void
    {
        self::assertNull($this->rule->validate('id', 'patient-001.v2', []));
    }

    #[Test]
    public function singleCharacterPasses(): void
    {
        self::assertNull($this->rule->validate('id', 'a', []));
    }

    #[Test]
    public function sixtyFourCharactersPasses(): void
    {
        self::assertNull($this->rule->validate('id', str_repeat('a', 64), []));
    }

    #[Test]
    public function sixtyFiveCharactersFails(): void
    {
        $violation = $this->rule->validate('id', str_repeat('a', 65), []);
        self::assertNotNull($violation);
        self::assertSame('fhir_resource_id', $violation->rule);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('id', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function spacesFails(): void
    {
        $violation = $this->rule->validate('id', 'abc 123', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function underscoreFails(): void
    {
        $violation = $this->rule->validate('id', 'abc_123', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('id', null, []));
    }

    #[Test]
    public function nameReturnsFhirResourceId(): void
    {
        self::assertSame('fhir_resource_id', $this->rule->name());
    }

    #[Test]
    public function uuidStylePasses(): void
    {
        self::assertNull($this->rule->validate('id', 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new FhirResourceId(message: 'Custom FHIR message');
        $violation = $rule->validate('id', 'invalid!id', []);
        self::assertNotNull($violation);
        self::assertSame('Custom FHIR message', $violation->message);
    }

    #[Test]
    public function dotOnlyPasses(): void
    {
        self::assertNull($this->rule->validate('id', '.', []));
    }

    #[Test]
    public function dashOnlyPasses(): void
    {
        self::assertNull($this->rule->validate('id', '-', []));
    }

    #[Test]
    public function specialCharacterAtSignFails(): void
    {
        $violation = $this->rule->validate('id', 'patient@1', []);
        self::assertNotNull($violation);
    }
}
