<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * Covers all static factory methods on CmsException not already tested
 * in CmsExceptionTest.
 */
#[CoversClass(CmsException::class)]
final class CmsExceptionFactoriesTest extends TestCase
{
    #[Test]
    public function revisionNotFound(): void
    {
        $e = CmsException::revisionNotFound('rev-99');
        self::assertStringContainsString('rev-99', $e->getMessage());
    }

    #[Test]
    public function disallowedExtension(): void
    {
        $e = CmsException::disallowedExtension('.exe');
        self::assertStringContainsString('.exe', $e->getMessage());
    }

    #[Test]
    public function magicByteMismatch(): void
    {
        $e = CmsException::magicByteMismatch('image/png');
        self::assertStringContainsString('image/png', $e->getMessage());
    }

    #[Test]
    public function mimeTypeMismatch(): void
    {
        $e = CmsException::mimeTypeMismatch('text/plain', 'image/jpeg');
        self::assertStringContainsString('text/plain', $e->getMessage());
        self::assertStringContainsString('image/jpeg', $e->getMessage());
    }

    #[Test]
    public function imageDimensionsExceeded(): void
    {
        $e = CmsException::imageDimensionsExceeded(5000, 5000, 4096, 4096);
        self::assertStringContainsString('5000', $e->getMessage());
        self::assertStringContainsString('4096', $e->getMessage());
    }

    #[Test]
    public function pixelCountExceeded(): void
    {
        $e = CmsException::pixelCountExceeded(100_000_000, 50_000_000);
        self::assertStringContainsString('100000000', $e->getMessage());
        self::assertStringContainsString('50000000', $e->getMessage());
    }

    #[Test]
    public function embeddedPhpDetected(): void
    {
        $e = CmsException::embeddedPhpDetected();
        self::assertStringContainsString('PHP', $e->getMessage());
    }

    #[Test]
    public function invalidImageFile(): void
    {
        $e = CmsException::invalidImageFile();
        self::assertStringContainsString('valid image', $e->getMessage());
    }

    #[Test]
    public function fileTooLarge(): void
    {
        $e = CmsException::fileTooLarge(20_000_000, 10_000_000);
        self::assertStringContainsString('20000000', $e->getMessage());
        self::assertStringContainsString('10000000', $e->getMessage());
    }

    #[Test]
    public function unsafeSvgContent(): void
    {
        $e = CmsException::unsafeSvgContent();
        self::assertStringContainsString('SVG', $e->getMessage());
    }

    #[Test]
    public function unsafePdfContent(): void
    {
        $e = CmsException::unsafePdfContent('/JavaScript');
        self::assertStringContainsString('/JavaScript', $e->getMessage());
    }

    #[Test]
    public function invalidPdfFile(): void
    {
        $e = CmsException::invalidPdfFile();
        self::assertStringContainsString('PDF', $e->getMessage());
    }

    #[Test]
    public function mediaNotFound(): void
    {
        $e = CmsException::mediaNotFound('media-01');
        self::assertStringContainsString('media-01', $e->getMessage());
    }

    #[Test]
    public function commentNotFound(): void
    {
        $e = CmsException::commentNotFound('cmt-01');
        self::assertStringContainsString('cmt-01', $e->getMessage());
    }

    #[Test]
    public function commentEditWindowExpired(): void
    {
        $e = CmsException::commentEditWindowExpired('cmt-02');
        self::assertStringContainsString('cmt-02', $e->getMessage());
    }

    #[Test]
    public function searchQueryTooLong(): void
    {
        $e = CmsException::searchQueryTooLong(500, 256);
        self::assertStringContainsString('500', $e->getMessage());
        self::assertStringContainsString('256', $e->getMessage());
    }

    #[Test]
    public function searchUnavailable(): void
    {
        $e = CmsException::searchUnavailable('timeout');
        self::assertStringContainsString('timeout', $e->getMessage());
    }

    #[Test]
    public function redirectNotFound(): void
    {
        $e = CmsException::redirectNotFound('redir-01');
        self::assertStringContainsString('redir-01', $e->getMessage());
    }

    #[Test]
    public function openRedirectBlocked(): void
    {
        $e = CmsException::openRedirectBlocked('http://evil.com');
        self::assertStringContainsString('http://evil.com', $e->getMessage());
    }

    #[Test]
    public function redirectChainDetected(): void
    {
        $e = CmsException::redirectChainDetected('/old-page');
        self::assertStringContainsString('/old-page', $e->getMessage());
    }

