<?php

declare(strict_types=1);

namespace Pulsar\Http\Http3;

use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function count;
use function implode;

/**
 * Link header generation for HTTP/2 server push and HTTP/3 preload.
 *
 * Generates RFC 8297 Link headers that signal resources to push (HTTP/2)
 * or preload (HTTP/3). HTTP/3 does not support server push, but Link
 * headers with rel=preload still instruct the browser to fetch resources
 * with high priority.
 *
 * For nginx: use http2_push_preload on; to automatically push resources
 * listed in Link headers.
 *
 * @see https://httpwg.org/specs/rfc8297.html
 * @api
 */
#[Api(since: '1.0.0')]
final class ServerPush
{
    /** @var list<PushResource> */
    private array $resources = [];

    /**
     * Push a CSS stylesheet.
     */
    public function stylesheet(string $path, bool $noPush = false): self
    {
        $this->resources[] = new PushResource($path, 'style', $noPush);

        return $this;
    }

    /**
     * Push a JavaScript file.
     */
    public function script(string $path, bool $noPush = false): self
    {
        $this->resources[] = new PushResource($path, 'script', $noPush);

        return $this;
    }

    /**
     * Push a font file.
     */
    public function font(string $path, bool $noPush = false): self
    {
        $this->resources[] = new PushResource($path, 'font', $noPush, crossOrigin: true);

        return $this;
    }

    /**
     * Push an image.
     */
    public function image(string $path, bool $noPush = false): self
    {
        $this->resources[] = new PushResource($path, 'image', $noPush);

        return $this;
    }

    /**
     * Push a generic resource.
     *
     * @param string $path Resource path
     * @param string $as Resource type (style, script, font, image, fetch)
     * @param bool $noPush Add nopush attribute (preload hint only, no server push)
     * @param bool $crossOrigin Whether the resource requires CORS
     */
    public function resource(string $path, string $as, bool $noPush = false, bool $crossOrigin = false): self
    {
        $this->resources[] = new PushResource($path, $as, $noPush, $crossOrigin);

        return $this;
    }

    /**
     * Get all registered push resources.
     *
     * @return list<PushResource>
     */
    #[NoDiscard]
    public function resources(): array
    {
        return $this->resources;
    }

    /**
     * Generate Link header values for all resources.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function toLinkHeaders(): array
    {
        return array_map(
            static fn(PushResource $r): string => $r->toHeaderValue(),
            $this->resources,
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
     * Whether any resources have been registered.
     */
    public function isEmpty(): bool
    {
        return $this->resources === [];
    }

    /**
     * Number of registered resources.
     */
    public function count(): int
    {
        return count($this->resources);
    }
}
