<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ---- DataProvider-driven valid cases ----

    /**
     * @return iterable<string, array{string}>
     */
    public static function validEmailProvider(): iterable
    {
        yield 'simple' => ['user@example.com'];
        yield 'subdomain' => ['user@sub.example.com'];
        yield 'plus-addressing' => ['user+tag@example.com'];
        yield 'dotted local' => ['first.last@example.com'];
        yield 'numeric local' => ['123@example.com'];
        yield 'long TLD' => ['user@example.museum'];
        yield 'hyphenated domain' => ['user@my-domain.co.uk'];
    }

    #[Test]
    #[DataProvider('validEmailProvider')]
    public function validEmailPasses(string $email): void
    {
        self::assertNull($this->rule->validate('field', $email, []));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidEmailProvider(): iterable
    {
        yield 'missing @' => ['userexample.com'];
        yield 'random string' => ['not-an-email'];
        yield 'empty string' => [''];
        yield '@ only' => ['@'];
        yield 'missing domain' => ['user@'];
        yield 'missing local' => ['@example.com'];
        yield 'double @' => ['user@@example.com'];
        yield 'spaces' => ['user @example.com'];
    }

    #[Test]
    #[DataProvider('invalidEmailProvider')]
    public function invalidEmailFails(mixed $value): void
    {
        $violation = $this->rule->validate('field', $value, []);
        self::assertNotNull($violation);
        self::assertSame('email', $violation->rule);
    }

    // ---- Existing single-case tests kept for clarity ----

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }
}
