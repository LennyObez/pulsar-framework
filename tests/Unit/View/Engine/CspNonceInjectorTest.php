<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\CspNonceInjector;

#[CoversClass(CspNonceInjector::class)]
final class CspNonceInjectorTest extends TestCase
{
    #[Test]
    public function injectAddsNonceToScriptTags(): void
    {
        $input = '<script>console.log("hello");</script>';
        $output = CspNonceInjector::inject($input);

        self::assertStringContainsString('nonce="<?php echo htmlspecialchars', $output);
        self::assertStringContainsString('$__csp_nonce', $output);
    }

    #[Test]
    public function injectAddsNonceToStyleTags(): void
    {
        $input = '<style>body { color: red; }</style>';
        $output = CspNonceInjector::inject($input);

        self::assertStringContainsString('nonce="<?php echo htmlspecialchars', $output);
    }

    #[Test]
    public function injectSkipsTagsWithExistingNonce(): void
    {
        $input = '<script nonce="abc123">alert(1);</script>';
        $output = CspNonceInjector::inject($input);

        // Should NOT add a second nonce attribute
        self::assertSame(1, substr_count($output, 'nonce'));
    }

    #[Test]
    public function injectHandlesMultipleTags(): void
    {
        $input = '<script>a();</script><p>text</p><script>b();</script>';
        $output = CspNonceInjector::inject($input);

        self::assertSame(2, substr_count($output, '$__csp_nonce'));
    }

    #[Test]
    public function injectHandlesScriptWithAttributes(): void
    {
        $input = '<script type="module" defer>import x;</script>';
        $output = CspNonceInjector::inject($input);

        self::assertStringContainsString('nonce=', $output);
        self::assertStringContainsString('type="module"', $output);
        self::assertStringContainsString('defer', $output);
    }

    #[Test]
    public function injectHandlesMixedScriptAndStyle(): void
    {
        $input = '<script>x();</script><style>.a{}</style>';
        $output = CspNonceInjector::inject($input);

        self::assertSame(2, substr_count($output, '$__csp_nonce'));
    }

    #[Test]
    public function injectPreservesContentOutsideTags(): void
    {
        $input = '<p>Hello</p><script>x();</script><p>World</p>';
        $output = CspNonceInjector::inject($input);

        self::assertStringContainsString('<p>Hello</p>', $output);
        self::assertStringContainsString('<p>World</p>', $output);
    }

    #[Test]
    public function injectIsCaseInsensitive(): void
    {
        $input = '<SCRIPT>x();</SCRIPT><Style>.a{}</Style>';
        $output = CspNonceInjector::inject($input);

        self::assertSame(2, substr_count($output, '$__csp_nonce'));
    }

    #[Test]
    public function injectDoesNothingWithNoTags(): void
    {
        $input = '<p>No scripts or styles here</p>';
        $output = CspNonceInjector::inject($input);

        self::assertSame($input, $output);
    }
}
