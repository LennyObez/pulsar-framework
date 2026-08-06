<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\HeaderValidator;

#[CoversClass(HeaderValidator::class)]
final class HeaderValidatorTest extends TestCase
{
    // ── Names ──────────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNames(): iterable
    {
        yield 'ordinary header' => ['Content-Type'];
        yield 'lowercase' => ['content-type'];
        yield 'single character' => ['X'];
        yield 'digits' => ['X-Http2-Push'];
        yield 'underscore is a tchar' => ['X_Custom'];

        // RFC 7230 §3.2.6 lists every one of these as a tchar. A validator
        // that only accepts letters, digits and `-` would reject real
        // headers such as `X-Foo!` or the `*` used by some vendors.
        yield 'full tchar alphabet' => ["!#$%&'*+-.^_`|~0123456789AZaz"];
    }

    #[DataProvider('validNames')]
    #[Test]
    public function validNamesAreAccepted(string $name): void
    {
        HeaderValidator::assertValidName($name);

        self::assertTrue(HeaderValidator::isValidName($name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['Bad Name'];
        yield 'colon' => ['X-Foo:'];
        yield 'carriage return' => ["X-Foo\r"];
        yield 'line feed' => ["X-Foo\n"];
        yield 'CRLF injection' => ["X-Foo\r\nX-Injected"];
        yield 'nul' => ["X-Foo\0"];
        yield 'leading whitespace' => [' X-Foo'];
        yield 'trailing whitespace' => ['X-Foo '];
        yield 'tab' => ["X-\tFoo"];
        yield 'parenthesis' => ['X-(Foo)'];
        yield 'comma' => ['X-Foo,X-Bar'];
        yield 'at sign' => ['X-Foo@Bar'];
        yield 'slash' => ['X-Foo/Bar'];
        yield 'non-ascii' => ['X-Föo'];
        yield 'high byte' => ["X-Foo\x80"];
    }

    #[DataProvider('invalidNames')]
    #[Test]
    public function invalidNamesAreRejected(string $name): void
    {
        self::assertFalse(HeaderValidator::isValidName($name));

        $this->expectException(InvalidArgumentException::class);

        HeaderValidator::assertValidName($name);
    }

    #[Test]
    public function aTrailingNewlineDoesNotSlipPastTheAnchor(): void
    {
        // PCRE's `$` matches before a final newline unless the pattern is
        // anchored with `D`. Dropping that modifier makes every name ending
        // in LF a valid token again.
        self::assertFalse(HeaderValidator::isValidName("X-Foo\n"));

        $this->expectException(InvalidArgumentException::class);

        HeaderValidator::assertValidName("X-Foo\n");
    }

    #[Test]
    public function emptyNameReportsItsOwnCause(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Header name must not be empty/');

        HeaderValidator::assertValidName('');
    }

    #[Test]
    public function rejectedNameIsQuotedInTheMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Header name "Bad Name" is not a valid RFC 7230 token/');

        HeaderValidator::assertValidName('Bad Name');
    }

    // ── Values ─────────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string}>
     */
    public static function validValues(): iterable
    {
        yield 'empty' => [''];
        yield 'ordinary' => ['application/json'];
        yield 'with parameters' => ['text/html; charset=utf-8'];
        yield 'internal spaces' => ['Mozilla/5.0 (X11; Linux x86_64)'];
        yield 'horizontal tab is field content' => ["a\tb"];
        yield 'obs-text high byte' => ["caf\xC3\xA9"];
        yield 'comma-separated list' => ['gzip, deflate, br'];
    }

    #[DataProvider('validValues')]
    #[Test]
    public function validValuesAreAccepted(string $value): void
    {
        HeaderValidator::assertValidValue($value);

        self::assertTrue(HeaderValidator::isValidValue($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'response splitting' => ["text/html\r\nX-Injected: value"];
        yield 'bare carriage return' => ["text/html\rX-Injected: value"];
        yield 'bare line feed' => ["text/html\nX-Injected: value"];
        yield 'nul' => ["text/html\0"];
        yield 'early body' => ["ok\r\n\r\n<script>alert(1)</script>"];

        // obs-fold (RFC 7230 §3.2.4) is deprecated and forbidden in
        // generated messages, so a folded continuation is not a way in.
        yield 'obs-fold continuation' => ["text/html\r\n charset=utf-8"];
    }

    #[DataProvider('invalidValues')]
    #[Test]
    public function invalidValuesAreRejected(string $value): void
    {
        self::assertFalse(HeaderValidator::isValidValue($value));

        $this->expectException(InvalidArgumentException::class);

        HeaderValidator::assertValidValue($value);
    }

    #[Test]
    public function listOfValuesIsCheckedElementWise(): void
    {
        HeaderValidator::assertValidValue(['gzip', 'deflate']);

        // Nothing to assert but the absence of a throw.
        self::addToAssertionCount(1);
    }

    #[Test]
    public function aSingleBadElementRejectsTheWholeList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Header value contains an illegal CR, LF, or NUL character/');

        HeaderValidator::assertValidValue(['gzip', "deflate\r\nX-Injected: 1", 'br']);
    }

    #[Test]
    public function emptyListPasses(): void
    {
        HeaderValidator::assertValidValue([]);

        self::addToAssertionCount(1);
    }

    // ── Error previews ─────────────────────────────────────────────────

    #[Test]
    public function controlCharactersAreEscapedInTheMessage(): void
    {
        // The rejected value reaches an error log. Emitting it raw would put
        // the injection payload into the log verbatim, so the preview is
        // ASCII-escaped.
        try {
            HeaderValidator::assertValidValue("ok\r\nX-Injected: yes");
            self::fail('expected the value to be rejected');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('ok\\x0D\\x0AX-Injected: yes', $e->getMessage());
            self::assertStringNotContainsString("\r", $e->getMessage());
            self::assertStringNotContainsString("\n", $e->getMessage());
        }
    }

    #[Test]
    public function longValuesAreTruncatedInTheMessage(): void
    {
        try {
            HeaderValidator::assertValidValue(str_repeat('A', 200) . "\r\n");
            self::fail('expected the value to be rejected');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString(str_repeat('A', 64) . '…', $e->getMessage());
            self::assertStringNotContainsString(str_repeat('A', 65), $e->getMessage());
        }
    }
}
