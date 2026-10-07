<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Exception\CmsException;

final class CmsExceptionTest extends TestCase
{
    #[Test]
    public function content_not_found(): void
    {
        $e = CmsException::contentNotFound('c-123');

        self::assertStringContainsString('c-123', $e->getMessage());
    }

    #[Test]
    public function revision_not_found(): void
    {
        $e = CmsException::revisionNotFound('r-1');

        self::assertStringContainsString('r-1', $e->getMessage());
    }

    #[Test]
    public function translation_not_found(): void
    {
        $e = CmsException::translationNotFound('c-1', 'fr');

        self::assertStringContainsString('c-1', $e->getMessage());
        self::assertStringContainsString('fr', $e->getMessage());
    }

    #[Test]
    public function invalid_transition(): void
    {
        $e = CmsException::invalidTransition('draft', 'archived');

        self::assertStringContainsString('draft', $e->getMessage());
        self::assertStringContainsString('archived', $e->getMessage());
    }

    #[Test]
    public function circular_parent_reference(): void
    {
        $e = CmsException::circularParentReference();

        self::assertStringContainsString('Circular', $e->getMessage());
    }

    #[Test]
    public function max_depth_exceeded(): void
    {
        $e = CmsException::maxDepthExceeded(5);

        self::assertStringContainsString('5', $e->getMessage());
    }

    #[Test]
    public function content_locked(): void
    {
        $e = CmsException::contentLocked('c-1', 'user-2');

        self::assertStringContainsString('c-1', $e->getMessage());
        self::assertStringContainsString('user-2', $e->getMessage());
    }

    #[Test]
    public function slug_conflict(): void
    {
        $e = CmsException::slugConflict('hello-world', 'en');

        self::assertStringContainsString('hello-world', $e->getMessage());
        self::assertStringContainsString('en', $e->getMessage());
    }

    #[Test]
    public function invalid_slug(): void
    {
        $e = CmsException::invalidSlug('BAD SLUG');

        self::assertStringContainsString('BAD SLUG', $e->getMessage());
    }

    #[Test]
    public function sanitizer_bypass_detected(): void
    {
        $e = CmsException::sanitizerBypassDetected();

        self::assertStringContainsString('bypass', $e->getMessage());
    }

    #[Test]
    public function disallowed_extension(): void
    {
        $e = CmsException::disallowedExtension('.exe');

        self::assertStringContainsString('.exe', $e->getMessage());
    }

    #[Test]
    public function magic_byte_mismatch(): void
    {
        $e = CmsException::magicByteMismatch('image/png');

        self::assertStringContainsString('image/png', $e->getMessage());
    }

    #[Test]
    public function mime_type_mismatch(): void
    {
        $e = CmsException::mimeTypeMismatch('text/html', 'image/jpeg');

        self::assertStringContainsString('text/html', $e->getMessage());
        self::assertStringContainsString('image/jpeg', $e->getMessage());
    }

    #[Test]
    public function image_dimensions_exceeded(): void
    {
        $e = CmsException::imageDimensionsExceeded(5000, 4000, 4096, 4096);

        self::assertStringContainsString('5000', $e->getMessage());
        self::assertStringContainsString('4096', $e->getMessage());
    }

    #[Test]
    public function pixel_count_exceeded(): void
    {
        $e = CmsException::pixelCountExceeded(100_000_000, 50_000_000);

        self::assertStringContainsString('100000000', $e->getMessage());
    }

    #[Test]
    public function embedded_php_detected(): void
    {
        $e = CmsException::embeddedPhpDetected();

        self::assertStringContainsString('PHP', $e->getMessage());
    }

    #[Test]
    public function unsafe_svg_content(): void
    {
        $e = CmsException::unsafeSvgContent();

        self::assertStringContainsString('SVG', $e->getMessage());
    }

    #[Test]
    public function unsafe_pdf_content(): void
    {
        $e = CmsException::unsafePdfContent('/JavaScript');

        self::assertStringContainsString('/JavaScript', $e->getMessage());
    }

    #[Test]
    public function theme_not_found(): void
    {
        $e = CmsException::themeNotFound('theme-1');

        self::assertStringContainsString('theme-1', $e->getMessage());
    }

    #[Test]
    public function theme_already_active(): void
    {
        $e = CmsException::themeAlreadyActive('theme-1');

        self::assertStringContainsString('already active', $e->getMessage());
    }

    #[Test]
    public function theme_is_active_cannot_delete(): void
    {
        $e = CmsException::themeIsActive('theme-1');

        self::assertStringContainsString('Cannot delete', $e->getMessage());
    }

    #[Test]
    public function theme_zip_slip_detected(): void
    {
        $e = CmsException::themeZipSlipDetected('../../etc/passwd');

        self::assertStringContainsString('Zip Slip', $e->getMessage());
        self::assertStringContainsString('../../etc/passwd', $e->getMessage());
    }

    #[Test]
    public function plugin_not_found(): void
    {
        $e = CmsException::pluginNotFound('plugin-1');

        self::assertStringContainsString('plugin-1', $e->getMessage());
    }

    #[Test]
    public function plugin_lifecycle_errors(): void
    {
        self::assertStringContainsString('already enabled', CmsException::pluginAlreadyEnabled('p1')->getMessage());
        self::assertStringContainsString('not enabled', CmsException::pluginNotEnabled('p1')->getMessage());
        self::assertStringContainsString('Cannot delete', CmsException::pluginIsEnabled('p1')->getMessage());
    }

    #[Test]
    public function concurrency_conflict(): void
    {
        $e = CmsException::concurrencyConflict('c-1', 3);

        self::assertStringContainsString('c-1', $e->getMessage());
        self::assertStringContainsString('3', $e->getMessage());
    }

    #[Test]
    public function open_redirect_blocked(): void
    {
        $e = CmsException::openRedirectBlocked('http://evil.com');

        self::assertStringContainsString('Open redirect', $e->getMessage());
        self::assertStringContainsString('evil.com', $e->getMessage());
    }

    #[Test]
    public function ssrf_blocked(): void
    {
        $e = CmsException::ssrfBlocked('http://169.254.169.254', 'private IP');

        self::assertStringContainsString('SSRF', $e->getMessage());
        self::assertStringContainsString('169.254.169.254', $e->getMessage());
    }

    #[Test]
    public function backup_tampered(): void
    {
        $e = CmsException::backupTampered('bk-1');

        self::assertStringContainsString('integrity', $e->getMessage());
        self::assertStringContainsString('bk-1', $e->getMessage());
    }
}
