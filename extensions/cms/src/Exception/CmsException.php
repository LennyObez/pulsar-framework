<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Base exception for all CMS errors.
 */
#[Api(since: '1.0.0')]
class CmsException extends RuntimeException
{
    public static function contentNotFound(string $id): self
    {
        return new self("Content not found: $id");
    }

    public static function revisionNotFound(string $id): self
    {
        return new self("Revision not found: $id");
    }

    public static function translationNotFound(string $contentId, string $locale): self
    {
        return new self("Translation not found for content $contentId in locale $locale");
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Invalid status transition from '$from' to '$to'");
    }

    public static function circularParentReference(): self
    {
        return new self('Circular parent reference detected');
    }

    public static function maxDepthExceeded(int $maxDepth): self
    {
        return new self("Maximum hierarchy depth of $maxDepth exceeded");
    }

    public static function contentLocked(string $contentId, string $lockedBy): self
    {
        return new self("Content $contentId is locked by user $lockedBy");
    }

    public static function slugConflict(string $slug, string $locale): self
    {
        return new self("Slug '$slug' already exists for locale '$locale'");
    }

    public static function invalidSlug(string $slug): self
    {
        return new self("Invalid slug format: '$slug'");
    }

    public static function sanitizerBypassDetected(): self
    {
        return new self('SafeHtmlPolicy bypass detected — input was escaped as plaintext');
    }

    public static function disallowedExtension(string $extension): self
    {
        return new self("File extension '$extension' is not allowed");
    }

    public static function magicByteMismatch(string $mimeType): self
    {
        return new self("File content does not match expected magic bytes for MIME type '$mimeType'");
    }

    public static function mimeTypeMismatch(string $detected, string $declared): self
    {
        return new self("Detected MIME type '$detected' does not match declared type '$declared'");
    }

    public static function imageDimensionsExceeded(int $width, int $height, int $maxWidth, int $maxHeight): self
    {
        return new self("Image dimensions {$width}x$height exceed maximum {$maxWidth}x$maxHeight");
    }

    public static function pixelCountExceeded(int $pixelCount, int $maxPixelCount): self
    {
        return new self("Image pixel count $pixelCount exceeds maximum $maxPixelCount");
    }

    public static function embeddedPhpDetected(): self
    {
        return new self('Embedded PHP code detected in image file');
    }

    public static function invalidImageFile(): self
    {
        return new self('File is not a valid image');
    }

    public static function fileTooLarge(int $fileSize, int $maxSize): self
    {
        return new self("File size $fileSize bytes exceeds maximum $maxSize bytes");
    }

    public static function unsafeSvgContent(): self
    {
        return new self('SVG contains potentially unsafe content');
    }

    public static function unsafePdfContent(string $pattern): self
    {
        return new self("PDF contains dangerous pattern: $pattern");
    }

    public static function invalidPdfFile(): self
    {
        return new self('File is not a valid PDF document');
    }

    public static function mediaNotFound(string $id): self
    {
        return new self("Media asset not found: $id");
    }

    public static function commentNotFound(string $id): self
    {
        return new self("Comment not found: $id");
    }

    public static function commentEditWindowExpired(string $id): self
    {
        return new self("Edit window has expired for comment: $id");
    }

    public static function searchQueryTooLong(int $length, int $maxLength): self
    {
        return new self("Search query length $length exceeds maximum of $maxLength characters");
    }

    public static function searchUnavailable(string $reason): self
    {
        return new self("Search service unavailable: $reason");
    }

    public static function redirectNotFound(string $id): self
    {
        return new self("Redirect not found: $id");
    }

    public static function openRedirectBlocked(string $url): self
    {
        return new self("Open redirect blocked — unsafe target URL: $url");
    }

    public static function redirectChainDetected(string $fromPath): self
    {
        return new self("Redirect chain detected starting at: $fromPath");
    }

    public static function themeNotFound(string $id): self
    {
        return new self("Theme not found: $id");
    }

    public static function themeAlreadyActive(string $id): self
    {
        return new self("Theme is already active: $id");
    }

    public static function themeNotActive(string $id): self
    {
        return new self("Theme is not active: $id");
    }

    public static function themeIsActive(string $id): self
    {
        return new self("Cannot delete active theme: $id");
    }

    public static function themeManifestInvalid(string $reason): self
    {
        return new self("Invalid theme manifest: $reason");
    }

    public static function themeProvenanceFailed(string $reason): self
    {
        return new self("Theme provenance verification failed: $reason");
    }

    public static function themeExtractionFailed(string $reason): self
    {
        return new self("Theme archive extraction failed: $reason");
    }

    public static function themeArchiveTooLarge(int $size, int $maxSize): self
    {
        return new self("Theme archive size $size bytes exceeds maximum $maxSize bytes");
    }

    public static function themeFileCountExceeded(int $count, int $maxCount): self
    {
        return new self("Theme archive contains $count files, exceeding maximum of $maxCount");
    }

    public static function themeZipSlipDetected(string $entryName): self
    {
        return new self("Zip Slip path traversal detected in entry: $entryName");
    }

    public static function noPreviousTheme(): self
    {
        return new self('No previous theme available for rollback');
    }

