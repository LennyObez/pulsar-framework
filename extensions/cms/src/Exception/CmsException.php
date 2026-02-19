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
}
