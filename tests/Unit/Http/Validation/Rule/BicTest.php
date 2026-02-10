<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Bic;

#[CoversClass(Bic::class)]
final class BicTest extends TestCase
{
    private Bic $rule;

    protected function setUp(): void
    {
        $this->rule = new Bic();
    }

    #[Test]
    public function valid8CharBicPasses(): void
    {
        self::assertNull($this->rule->validate('bic', 'DEUTDEFF', []));
    }

    #[Test]
    public function valid11CharBicPasses(): void
    {
        self::assertNull($this->rule->validate('bic', 'DEUTDEFF500', []));
    }

    #[Test]
    public function lowercaseFails(): void
    {
        $violation = $this->rule->validate('bic', 'deutdeff', []);
        self::assertNotNull($violation);
        self::assertSame('bic', $violation->rule);
    }

    #[Test]
    public function tooShortFails(): void
    {
        $violation = $this->rule->validate('bic', 'DEUTDE', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tooLongFails(): void
    {
        $violation = $this->rule->validate('bic', 'DEUTDEFF5000', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function invalidCharactersFails(): void
    {
        $violation = $this->rule->validate('bic', 'DEUT12FF', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('bic', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new Bic(message: 'Bad BIC.');
        $violation = $rule->validate('bic', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Bad BIC.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('bic', $this->rule->name());
    }
}
