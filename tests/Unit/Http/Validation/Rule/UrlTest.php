<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Url;

#[CoversClass(Url::class)]
final class UrlTest extends TestCase
{
    private Url $rule;

    protected function setUp(): void
    {
        $this->rule = new Url();
    }

    #[Test]
    public function validHttpUrlPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'http://example.com', []));
    }

    #[Test]
    public function validHttpsUrlPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'https://example.com/path?q=1', []));
    }

    #[Test]
    public function validFtpUrlPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'ftp://files.example.com', []));
    }

    #[Test]
    public function invalidUrlFails(): void
    {
        $violation = $this->rule->validate('field', 'not-a-url', []);
        self::assertNotNull($violation);
        self::assertSame('url', $violation->rule);
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
    public function customMessageUsed(): void
    {
        $rule = new Url(message: 'Custom URL message');
        $violation = $rule->validate('field', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Custom URL message', $violation->message);
    }

    #[Test]
    public function missingSchemaFails(): void
    {
        $violation = $this->rule->validate('field', 'example.com', []);
        self::assertNotNull($violation);
    }
}
