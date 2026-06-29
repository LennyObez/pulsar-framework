<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\SafeRedirect;

#[CoversClass(SafeRedirect::class)]
final class SafeRedirectTest extends TestCase
{
    #[Test]
    public function allowsRelativePathStartingWithSlash(): void
    {
        $result = SafeRedirect::validate('/dashboard');

        self::assertSame('/dashboard', $result);
    }

    #[Test]
    public function allowsRelativePathWithQuery(): void
    {
        $result = SafeRedirect::validate('/search?q=test');

        self::assertSame('/search?q=test', $result);
    }

    #[Test]
    public function rejectsEmptyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');
        SafeRedirect::validate('');
    }

    #[Test]
    public function rejectsProtocolRelativeUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Protocol-relative');
        SafeRedirect::validate('//evil.com/phish');
    }

    #[Test]
    public function rejectsJavascriptScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relative path');
        SafeRedirect::validate('javascript:alert(1)');
    }

    #[Test]
    public function rejectsDataScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relative path');
        SafeRedirect::validate('data:text/html,<script>alert(1)</script>');
    }

    #[Test]
    public function rejectsAbsoluteUrlWithoutAllowedHosts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require a non-empty allowed-hosts list');
        SafeRedirect::validate('https://example.com/path');
    }

    #[Test]
    public function rejectsAbsoluteUrlWithUnallowedHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not allowed');
        SafeRedirect::validate('https://evil.com/phish', ['example.com']);
    }

    #[Test]
    public function allowsAbsoluteUrlWithAllowedHost(): void
    {
        $result = SafeRedirect::validate('https://example.com/path', ['example.com']);

        self::assertSame('https://example.com/path', $result);
    }

    #[Test]
    public function allowsHttpAbsoluteUrlWithAllowedHost(): void
    {
        $result = SafeRedirect::validate('http://app.example.com/', ['app.example.com']);

        self::assertSame('http://app.example.com/', $result);
    }

    #[Test]
    public function rejectsMalformedUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relative path');
        SafeRedirect::validate('not-a-url');
    }

    /**
     * Browsers normalise "\" to "/" before resolving the authority, so
     * `/\evil.com` becomes the protocol-relative `//evil.com` and navigates
     * off-site. The "//" prefix check alone misses this; the backslash
     * spelling must be rejected too.
     */
    #[Test]
    public function rejectsBackslashObfuscatedProtocolRelativeUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Protocol-relative');
        SafeRedirect::validate('/\\evil.com/phish');
    }

    #[Test]
    public function rejectsLeadingDoubleBackslashUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Protocol-relative');
        SafeRedirect::validate('\\\\evil.com/phish');
    }

    #[Test]
    public function rejectsBackslashSlashProtocolRelativeUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Protocol-relative');
        SafeRedirect::validate('/\\/evil.com');
    }

    #[Test]
    public function rejectsCarriageReturnLineFeedForHeaderInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');
        SafeRedirect::validate("/dashboard\r\nSet-Cookie: session=hijacked");
    }

    #[Test]
    public function stillAllowsBackslashInsideRelativePath(): void
    {
        // A backslash that is NOT part of a leading authority is harmless —
        // the browser keeps it same-origin. Do not over-reject.
        $result = SafeRedirect::validate('/files/path\\to\\doc');

        self::assertSame('/files/path\\to\\doc', $result);
    }
}
