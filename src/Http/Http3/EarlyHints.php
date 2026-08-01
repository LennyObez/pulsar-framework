<?php

declare(strict_types=1);

namespace Pulsar\Http\Http3;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\ResponseStatus;

use function array_map;
use function count;
use function function_exists;
use function header;
use function headers_send;
use function implode;
use function sprintf;

/**
 * HTTP 103 Early Hints support for preloading critical resources.
 *
 * Generates Link headers so a browser can start fetching CSS, JS and fonts while
 * the server is still building the response.
 *
 * A 103 is an *interim* response: the connection stays open and the real response
 * follows on the same request. Only a SAPI that can flush headers independently of
 * a body can do that, and today FrankenPHP's `headers_send()` is the only one — the
 * FastCGI protocol carries exactly one response per request, so PHP-FPM cannot emit
 * an interim status at all, whatever the front server supports. {@see self::send()}
 * degrades accordingly instead of pretending.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/103
 * @see https://frankenphp.dev/docs/early-hints/
 * @api
 */
#[Api(since: '1.0.0')]
final class EarlyHints implements EarlyHintsInterface
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
     * Emit the hints, as a 103 interim response where the SAPI can do that.
     *
     * Must be called before any output and before the final response headers.
     *
     * On FrankenPHP the queued Link headers are flushed as a real 103 and the header
     * state resets, so the final response is unaffected. Everywhere else the hints
     * stay queued and ride on the final response, where a browser still honours
     * `Link: rel=preload` — later than a 103 would allow, but correct, and the page
     * is never held back by a hint that could not be sent.
     *
     * What this deliberately does not do is pass 103 to header(). That never produces
     * an interim response on any SAPI; it sets the *final* status to 103, which is not
     * a status a client can be served.
     */
    public function send(): void
    {
        if ($this->hints === []) {
            return;
        }

        foreach ($this->toLinkHeaders() as $linkValue) {
            header(sprintf('Link: %s', $linkValue), false);
        }

        if (function_exists('headers_send')) {
            headers_send(ResponseStatus::EarlyHints->value);
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
