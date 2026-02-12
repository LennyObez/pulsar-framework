<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Confirmed;

#[CoversClass(Confirmed::class)]
final class ConfirmedTest extends TestCase
{
    private Confirmed $rule;

    protected function setUp(): void
    {
        $this->rule = new Confirmed();
    }

    #[Test]
    public function matchingConfirmationPasses(): void
    {
        self::assertNull($this->rule->validate(
            'password',
            'secret123',
            ['password_confirmation' => 'secret123'],
        ));
    }

    #[Test]
    public function mismatchedConfirmationFails(): void
    {
        $violation = $this->rule->validate(
            'password',
            'secret123',
            ['password_confirmation' => 'different'],
        );
        self::assertNotNull($violation);
        self::assertSame('confirmed', $violation->rule);
    }

    #[Test]
    public function missingConfirmationFieldFails(): void
    {
        $violation = $this->rule->validate('password', 'secret123', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function strictComparisonFails(): void
    {
        $violation = $this->rule->validate('field', '1', ['field_confirmation' => 1]);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('password', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new Confirmed(message: 'No match.');
        $violation = $rule->validate('pw', 'a', ['pw_confirmation' => 'b']);
        self::assertNotNull($violation);
        self::assertSame('No match.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('confirmed', $this->rule->name());
    }
}
