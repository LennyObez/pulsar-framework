<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Closure;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Html\HtmlSanitizer;
use Pulsar\Security\Html\HtmlSanitizerPolicy;

use function strlen;

/**
 * Allowlist-based HTML sanitizer for user-authored CMS content.
 *
 * A thin CMS policy over the framework-wide {@see HtmlSanitizer}: it declares
 * the element/attribute allowlists for content bodies and comments and the CMS
 * URL rules (images must be HTTPS, a safe data URI, or under `/media/`), and
 * audit-logs any defense-in-depth bypass. The sanitization algorithm itself
 * lives in core so CMS, the forum and comments all share one implementation.
 *
 * @psalm-api Public sanitization policy resolved from the DI container by
 *            CommentBodyPolicy and content services; not instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SafeHtmlPolicy
{
    /**
     * Exhaustive element-to-allowed-attributes allowlist.
     *
     * @var array<string, list<string>>
     */
    private const array ELEMENT_ATTRIBUTES = [
        'p' => [],
        'br' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'pre' => [],
        'code' => ['class'],
        'strong' => [],
        'em' => [],
        'a' => ['href', 'rel', 'title'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'figure' => [],
        'figcaption' => [],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tr' => [],
        'th' => ['scope', 'colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
        'dl' => [],
        'dt' => [],
        'dd' => [],
        'abbr' => ['title'],
        'mark' => [],
        'sub' => [],
        'sup' => [],
        'hr' => [],
        'details' => ['open'],
        'summary' => [],
        'time' => ['datetime'],
    ];

    /**
     * Subset of elements allowed in comment bodies.
     *
     * @var array<string, list<string>>
     */
    private const array COMMENT_ATTRIBUTES = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'em' => [],
        'a' => ['href', 'rel', 'title'],
        'code' => [],
        'blockquote' => [],
        'pre' => [],
    ];

    private HtmlSanitizer $contentSanitizer;

    private HtmlSanitizer $commentSanitizer;

    public function __construct(
        private AuditLoggerInterface $auditLogger,
    ) {
        $onBypass = $this->auditBypass();

        $this->contentSanitizer = new HtmlSanitizer(
            new HtmlSanitizerPolicy(allowedElements: self::ELEMENT_ATTRIBUTES, imgRelativePrefix: '/media/'),
            $onBypass,
        );
        $this->commentSanitizer = new HtmlSanitizer(
            new HtmlSanitizerPolicy(allowedElements: self::COMMENT_ATTRIBUTES, imgRelativePrefix: '/media/'),
            $onBypass,
        );
    }

    /**
     * Sanitize user-authored HTML for content body storage.
     *
     * @param string $html Raw HTML input
     *
     * @return string Sanitized HTML safe for rendering
     */
    public function sanitize(string $html): string
    {
        return $this->contentSanitizer->sanitize($html);
    }

    /**
     * Sanitize HTML for comment bodies using the strict comment subset.
     *
     * @param string $html Raw HTML input
     *
     * @return string Sanitized HTML safe for rendering in comments
     */
    public function sanitizeComment(string $html): string
    {
        return $this->commentSanitizer->sanitize($html);
    }

    /**
     * @return Closure(string): void
     */
    private function auditBypass(): Closure
    {
        return function (string $originalHtml): void {
            $this->auditLogger->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                null,
                'cms.security.sanitizer_bypass_detected',
                'SafeHtmlPolicy',
                ['input_length' => strlen($originalHtml)],
            );
        };
    }
}
