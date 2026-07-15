<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Records the incoming request URI before any locale-driven rewrite mutates it.
 *
 * {@see LocalePrefixMiddleware} strips the locale prefix and
 * {@see LocalizedSlugMiddleware} rewrites localized slugs to canonical route
 * keys — both replace the request URI via `withUri()`. A downstream consumer
 * that needs the URL the visitor actually requested (canonical/hreflang links,
 * analytics, audit trails) would otherwise only see the rewritten form.
 *
 * Whichever rewriter runs first stamps the original URI into the
 * `_original_uri` attribute; the guard makes it set-once, so the second
 * rewriter never overwrites the first's record.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class OriginalUriStash
{
    /**
     * Request attribute holding the original, pre-rewrite
     * {@see \Psr\Http\Message\UriInterface}.
     */
    public const string ATTRIBUTE = '_original_uri';

    /**
     * Return the request with its original URI recorded, or unchanged if a
     * prior rewriter already recorded one.
     */
    public static function remember(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request->getAttribute(self::ATTRIBUTE) !== null) {
            return $request;
        }

        return $request->withAttribute(self::ATTRIBUTE, $request->getUri());
    }
}