    public static function pluginNotFound(string $id): self
    {
        return new self("Plugin not found: $id");
    }

    public static function pluginAlreadyEnabled(string $id): self
    {
        return new self("Plugin is already enabled: $id");
    }

    public static function pluginNotEnabled(string $id): self
    {
        return new self("Plugin is not enabled: $id");
    }

    public static function pluginIsEnabled(string $id): self
    {
        return new self("Cannot delete enabled plugin: $id");
    }

    public static function pluginManifestInvalid(string $reason): self
    {
        return new self("Invalid plugin manifest: $reason");
    }

    public static function pluginProvenanceFailed(string $reason): self
    {
        return new self("Plugin provenance verification failed: $reason");
    }

    public static function pluginExtractionFailed(string $reason): self
    {
        return new self("Plugin archive extraction failed: $reason");
    }

    public static function ssrfBlocked(string $url, string $reason): self
    {
        return new self("SSRF protection blocked request to $url: $reason");
    }

    public static function backupNotFound(string $id): self
    {
        return new self("Backup not found: $id");
    }

    public static function backupTampered(string $id): self
    {
        return new self("Backup integrity check failed — hash mismatch for backup: $id");
    }

    public static function importSizeLimitExceeded(int $size, int $maxSize): self
    {
        return new self("Import file size $size bytes exceeds maximum of $maxSize bytes");
    }

    public static function invalidImportFormat(string $reason): self
    {
        return new self("Invalid import format: $reason");
    }

    public static function twoFactorAlreadyEnabled(string $userId): self
    {
        return new self("Two-factor authentication is already enabled for user: $userId");
    }

    public static function twoFactorNotEnabled(string $userId): self
    {
        return new self("Two-factor authentication is not enabled for user: $userId");
    }

    public static function twoFactorInvalidCode(): self
    {
        return new self('Invalid two-factor authentication code');
    }

    public static function userNotFound(string $id): self
    {
        return new self("CMS user not found: $id");
    }

    public static function invalidCmsRole(string $role): self
    {
        return new self("Invalid CMS role: $role");
    }

    public static function orderNotFound(string $id): self
    {
        return new self("Order not found: $id");
    }

    public static function productNotFound(string $id): self
    {
        return new self("Product not found: $id");
    }

    public static function invoiceNotFound(string $id): self
    {
        return new self("Invoice not found: $id");
    }

    public static function promotionNotFound(string $id): self
    {
        return new self("Promotion not found: $id");
    }

    public static function insufficientStock(string $productId, int $requested, int $available): self
    {
        return new self("Insufficient stock for product $productId: requested $requested, available $available");
    }

    public static function cartValidationFailed(string $reason): self
    {
        return new self("Cart validation failed: $reason");
    }

    public static function paymentFailed(string $orderId, string $reason): self
    {
        return new self("Payment failed for order $orderId: $reason");
    }

    public static function refundExceedsTotal(int $refundAmount, int $orderTotal): self
    {
        return new self("Refund amount $refundAmount exceeds order total $orderTotal");
    }

    public static function downloadExpired(string $downloadId): self
    {
        return new self("Download entitlement expired: $downloadId");
    }

    public static function downloadLimitReached(string $downloadId): self
    {
        return new self("Download limit reached for entitlement: $downloadId");
    }

    public static function invalidWebhookSignature(): self
    {
        return new self('Invalid webhook signature');
    }

    public static function invalidCouponCode(string $code): self
    {
        return new self("Invalid or expired coupon code: $code");
    }

    public static function invalidBackupData(string $reason): self
    {
        return new self("Invalid backup data: $reason");
    }

    public static function concurrencyConflict(string $contentId, int $expectedVersion): self
    {
        return new self(sprintf(
            'Concurrency conflict for content %s: expected version %d was already modified',
            $contentId,
            $expectedVersion,
        ));
    }

    public static function subscriberNotFound(string $id): self
    {
        return new self("Newsletter subscriber not found: $id");
    }

    public static function subscriberNotFoundByEmail(string $email): self
    {
        return new self("Newsletter subscriber not found for email: $email");
    }

    public static function subscriberAlreadyConfirmed(string $email): self
    {
        return new self("Email is already subscribed and confirmed: $email");
    }

    public static function subscriberNotUnsubscribed(string $email): self
    {
        return new self("Subscriber is not in unsubscribed status: $email");
    }

    public static function campaignNotFound(string $id): self
    {
        return new self("Newsletter campaign not found: $id");
    }

    public static function campaignNotEditable(string $id): self
    {
        return new self("Campaign can only be edited in Draft status: $id");
    }

    public static function campaignNotDeletable(string $id): self
    {
        return new self("Campaign can only be deleted in Draft or Cancelled status: $id");
    }

    public static function campaignNotSchedulable(string $id): self
    {
        return new self("Campaign can only be scheduled from Draft status: $id");
    }

    public static function campaignNotCancellable(string $id): self
    {
        return new self("Campaign can only be cancelled from Scheduled status: $id");
    }

    public static function sendNotFound(string $id): self
    {
        return new self("Newsletter send record not found: $id");
    }

    public static function invalidUnsubscribeSignature(): self
    {
        return new self('Invalid or expired unsubscribe link signature');
    }
}
