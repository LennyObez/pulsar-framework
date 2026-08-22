<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Exception\CmsException;

#[CoversClass(CmsException::class)]
final class CmsExceptionFactoryMethodsTest extends TestCase
{
    #[Test]
    #[DataProvider('factoryMethodProvider')]
    public function factoryMethodReturnsExceptionWithMessage(string $method, array $args, string $expectedSubstring): void
    {
        /** @var CmsException $exception */
        $exception = CmsException::$method(...$args);

        self::assertInstanceOf(CmsException::class, $exception);
        self::assertStringContainsString($expectedSubstring, $exception->getMessage());
    }

    /**
     * @return array<string, array{string, list<mixed>, string}>
     */
    public static function factoryMethodProvider(): array
    {
        return [
            'contentNotFound' => ['contentNotFound', ['abc-123'], 'Content not found'],
            'revisionNotFound' => ['revisionNotFound', ['rev-1'], 'Revision not found'],
            'translationNotFound' => ['translationNotFound', ['c-1', 'fr'], 'Translation not found'],
            'invalidTransition' => ['invalidTransition', ['draft', 'archived'], 'Invalid status transition'],
            'circularParentReference' => ['circularParentReference', [], 'Circular parent reference'],
            'maxDepthExceeded' => ['maxDepthExceeded', [10], 'Maximum hierarchy depth'],
            'contentLocked' => ['contentLocked', ['c-1', 'user-1'], 'locked by user'],
            'slugConflict' => ['slugConflict', ['about-us', 'en'], 'already exists'],
            'invalidSlug' => ['invalidSlug', ['bad slug!'], 'Invalid slug format'],
            'sanitizerBypassDetected' => ['sanitizerBypassDetected', [], 'bypass detected'],
            'disallowedExtension' => ['disallowedExtension', ['exe'], 'not allowed'],
            'magicByteMismatch' => ['magicByteMismatch', ['image/png'], 'magic bytes'],
            'mimeTypeMismatch' => ['mimeTypeMismatch', ['text/plain', 'image/png'], 'does not match'],
            'imageDimensionsExceeded' => ['imageDimensionsExceeded', [5000, 3000, 4096, 2160], 'exceed maximum'],
            'pixelCountExceeded' => ['pixelCountExceeded', [100000000, 50000000], 'pixel count'],
            'embeddedPhpDetected' => ['embeddedPhpDetected', [], 'Embedded PHP code'],
            'invalidImageFile' => ['invalidImageFile', [], 'not a valid image'],
            'fileTooLarge' => ['fileTooLarge', [50000000, 10000000], 'exceeds maximum'],
            'unsafeSvgContent' => ['unsafeSvgContent', [], 'unsafe content'],
            'unsafePdfContent' => ['unsafePdfContent', ['JavaScript'], 'dangerous pattern'],
            'invalidPdfFile' => ['invalidPdfFile', [], 'not a valid PDF'],
            'mediaNotFound' => ['mediaNotFound', ['m-1'], 'Media asset not found'],
            'commentNotFound' => ['commentNotFound', ['cmt-1'], 'Comment not found'],
            'commentEditWindowExpired' => ['commentEditWindowExpired', ['cmt-1'], 'Edit window'],
            'searchQueryTooLong' => ['searchQueryTooLong', [500, 200], 'exceeds maximum'],
            'searchUnavailable' => ['searchUnavailable', ['timeout'], 'unavailable'],
            'redirectNotFound' => ['redirectNotFound', ['r-1'], 'Redirect not found'],
            'openRedirectBlocked' => ['openRedirectBlocked', ['http://evil.com'], 'Open redirect blocked'],
            'redirectChainDetected' => ['redirectChainDetected', ['/old'], 'chain detected'],
            'themeNotFound' => ['themeNotFound', ['my-theme'], 'Theme not found'],
            'themeAlreadyActive' => ['themeAlreadyActive', ['t-1'], 'already active'],
            'themeNotActive' => ['themeNotActive', ['t-1'], 'not active'],
            'themeIsActive' => ['themeIsActive', ['t-1'], 'Cannot delete active'],
            'themeManifestInvalid' => ['themeManifestInvalid', ['missing slug'], 'Invalid theme manifest'],
            'themeProvenanceFailed' => ['themeProvenanceFailed', ['bad sig'], 'provenance verification'],
            'themeExtractionFailed' => ['themeExtractionFailed', ['corrupt'], 'extraction failed'],
            'themeArchiveTooLarge' => ['themeArchiveTooLarge', [100, 50], 'exceeds maximum'],
            'themeFileCountExceeded' => ['themeFileCountExceeded', [1000, 500], 'exceeding maximum'],
            'themeZipSlipDetected' => ['themeZipSlipDetected', ['../etc/passwd'], 'Zip Slip'],
            'noPreviousTheme' => ['noPreviousTheme', [], 'No previous theme'],
            'pluginNotFound' => ['pluginNotFound', ['p-1'], 'Plugin not found'],
            'pluginAlreadyEnabled' => ['pluginAlreadyEnabled', ['p-1'], 'already enabled'],
            'pluginNotEnabled' => ['pluginNotEnabled', ['p-1'], 'not enabled'],
            'pluginIsEnabled' => ['pluginIsEnabled', ['p-1'], 'Cannot delete enabled'],
            'pluginManifestInvalid' => ['pluginManifestInvalid', ['no version'], 'Invalid plugin manifest'],
            'pluginProvenanceFailed' => ['pluginProvenanceFailed', ['bad sig'], 'provenance verification'],
            'pluginExtractionFailed' => ['pluginExtractionFailed', ['corrupt'], 'extraction failed'],
            'ssrfBlocked' => ['ssrfBlocked', ['http://internal', 'private IP'], 'SSRF protection'],
            'backupNotFound' => ['backupNotFound', ['b-1'], 'Backup not found'],
            'backupTampered' => ['backupTampered', ['b-1'], 'integrity check failed'],
            'importSizeLimitExceeded' => ['importSizeLimitExceeded', [100, 50], 'exceeds maximum'],
            'invalidImportFormat' => ['invalidImportFormat', ['bad header'], 'Invalid import format'],
            'twoFactorAlreadyEnabled' => ['twoFactorAlreadyEnabled', ['u-1'], 'already enabled'],
            'twoFactorNotEnabled' => ['twoFactorNotEnabled', ['u-1'], 'not enabled'],
            'twoFactorInvalidCode' => ['twoFactorInvalidCode', [], 'Invalid two-factor'],
            'userNotFound' => ['userNotFound', ['u-1'], 'user not found'],
            'invalidCmsRole' => ['invalidCmsRole', ['superadmin'], 'Invalid CMS role'],
            'orderNotFound' => ['orderNotFound', ['o-1'], 'Order not found'],
            'productNotFound' => ['productNotFound', ['p-1'], 'Product not found'],
            'invoiceNotFound' => ['invoiceNotFound', ['inv-1'], 'Invoice not found'],
            'promotionNotFound' => ['promotionNotFound', ['promo-1'], 'Promotion not found'],
            'insufficientStock' => ['insufficientStock', ['p-1', 10, 5], 'Insufficient stock'],
            'cartValidationFailed' => ['cartValidationFailed', ['empty cart'], 'Cart validation failed'],
            'paymentFailed' => ['paymentFailed', ['o-1', 'declined'], 'Payment failed'],
            'refundExceedsTotal' => ['refundExceedsTotal', [200, 100], 'exceeds order total'],
            'downloadExpired' => ['downloadExpired', ['dl-1'], 'Download entitlement expired'],
            'downloadLimitReached' => ['downloadLimitReached', ['dl-1'], 'Download limit reached'],
            'invalidWebhookSignature' => ['invalidWebhookSignature', [], 'Invalid webhook signature'],
            'invalidCouponCode' => ['invalidCouponCode', ['SAVE10'], 'Invalid or expired coupon'],
            'invalidBackupData' => ['invalidBackupData', ['corrupted'], 'Invalid backup data'],
            'concurrencyConflict' => ['concurrencyConflict', ['c-1', 5], 'Concurrency conflict'],
            'subscriberNotFound' => ['subscriberNotFound', ['sub-1'], 'subscriber not found'],
            'subscriberNotFoundByEmail' => ['subscriberNotFoundByEmail', ['test@example.com'], 'subscriber not found for email'],
            'subscriberAlreadyConfirmed' => ['subscriberAlreadyConfirmed', ['test@example.com'], 'already subscribed'],
            'subscriberNotUnsubscribed' => ['subscriberNotUnsubscribed', ['test@example.com'], 'not in unsubscribed'],
            'campaignNotFound' => ['campaignNotFound', ['camp-1'], 'campaign not found'],
            'campaignNotEditable' => ['campaignNotEditable', ['camp-1'], 'Draft status'],
            'campaignNotDeletable' => ['campaignNotDeletable', ['camp-1'], 'Draft or Cancelled'],
            'campaignNotSchedulable' => ['campaignNotSchedulable', ['camp-1'], 'Draft status'],
            'campaignNotCancellable' => ['campaignNotCancellable', ['camp-1'], 'Scheduled status'],
            'sendNotFound' => ['sendNotFound', ['s-1'], 'send record not found'],
            'invalidUnsubscribeSignature' => ['invalidUnsubscribeSignature', [], 'Invalid or expired unsubscribe'],
        ];
    }
}
