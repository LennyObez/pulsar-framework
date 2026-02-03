<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Email;

#[CoversClass(Email::class)]
final class EmailTest extends TestCase
{
    private Email $rule;

    protected function setUp(): void
    {
        $this->rule = new Email();
    }

    #[Test]
    public function validEmailPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'user@example.com', []));
    }

    #[Test]
    public function emailWithSubdomainPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'user@sub.example.com', []));
    }

    #[Test]
    public function invalidEmailFails(): void
    {
        $violation = $this->rule->validate('field', 'not-an-email', []);
        self::assertNotNull($violation);
        self::assertSame('email', $violation->rule);
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
    public function missingAtSymbolFails(): void
    {
        $violation = $this->rule->validate('field', 'userexample.com', []);
        self::assertNotNull($violation);
    }
}