    #[Test]
    public function themeNotFound(): void
    {
        $e = CmsException::themeNotFound('theme-01');
        self::assertStringContainsString('theme-01', $e->getMessage());
    }

    #[Test]
    public function themeAlreadyActive(): void
    {
        $e = CmsException::themeAlreadyActive('theme-01');
        self::assertStringContainsString('theme-01', $e->getMessage());
    }

    #[Test]
    public function themeNotActive(): void
    {
        $e = CmsException::themeNotActive('theme-01');
        self::assertStringContainsString('theme-01', $e->getMessage());
    }

    #[Test]
    public function themeIsActive(): void
    {
        $e = CmsException::themeIsActive('theme-01');
        self::assertStringContainsString('theme-01', $e->getMessage());
    }

    #[Test]
    public function themeManifestInvalid(): void
    {
        $e = CmsException::themeManifestInvalid('missing name');
        self::assertStringContainsString('missing name', $e->getMessage());
    }

    #[Test]
    public function themeProvenanceFailed(): void
    {
        $e = CmsException::themeProvenanceFailed('bad sig');
        self::assertStringContainsString('bad sig', $e->getMessage());
    }

    #[Test]
    public function themeExtractionFailed(): void
    {
        $e = CmsException::themeExtractionFailed('zip error');
        self::assertStringContainsString('zip error', $e->getMessage());
    }

    #[Test]
    public function themeArchiveTooLarge(): void
    {
        $e = CmsException::themeArchiveTooLarge(200, 100);
        self::assertStringContainsString('200', $e->getMessage());
        self::assertStringContainsString('100', $e->getMessage());
    }

    #[Test]
    public function themeFileCountExceeded(): void
    {
        $e = CmsException::themeFileCountExceeded(1000, 500);
        self::assertStringContainsString('1000', $e->getMessage());
        self::assertStringContainsString('500', $e->getMessage());
    }

    #[Test]
    public function themeZipSlipDetected(): void
    {
        $e = CmsException::themeZipSlipDetected('../../etc/passwd');
        self::assertStringContainsString('../../etc/passwd', $e->getMessage());
    }

    #[Test]
    public function noPreviousTheme(): void
    {
        $e = CmsException::noPreviousTheme();
        self::assertStringContainsString('previous theme', $e->getMessage());
    }

    #[Test]
    public function pluginNotFound(): void
    {
        $e = CmsException::pluginNotFound('plugin-01');
        self::assertStringContainsString('plugin-01', $e->getMessage());
    }

    #[Test]
    public function pluginAlreadyEnabled(): void
    {
        $e = CmsException::pluginAlreadyEnabled('plugin-01');
        self::assertStringContainsString('plugin-01', $e->getMessage());
    }

    #[Test]
    public function pluginNotEnabled(): void
    {
        $e = CmsException::pluginNotEnabled('plugin-01');
        self::assertStringContainsString('plugin-01', $e->getMessage());
    }

    #[Test]
    public function pluginIsEnabled(): void
    {
        $e = CmsException::pluginIsEnabled('plugin-01');
        self::assertStringContainsString('plugin-01', $e->getMessage());
    }

    #[Test]
    public function pluginManifestInvalid(): void
    {
        $e = CmsException::pluginManifestInvalid('missing entry');
        self::assertStringContainsString('missing entry', $e->getMessage());
    }

    #[Test]
    public function pluginProvenanceFailed(): void
    {
        $e = CmsException::pluginProvenanceFailed('bad hash');
        self::assertStringContainsString('bad hash', $e->getMessage());
    }

    #[Test]
    public function pluginExtractionFailed(): void
    {
        $e = CmsException::pluginExtractionFailed('corrupt');
        self::assertStringContainsString('corrupt', $e->getMessage());
    }

    #[Test]
    public function ssrfBlocked(): void
    {
        $e = CmsException::ssrfBlocked('http://169.254.169.254', 'internal IP');
        self::assertStringContainsString('169.254.169.254', $e->getMessage());
        self::assertStringContainsString('internal IP', $e->getMessage());
    }

    #[Test]
    public function backupNotFound(): void
    {
        $e = CmsException::backupNotFound('bk-01');
        self::assertStringContainsString('bk-01', $e->getMessage());
    }

    #[Test]
    public function backupTampered(): void
    {
        $e = CmsException::backupTampered('bk-01');
        self::assertStringContainsString('bk-01', $e->getMessage());
    }

