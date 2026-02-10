<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\CreditCard;

#[CoversClass(CreditCard::class)]
final class CreditCardTest extends TestCase
{
    private CreditCard $rule;

    protected function setUp(): void
    {
        $this->rule = new CreditCard();
    }

    #[Test]
    public function validVisaPasses(): void
    {
        // 4111111111111111 is a well-known Luhn-valid test number
        self::assertNull($this->rule->validate('card', '4111111111111111', []));
    }

    #[Test]
    public function validWithSpacesPasses(): void
    {
        self::assertNull($this->rule->validate('card', '4111 1111 1111 1111', []));
    }

    #[Test]
    public function validWithDashesPasses(): void
    {
        self::assertNull($this->rule->validate('card', '4111-1111-1111-1111', []));
    }

    #[Test]
    public function invalidLuhnFails(): void
    {
        $violation = $this->rule->validate('card', '4111111111111112', []);
        self::assertNotNull($violation);
        self::assertSame('credit_card', $violation->rule);
    }

    #[Test]
    public function tooShortFails(): void
    {
        $violation = $this->rule->validate('card', '411111111111', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tooLongFails(): void
    {
        $violation = $this->rule->validate('card', '41111111111111111111', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonNumericFails(): void
    {
        $violation = $this->rule->validate('card', 'abcdefghijklm', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('card', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('card', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new CreditCard(message: 'Invalid card.');
        $violation = $rule->validate('card', '0000', []);
        self::assertNotNull($violation);
        self::assertSame('Invalid card.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('credit_card', $this->rule->name());
    }

    #[Test]
    public function validMastercardPasses(): void
    {
        // 5500000000000004 — Luhn-valid Mastercard test number
        self::assertNull($this->rule->validate('card', '5500000000000004', []));
    }
}
