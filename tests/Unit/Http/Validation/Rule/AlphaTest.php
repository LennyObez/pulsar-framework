<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Alpha;

#[CoversClass(Alpha::class)]
final class AlphaTest extends TestCase
{
    private Alpha $rule;

    protected function setUp(): void
    {
        $this->rule = new Alpha();
    }

    #[Test]
    public function validAlphaPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'HelloWorld', []));
    }

    #[Test]
    public function stringWithNumbersFails(): void
    {
        $violation = $this->rule->validate('field', 'abc123', []);
        self::assertNotNull($violation);
        self::assertSame('alpha', $violation->rule);
    }

    #[Test]
    public function stringWithSpacesFails(): void
    {
        $violation = $this->rule->validate('field', 'hello world', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('field', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function unicodeModeAcceptsAccentedCharacters(): void
    {
        $rule = new Alpha(unicode: true);
        self::assertNull($rule->validate('field', "caf\u{00E9}", []));
    }

    #[Test]
    public function nonUnicodeModeRejectsAccentedCharacters(): void
    {
        $violation = $this->rule->validate('field', "caf\u{00E9}", []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Alpha(message: 'Letters only');
        $violation = $rule->validate('field', '123', []);
        self::assertNotNull($violation);
        self::assertSame('Letters only', $violation->message);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $violation = $this->rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }
}
