<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Ascii;

#[CoversClass(Ascii::class)]
final class AsciiTest extends TestCase
{
    private Ascii $rule;

    protected function setUp(): void
    {
        $this->rule = new Ascii();
    }

    #[Test]
    public function asciiStringPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'Hello World 123!', []));
    }

    #[Test]
    public function emptyStringPasses(): void
    {
        self::assertNull($this->rule->validate('field', '', []));
    }

    #[Test]
    public function unicodeCharacterFails(): void
    {
        $violation = $this->rule->validate('field', "caf\u{00E9}", []);
        self::assertNotNull($violation);
        self::assertSame('ascii', $violation->rule);
    }

    #[Test]
    public function emojiStringFails(): void
    {
        $violation = $this->rule->validate('field', "hello \u{1F600}", []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Ascii(message: 'ASCII only');
        $violation = $rule->validate('field', "\u{00E9}", []);
        self::assertNotNull($violation);
        self::assertSame('ASCII only', $violation->message);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $violation = $this->rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }
}