    #[Test]
    public function importSizeLimitExceeded(): void
    {
        $e = CmsException::importSizeLimitExceeded(100, 50);
        self::assertStringContainsString('100', $e->getMessage());
        self::assertStringContainsString('50', $e->getMessage());
    }

    #[Test]
    public function invalidImportFormat(): void
    {
        $e = CmsException::invalidImportFormat('unknown');
        self::assertStringContainsString('unknown', $e->getMessage());
    }

    #[Test]
    public function twoFactorAlreadyEnabled(): void
    {
        $e = CmsException::twoFactorAlreadyEnabled('user-01');
        self::assertStringContainsString('user-01', $e->getMessage());
    }

    #[Test]
    public function twoFactorNotEnabled(): void
    {
        $e = CmsException::twoFactorNotEnabled('user-01');
        self::assertStringContainsString('user-01', $e->getMessage());
    }

    #[Test]
    public function twoFactorInvalidCode(): void
    {
        $e = CmsException::twoFactorInvalidCode();
        self::assertStringContainsString('Invalid', $e->getMessage());
    }

    #[Test]
    public function userNotFound(): void
    {
        $e = CmsException::userNotFound('user-01');
        self::assertStringContainsString('user-01', $e->getMessage());
    }

    #[Test]
    public function invalidCmsRole(): void
    {
        $e = CmsException::invalidCmsRole('superadmin');
        self::assertStringContainsString('superadmin', $e->getMessage());
    }

    #[Test]
    public function orderNotFound(): void
    {
        $e = CmsException::orderNotFound('order-01');
        self::assertStringContainsString('order-01', $e->getMessage());
    }

    #[Test]
    public function productNotFound(): void
    {
        $e = CmsException::productNotFound('prod-01');
        self::assertStringContainsString('prod-01', $e->getMessage());
    }

    #[Test]
    public function invoiceNotFound(): void
    {
        $e = CmsException::invoiceNotFound('inv-01');
        self::assertStringContainsString('inv-01', $e->getMessage());
    }

    #[Test]
    public function promotionNotFound(): void
    {
        $e = CmsException::promotionNotFound('promo-01');
        self::assertStringContainsString('promo-01', $e->getMessage());
    }

    #[Test]
    public function insufficientStock(): void
    {
        $e = CmsException::insufficientStock('prod-01', 10, 3);
        self::assertStringContainsString('prod-01', $e->getMessage());
        self::assertStringContainsString('10', $e->getMessage());
        self::assertStringContainsString('3', $e->getMessage());
    }

    #[Test]
    public function cartValidationFailed(): void
    {
        $e = CmsException::cartValidationFailed('empty cart');
        self::assertStringContainsString('empty cart', $e->getMessage());
    }

    #[Test]
    public function paymentFailed(): void
    {
        $e = CmsException::paymentFailed('order-01', 'declined');
        self::assertStringContainsString('order-01', $e->getMessage());
        self::assertStringContainsString('declined', $e->getMessage());
    }

    #[Test]
    public function refundExceedsTotal(): void
    {
        $e = CmsException::refundExceedsTotal(5000, 3000);
        self::assertStringContainsString('5000', $e->getMessage());
        self::assertStringContainsString('3000', $e->getMessage());
    }

    #[Test]
    public function downloadExpired(): void
    {
        $e = CmsException::downloadExpired('dl-01');
        self::assertStringContainsString('dl-01', $e->getMessage());
    }

    #[Test]
    public function downloadLimitReached(): void
    {
        $e = CmsException::downloadLimitReached('dl-01');
        self::assertStringContainsString('dl-01', $e->getMessage());
    }

    #[Test]
    public function invalidWebhookSignature(): void
    {
        $e = CmsException::invalidWebhookSignature();
        self::assertStringContainsString('webhook', $e->getMessage());
    }

    #[Test]
    public function invalidCouponCode(): void
    {
        $e = CmsException::invalidCouponCode('SAVE20');
        self::assertStringContainsString('SAVE20', $e->getMessage());
    }

    #[Test]
    public function invalidBackupData(): void
    {
        $e = CmsException::invalidBackupData('corrupt');
        self::assertStringContainsString('corrupt', $e->getMessage());
    }

    #[Test]
    public function concurrencyConflict(): void
    {
        $e = CmsException::concurrencyConflict('content-01', 5);
        self::assertStringContainsString('content-01', $e->getMessage());
        self::assertStringContainsString('5', $e->getMessage());
    }
}
