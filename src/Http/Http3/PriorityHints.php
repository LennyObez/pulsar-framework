<?php

declare(strict_types=1);

namespace Pulsar\Http\Http3;

use NoDiscard;
use Pulsar\Api\Api;

use function count;

/**
 * Fetchpriority attribute support for resource priority hints.
 *
 * Generates HTML attributes and Link header parameters that indicate
 * resource loading priority to browsers, enabling smarter resource
 * scheduling for HTTP/2 and HTTP/3 connections.
 *
 * @see https://web.dev/articles/fetch-priority
 * @see https://developer.mozilla.org/en-US/docs/Web/API/HTMLImageElement/fetchPriority
 * @api
 */
#[Api(since: '1.0.0')]
final class PriorityHints
{
    /** @var list<PriorityHint> */
    private array $hints = [];

    /**
     * Set a resource to high priority.
     *
     * Use for above-the-fold images, critical CSS, or main scripts.
     */
    public function high(string $href, string $as): self
    {
        $this->hints[] = new PriorityHint($href, $as, FetchPriority::High);

        return $this;
    }

    /**
     * Set a resource to low priority.
     *
     * Use for below-the-fold images, deferred scripts, or prefetched resources.
     */
    public function low(string $href, string $as): self
    {
        $this->hints[] = new PriorityHint($href, $as, FetchPriority::Low);

        return $this;
    }

    /**
     * Set a resource to auto priority (browser default).
     */
    public function auto(string $href, string $as): self
    {
        $this->hints[] = new PriorityHint($href, $as, FetchPriority::Auto);

        return $this;
    }

    /**
     * Get all registered priority hints.
     *
     * @return list<PriorityHint>
     */
    #[NoDiscard]
    public function hints(): array
    {
        return $this->hints;
    }

    /**
     * Generate Link header values with priority parameters.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function toLinkHeaders(): array
    {
        $headers = [];

        foreach ($this->hints as $hint) {
            $headers[] = $hint->toLinkHeaderValue();
        }

        return $headers;
    }

    /**
     * Generate HTML attributes for an element (img, script, link).
     *
     * @return array<string, array{fetchpriority: string}>
     */
    #[NoDiscard]
    public function toHtmlAttributes(): array
    {
        $attrs = [];

        foreach ($this->hints as $hint) {
            $attrs[$hint->href] = ['fetchpriority' => $hint->priority->value];
        }

        return $attrs;
    }

    /**
     * Get the priority for a specific resource.
     */
    public function priorityFor(string $href): ?FetchPriority
    {
        foreach ($this->hints as $hint) {
            if ($hint->href === $href) {
                return $hint->priority;
            }
        }

        return null;
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
