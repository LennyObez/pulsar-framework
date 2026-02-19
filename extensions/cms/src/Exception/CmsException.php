<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Exception;

use Pulsar\Api\Api;
use RuntimeException;

/**
 * Base exception for all CMS errors.
 */
#[Api(since: '1.0.0')]
class CmsException extends RuntimeException
{
    public static function contentNotFound(string $id): self
    {
        return new self("Content not found: {$id}");
    }

    public static function translationNotFound(string $contentId, string $locale): self
    {
        return new self("Translation not found for content {$contentId} in locale {$locale}");
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Invalid status transition from '{$from}' to '{$to}'");
    }

    public static function circularParentReference(): self
    {
        return new self('Circular parent reference detected');
    }

    public static function maxDepthExceeded(int $maxDepth): self
    {
        return new self("Maximum hierarchy depth of {$maxDepth} exceeded");
    }

    public static function contentLocked(string $contentId, string $lockedBy): self
    {
        return new self("Content {$contentId} is locked by user {$lockedBy}");
    }

    public static function slugConflict(string $slug, string $locale): self
    {
        return new self("Slug '{$slug}' already exists for locale '{$locale}'");
    }

    public static function invalidSlug(string $slug): self
    {
        return new self("Invalid slug format: '{$slug}'");
    }

    public static function sanitizerBypassDetected(): self
    {
        return new self('SafeHtmlPolicy bypass detected — input was escaped as plaintext');
    }

    public static function disallowedExtension(string $extension): self
    {
        return new self("File extension '{$extension}' is not allowed");
    }

    public static function magicByteMismatch(string $mimeType): self
    {
        return new self("File content does not match expected magic bytes for MIME type '{$mimeType}'");
    }

    public static function mimeTypeMismatch(string $detected, string $declared): self
    {
        return new self("Detected MIME type '{$detected}' does not match declared type '{$declared}'");
    }

    public static function imageDimensionsExceeded(int $width, int $height, int $maxWidth, int $maxHeight): self
    {
        return new self("Image dimensions {$width}x{$height} exceed maximum {$maxWidth}x{$maxHeight}");
    }

    public static function pixelCountExceeded(int $pixelCount, int $maxPixelCount): self
    {
        return new self("Image pixel count {$pixelCount} exceeds maximum {$maxPixelCount}");
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
        return new self("File size {$fileSize} bytes exceeds maximum {$maxSize} bytes");
    }

    public static function unsafeSvgContent(): self
    {
        return new self('SVG contains potentially unsafe content');
    }

    public static function unsafePdfContent(string $pattern): self
    {
        return new self("PDF contains dangerous pattern: {$pattern}");
    }

    public static function invalidPdfFile(): self
    {
        return new self('File is not a valid PDF document');
    }

    public static function mediaNotFound(string $id): self
    {
        return new self("Media asset not found: {$id}");
    }

    public static function commentNotFound(string $id): self
    {
        return new self("Comment not found: {$id}");
    }

    public static function commentEditWindowExpired(string $id): self
    {
        return new self("Edit window has expired for comment: {$id}");
    }

    public static function searchQueryTooLong(int $length, int $maxLength): self
    {
        return new self("Search query length {$length} exceeds maximum of {$maxLength} characters");
    }

    public static function searchUnavailable(string $reason): self
    {
        return new self("Search service unavailable: {$reason}");
    }
}
