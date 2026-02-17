<?php

declare(strict_types=1);

namespace Pulsar\Http\Http3;

use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function count;
use function implode;
use function sprintf;

/**
 * HTTP 103 Early Hints support for preloading critical resources.
 *
 * Generates Link headers that can be sent as a 103 Early Hints response
 * before the final response is ready. This allows browsers to start
 * fetching CSS, JS, and fonts while the server processes the request.
 *
 * Works with both nginx + QUIC (HTTP/3) and traditional HTTP/2 setups.
 * For nginx, use fastcgi_early_hints to proxy these headers.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/103
 */
#[Api(since: '1.0.0')]
final class EarlyHints
{
    /** @var list<LinkHint> */
    private array $hints = [];

    /**
     * Add a CSS stylesheet for preloading.
     */
    public function preloadStylesheet(string $href, bool $crossOrigin = false): self
    {
        $this->hints[] = new LinkHint($href, 'preload', 'style', $crossOrigin);

        return $this;
    }

    /**
     * Add a JavaScript file for preloading.
     */
    public function preloadScript(string $href, bool $crossOrigin = false): self
    {
        $this->hints[] = new LinkHint($href, 'preload', 'script', $crossOrigin);

        return $this;
    }

    /**
     * Add a font for preloading.
     *
     * Fonts always require crossorigin per the spec.
     */
    public function preloadFont(string $href): self
    {
        $this->hints[] = new LinkHint($href, 'preload', 'font', true);

        return $this;
    }

    /**
     * Add an image for preloading.
     */
    public function preloadImage(string $href, bool $crossOrigin = false): self
    {
        $this->hints[] = new LinkHint($href, 'preload', 'image', $crossOrigin);

        return $this;
    }

    /**
     * Add a fetch resource for preloading (e.g., API endpoint).
     */
    public function preloadFetch(string $href, bool $crossOrigin = true): self
    {
        $this->hints[] = new LinkHint($href, 'preload', 'fetch', $crossOrigin);

        return $this;
    }

    /**
     * Add a preconnect hint for an origin.
     */
    public function preconnect(string $origin, bool $crossOrigin = true): self
    {
        $this->hints[] = new LinkHint($origin, 'preconnect', null, $crossOrigin);

        return $this;
    }

    /**
     * Add a dns-prefetch hint for an origin.
     */
    public function dnsPrefetch(string $origin): self
    {
        $this->hints[] = new LinkHint($origin, 'dns-prefetch', null, false);

        return $this;
    }

    /**
     * Add a custom link hint.
     */
    public function addHint(string $href, string $rel, ?string $as = null, bool $crossOrigin = false): self
    {
        $this->hints[] = new LinkHint($href, $rel, $as, $crossOrigin);

        return $this;
    }

    /**
     * Get all link hints.
     *
     * @return list<LinkHint>
     */
    #[NoDiscard]
    public function hints(): array
    {
        return $this->hints;
    }

    /**
     * Generate Link header values for all hints.
     *
     * @return list<string> Individual Link header values
     */
    #[NoDiscard]
    public function toLinkHeaders(): array
    {
        return array_map(
            static fn(LinkHint $hint): string => $hint->toHeaderValue(),
            $this->hints,
        );
    }

    /**
     * Generate a single combined Link header value.
     */
    #[NoDiscard]
    public function toCombinedHeader(): string
    {
        return implode(', ', $this->toLinkHeaders());
    }

    /**
     * Send the 103 Early Hints response using PHP's header() function.
     *
     * This MUST be called before any output and before the final response headers.
     * Works with PHP-FPM behind nginx (requires fastcgi_early_hints on).
     */
    public function send(): void
    {
        if ($this->hints === []) {
            return;
        }

        foreach ($this->toLinkHeaders() as $linkValue) {
            header(sprintf('Link: %s', $linkValue), false, 103);
        }
    }

    /**
     * Whether any hints have been registered.
     */
    public function isEmpty(): bool
    {
        return $this->hints === [];
    }

    /**
     * Number of registered hints.
     */
    public function count(): int
    {
        return count($this->hints);
    }
}
