<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\LiveCss\CspHashComputer;
use Pulsar\Extension\Cms\Internal\LiveCss\LiveCssInjector;

use function base64_encode;
use function hash;

/**
 * Security tests verifying CSP uses hash-based directives, never unsafe-inline.
 *
 * Ensures the live CSS editor produces sha256 hashes for style-src CSP directives
 * rather than relying on 'unsafe-inline' which would weaken XSS protection.
 */
#[CoversClass(CspHashComputer::class)]
#[CoversClass(LiveCssInjector::class)]
final class CspHashIntegrityTest extends TestCase
{
    private CspHashComputer $hashComputer;
    private LiveCssInjector $injector;

    protected function setUp(): void
    {
        $this->hashComputer = new CspHashComputer();
        $this->injector = new LiveCssInjector($this->hashComputer);
    }

    #[Test]
    public function cspDirectiveUsesSha256HashNotUnsafeInline(): void
    {
        $css = ':root { --color-primary: #ff0000; }';
        $directive = $this->injector->generateCspDirective($css);

        self::assertStringContainsString("'sha256-", $directive);
        self::assertStringNotContainsString('unsafe-inline', $directive);
        self::assertStringStartsWith("style-src 'self'", $directive);
    }

    #[Test]
    public function cspHashMatchesContent(): void
    {
        $css = ':root { --color-primary: #ff0000; }';
        $computedHash = $this->hashComputer->computeHash($css);

        $expectedHash = 'sha256-' . base64_encode(hash('sha256', $css, true));

        self::assertSame($expectedHash, $computedHash);
    }

    #[Test]
    public function differentContentProducesDifferentHash(): void
    {
        $hash1 = $this->hashComputer->computeHash('body { color: red; }');
        $hash2 = $this->hashComputer->computeHash('body { color: blue; }');

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function identicalContentProducesIdenticalHash(): void
    {
        $css = 'body { background: #000; }';
        $hash1 = $this->hashComputer->computeHash($css);
        $hash2 = $this->hashComputer->computeHash($css);

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function cspDirectiveContainsSelf(): void
    {
        $css = 'body { color: green; }';
        $directive = $this->injector->generateCspDirective($css);

        self::assertStringContainsString("'self'", $directive);
    }

    #[Test]
    public function cspHashPrefixFormat(): void
    {
        $hash = $this->hashComputer->computeHash('p { margin: 0; }');

        self::assertStringStartsWith('sha256-', $hash);
        // After the prefix, should be valid base64
        $base64Part = substr($hash, 7);
        self::assertNotEmpty($base64Part);
        self::assertMatchesRegularExpression('#^[A-Za-z0-9+/]+=*$#', $base64Part);
    }

    #[Test]
    public function emptyCssStillProducesValidHash(): void
    {
        $hash = $this->hashComputer->computeHash('');

        self::assertStringStartsWith('sha256-', $hash);
        self::assertNotEmpty($hash);
    }

    #[Test]
    public function xssPayloadInCssStillHashedNotInline(): void
    {
        // Even if CSS contains suspicious content, the CSP approach is hash-based
        $maliciousCss = '</style><script>alert(1)</script><style>';
        $directive = $this->injector->generateCspDirective($maliciousCss);

        self::assertStringContainsString("'sha256-", $directive);
        self::assertStringNotContainsString('unsafe-inline', $directive);
    }
}
