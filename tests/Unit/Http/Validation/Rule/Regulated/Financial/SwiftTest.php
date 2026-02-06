<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Financial;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Financial\Swift;

#[CoversClass(Swift::class)]
final class SwiftTest extends TestCase
{
    private Swift $rule;

    protected function setUp(): void
    {
        $this->rule = new Swift();
    }

    #[Test]
    public function eightCharacterPasses(): void
    {
        self::assertNull($this->rule->validate('swift', 'DEUTDEFF', []));
    }

    #[Test]
    public function elevenCharacterPasses(): void
    {
        self::assertNull($this->rule->validate('swift', 'DEUTDEFF500', []));
    }

    #[Test]
    public function alphanumericLocationPasses(): void
    {
        self::assertNull($this->rule->validate('swift', 'CHASUS33', []));
    }

    #[Test]
    public function alphanumericBranchPasses(): void
    {
        self::assertNull($this->rule->validate('swift', 'CHASUS33XXX', []));
    }

    #[Test]
    public function lowercaseFails(): void
    {
        $violation = $this->rule->validate('swift', 'deutdeff', []);
        self::assertNotNull($violation);
        self::assertSame('swift', $violation->rule);
    }

    #[Test]
    public function sevenCharactersFails(): void
    {
        $violation = $this->rule->validate('swift', 'DEUTDEF', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nineCharactersFails(): void
    {
        $violation = $this->rule->validate('swift', 'DEUTDEFF5', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tenCharactersFails(): void
    {
        $violation = $this->rule->validate('swift', 'DEUTDEFF50', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function numericBankCodeFails(): void
    {
        $violation = $this->rule->validate('swift', '1234DEFF', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function numericCountryCodeFails(): void
    {
        $violation = $this->rule->validate('swift', 'DEUT12FF', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('swift', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('swift', null, []));
    }

    #[Test]
    public function nameReturnsSwift(): void
    {
        self::assertSame('swift', $this->rule->name());
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Swift(message: 'Custom SWIFT message');
        $violation = $rule->validate('swift', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Custom SWIFT message', $violation->message);
    }

    #[Test]
    public function mixedCaseFails(): void
    {
        $violation = $this->rule->validate('swift', 'DeutDEFF', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function twelveCharactersFails(): void
    {
        $violation = $this->rule->validate('swift', 'DEUTDEFF5001', []);
        self::assertNotNull($violation);
    }
}
