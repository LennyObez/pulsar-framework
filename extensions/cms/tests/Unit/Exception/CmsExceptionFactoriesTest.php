<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Exception\CmsException;

#[CoversClass(CmsException::class)]
final class CmsExceptionFactoriesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<mixed>, string}>
     */
    public static function factoryMethodProvider(): iterable
    {
        yield 'contentNotFound' => ['contentNotFound', ['abc-123'], 'Content not found: abc-123'];
        yield 'revisionNotFound' => ['revisionNotFound', ['rev-1'], 'Revision not found: rev-1'];
        yield 'translationNotFound' => ['translationNotFound', ['c1', 'fr'], 'Translation not found for content c1 in locale fr'];
        yield 'invalidTransition' => ['invalidTransition', ['draft', 'archived'], "Invalid status transition from 'draft' to 'archived'"];
        yield 'circularParentReference' => ['circularParentReference', [], 'Circular parent reference detected'];
        yield 'maxDepthExceeded' => ['maxDepthExceeded', [10], 'Maximum hierarchy depth of 10 exceeded'];
        yield 'contentLocked' => ['contentLocked', ['c1', 'u1'], 'Content c1 is locked by user u1'];
        yield 'slugConflict' => ['slugConflict', ['hello', 'en'], "Slug 'hello' already exists for locale 'en'"];
        yield 'invalidSlug' => ['invalidSlug', ['bad slug!'], "Invalid slug format: 'bad slug!'"];
        yield 'sanitizerBypassDetected' => ['sanitizerBypassDetected', [], 'SafeHtmlPolicy bypass detected'];
        yield 'disallowedExtension' => ['disallowedExtension', ['exe'], "File extension 'exe' is not allowed"];
        yield 'magicByteMismatch' => ['magicByteMismatch', ['image/png'], "magic bytes for MIME type 'image/png'"];
        yield 'mimeTypeMismatch' => ['mimeTypeMismatch', ['text/plain', 'image/png'], "Detected MIME type 'text/plain' does not match declared type 'image/png'"];
        yield 'imageDimensionsExceeded' => ['imageDimensionsExceeded', [5000, 5000, 4096, 4096], '5000x5000 exceed maximum 4096x4096'];
        yield 'pixelCountExceeded' => ['pixelCountExceeded', [25000000, 20000000], 'pixel count 25000000 exceeds maximum 20000000'];
        yield 'embeddedPhpDetected' => ['embeddedPhpDetected', [], 'Embedded PHP code detected'];
        yield 'invalidImageFile' => ['invalidImageFile', [], 'not a valid image'];
        yield 'fileTooLarge' => ['fileTooLarge', [10000, 5000], 'File size 10000 bytes exceeds maximum 5000 bytes'];
        yield 'unsafeSvgContent' => ['unsafeSvgContent', [], 'unsafe content'];
        yield 'unsafePdfContent' => ['unsafePdfContent', ['/JavaScript'], '/JavaScript'];
        yield 'invalidPdfFile' => ['invalidPdfFile', [], 'not a valid PDF'];
        yield 'mediaNotFound' => ['mediaNotFound', ['m1'], 'Media asset not found: m1'];
        yield 'commentNotFound' => ['commentNotFound', ['c1'], 'Comment not found: c1'];
        yield 'commentEditWindowExpired' => ['commentEditWindowExpired', ['c1'], 'Edit window has expired for comment: c1'];
        yield 'searchQueryTooLong' => ['searchQueryTooLong', [500, 200], 'Search query length 500 exceeds maximum of 200'];
        yield 'searchUnavailable' => ['searchUnavailable', ['timeout'], 'Search service unavailable: timeout'];
        yield 'redirectNotFound' => ['redirectNotFound', ['r1'], 'Redirect not found: r1'];
        yield 'openRedirectBlocked' => ['openRedirectBlocked', ['http://evil.com'], 'Open redirect blocked'];
        yield 'redirectChainDetected' => ['redirectChainDetected', ['/old'], 'Redirect chain detected'];
        yield 'themeNotFound' => ['themeNotFound', ['t1'], 'Theme not found: t1'];
        yield 'themeAlreadyActive' => ['themeAlreadyActive', ['t1'], 'already active'];
        yield 'themeNotActive' => ['themeNotActive', ['t1'], 'not active'];
        yield 'themeIsActive' => ['themeIsActive', ['t1'], 'Cannot delete active theme'];
        yield 'themeManifestInvalid' => ['themeManifestInvalid', ['missing slug'], 'Invalid theme manifest: missing slug'];
        yield 'themeProvenanceFailed' => ['themeProvenanceFailed', ['bad sig'], 'provenance verification failed'];
        yield 'themeExtractionFailed' => ['themeExtractionFailed', ['corrupt'], 'extraction failed'];
        yield 'themeArchiveTooLarge' => ['themeArchiveTooLarge', [100, 50], 'exceeds maximum 50 bytes'];
        yield 'themeFileCountExceeded' => ['themeFileCountExceeded', [500, 100], '500 files, exceeding maximum of 100'];
        yield 'themeZipSlipDetected' => ['themeZipSlipDetected', ['../../etc'], 'Zip Slip path traversal'];
        yield 'noPreviousTheme' => ['noPreviousTheme', [], 'No previous theme'];
        yield 'pluginNotFound' => ['pluginNotFound', ['p1'], 'Plugin not found: p1'];
        yield 'pluginAlreadyEnabled' => ['pluginAlreadyEnabled', ['p1'], 'already enabled'];
        yield 'pluginNotEnabled' => ['pluginNotEnabled', ['p1'], 'not enabled'];
        yield 'pluginIsEnabled' => ['pluginIsEnabled', ['p1'], 'Cannot delete enabled'];
        yield 'pluginManifestInvalid' => ['pluginManifestInvalid', ['bad'], 'Invalid plugin manifest'];
        yield 'pluginProvenanceFailed' => ['pluginProvenanceFailed', ['sig'], 'provenance verification failed'];
        yield 'pluginExtractionFailed' => ['pluginExtractionFailed', ['err'], 'extraction failed'];
        yield 'ssrfBlocked' => ['ssrfBlocked', ['http://internal', 'private IP'], 'SSRF protection blocked'];
        yield 'backupNotFound' => ['backupNotFound', ['b1'], 'Backup not found'];
        yield 'backupTampered' => ['backupTampered', ['b1'], 'integrity check failed'];
        yield 'importSizeLimitExceeded' => ['importSizeLimitExceeded', [100, 50], 'exceeds maximum of 50 bytes'];
        yield 'invalidImportFormat' => ['invalidImportFormat', ['bad CSV'], 'Invalid import format'];
        yield 'twoFactorAlreadyEnabled' => ['twoFactorAlreadyEnabled', ['u1'], 'already enabled'];
        yield 'twoFactorNotEnabled' => ['twoFactorNotEnabled', ['u1'], 'not enabled'];
        yield 'twoFactorInvalidCode' => ['twoFactorInvalidCode', [], 'Invalid two-factor'];
        yield 'userNotFound' => ['userNotFound', ['u1'], 'CMS user not found'];
        yield 'invalidCmsRole' => ['invalidCmsRole', ['superadmin'], 'Invalid CMS role'];
        yield 'orderNotFound' => ['orderNotFound', ['o1'], 'Order not found'];
        yield 'productNotFound' => ['productNotFound', ['p1'], 'Product not found'];
        yield 'invoiceNotFound' => ['invoiceNotFound', ['i1'], 'Invoice not found'];
        yield 'promotionNotFound' => ['promotionNotFound', ['pr1'], 'Promotion not found'];
        yield 'insufficientStock' => ['insufficientStock', ['p1', 10, 5], 'requested 10, available 5'];
        yield 'cartValidationFailed' => ['cartValidationFailed', ['empty cart'], 'Cart validation failed'];
        yield 'paymentFailed' => ['paymentFailed', ['o1', 'declined'], 'Payment failed for order o1'];
        yield 'refundExceedsTotal' => ['refundExceedsTotal', [200, 100], 'Refund amount 200 exceeds order total 100'];
        yield 'downloadExpired' => ['downloadExpired', ['d1'], 'Download entitlement expired'];
        yield 'downloadLimitReached' => ['downloadLimitReached', ['d1'], 'Download limit reached'];
        yield 'invalidWebhookSignature' => ['invalidWebhookSignature', [], 'Invalid webhook signature'];
        yield 'invalidCouponCode' => ['invalidCouponCode', ['BADCODE'], 'Invalid or expired coupon code'];
        yield 'invalidBackupData' => ['invalidBackupData', ['corrupt'], 'Invalid backup data'];
        yield 'concurrencyConflict' => ['concurrencyConflict', ['c1', 5], 'expected version 5'];
        yield 'subscriberNotFound' => ['subscriberNotFound', ['s1'], 'subscriber not found: s1'];
        yield 'subscriberNotFoundByEmail' => ['subscriberNotFoundByEmail', ['a@b.com'], 'subscriber not found for email'];
        yield 'subscriberAlreadyConfirmed' => ['subscriberAlreadyConfirmed', ['a@b.com'], 'already subscribed and confirmed'];
        yield 'subscriberNotUnsubscribed' => ['subscriberNotUnsubscribed', ['a@b.com'], 'not in unsubscribed status'];
        yield 'campaignNotFound' => ['campaignNotFound', ['c1'], 'campaign not found'];
        yield 'campaignNotEditable' => ['campaignNotEditable', ['c1'], 'only be edited in Draft'];
        yield 'campaignNotDeletable' => ['campaignNotDeletable', ['c1'], 'only be deleted in Draft or Cancelled'];
        yield 'campaignNotSchedulable' => ['campaignNotSchedulable', ['c1'], 'only be scheduled from Draft'];
        yield 'campaignNotCancellable' => ['campaignNotCancellable', ['c1'], 'only be cancelled from Scheduled'];
        yield 'sendNotFound' => ['sendNotFound', ['s1'], 'send record not found'];
        yield 'invalidUnsubscribeSignature' => ['invalidUnsubscribeSignature', [], 'Invalid or expired unsubscribe link'];
    }

    #[Test]
    #[DataProvider('factoryMethodProvider')]
    public function factory_method_creates_exception_with_message(string $method, array $args, string $expectedSubstring): void
    {
        /** @var CmsException $exception */
        $exception = CmsException::$method(...$args);

        self::assertInstanceOf(CmsException::class, $exception);
        self::assertStringContainsString($expectedSubstring, $exception->getMessage());
    }
}
