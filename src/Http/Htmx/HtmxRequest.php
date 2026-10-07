<?php

declare(strict_types=1);

namespace Pulsar\Http\Htmx;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Wraps a PSR-7 request to extract Pulsar hypermedia headers.
 *
 * Detects requests originating from the px-* client runtime
 * and exposes trigger, target, and swap context.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HtmxRequest
{
    public function __construct(
        private ServerRequestInterface $request,
    ) {}

    /**
     * Whether this request was triggered by the px-* client runtime.
     */
    public function isHtmx(): bool
    {
        return $this->request->hasHeader('PX-Request');
    }

    /**
     * Whether this is a history restoration request (back/forward navigation).
     */
    public function isHistoryRestore(): bool
    {
        return $this->request->getHeaderLine('PX-History-Restore-Request') === 'true';
    }

    /**
     * Whether this request was triggered by a boosted element (px-boost).
     */
    public function isBoosted(): bool
    {
        return $this->request->getHeaderLine('PX-Boosted') === 'true';
    }

    /**
     * The element that triggered the request (CSS selector).
     */
    public function trigger(): ?string
    {
        $value = $this->request->getHeaderLine('PX-Trigger');

        return $value !== '' ? $value : null;
    }

    /**
     * The name of the trigger element.
     */
    public function triggerName(): ?string
    {
        $value = $this->request->getHeaderLine('PX-Trigger-Name');

        return $value !== '' ? $value : null;
    }

    /**
     * The target element's CSS selector.
     */
    public function target(): ?string
    {
        $value = $this->request->getHeaderLine('PX-Target');

        return $value !== '' ? $value : null;
    }

    /**
     * The current URL of the browser when the request was made.
     */
    public function currentUrl(): ?string
    {
        $value = $this->request->getHeaderLine('PX-Current-URL');

        return $value !== '' ? $value : null;
    }

    /**
     * The prompt response from px-prompt.
     */
    public function prompt(): ?string
    {
        $value = $this->request->getHeaderLine('PX-Prompt');

        return $value !== '' ? $value : null;
    }
}
