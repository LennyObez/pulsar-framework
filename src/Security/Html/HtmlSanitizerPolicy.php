<?php

declare(strict_types=1);

namespace Pulsar\Security\Html;

use Pulsar\Api\Api;

/**
 * Declarative policy for {@see HtmlSanitizer}: which elements and attributes are
 * allowed, and which URL schemes are permitted on links and images.
 *
 * The sanitizer is allowlist-based — anything not described here is removed — so
 * a consumer (CMS content, forum posts, comments) only declares what it needs.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HtmlSanitizerPolicy
{
    /**
     * @param array<string, list<string>> $allowedElements   Element name → allowed attribute names.
     * @param list<string>                 $hrefSchemes       Absolute URL schemes allowed on <a href> (relative is always allowed).
     * @param list<string>                 $imgSchemes        Absolute URL schemes allowed on <img src>.
     * @param list<string>                 $dangerousAttributes Attribute names stripped on every element (defense in depth).
     */
    public function __construct(
        public array $allowedElements,
        public array $hrefSchemes = ['http', 'https', 'mailto'],
        public array $imgSchemes = ['https'],
        public bool $allowRelativeImg = true,
        public string $imgRelativePrefix = '',
        public bool $allowDataImages = true,
        public int $maxDataUriBytes = 32768,
        public bool $forceRelOnAbsoluteLinks = true,
        public array $dangerousAttributes = ['id', 'srcset', 'formaction', 'xlink:href', 'xmlns', 'xml:base'],
    ) {}
}
