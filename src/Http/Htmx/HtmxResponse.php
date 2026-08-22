<?php

declare(strict_types=1);

namespace Pulsar\Http\Htmx;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Fluent builder for hypermedia (px-*) HTML fragment responses.
 *
 * Sets the response headers consumed by the px-* client runtime
 * to control swapping, retargeting, history, and event triggers.
 *
 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement: Psalm does not yet infer clone() return type
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HtmxResponse
{
    private function __construct(
        private string $html,
        private ResponseStatus $status,
        private SwapStrategy $swap,
        private ?string $target,
        private ?string $pushUrl,
        private ?string $replaceUrl,
        private ?string $redirect,
        private ?string $refresh,
        private ?string $reswap,
        /** @var array<string, mixed> */
        private array $triggerEvents,
        /** @var array<string, mixed> */
        private array $triggerAfterSettle,
        /** @var array<string, mixed> */
        private array $triggerAfterSwap,
    ) {}

    /**
     * Create a new hypermedia response with the given HTML fragment.
     */
    #[NoDiscard]
    public static function fragment(string $html, ResponseStatus $status = ResponseStatus::OK): self
    {
        return new self(
            html: $html,
            status: $status,
            swap: SwapStrategy::InnerHTML,
            target: null,
            pushUrl: null,
            replaceUrl: null,
            redirect: null,
            refresh: null,
            reswap: null,
            triggerEvents: [],
            triggerAfterSettle: [],
            triggerAfterSwap: [],
        );
    }

    /**
     * Override the swap strategy for this response.
     */
    #[NoDiscard]
    public function withSwap(SwapStrategy $swap): self
    {
        return clone($this, ['swap' => $swap]);
    }

    /**
     * Retarget the response to a different CSS selector.
     */
    #[NoDiscard]
    public function withTarget(string $selector): self
    {
        return clone($this, ['target' => $selector]);
    }

    /**
     * Push a new URL into browser history.
     */
    #[NoDiscard]
    public function withPushUrl(string $url): self
    {
        return clone($this, ['pushUrl' => $url]);
    }

    /**
     * Replace the current URL in browser history (no new entry).
     */
    #[NoDiscard]
    public function withReplaceUrl(string $url): self
    {
        return clone($this, ['replaceUrl' => $url]);
    }

    /**
     * Redirect the browser to a new URL (client-side redirect).
     */
    #[NoDiscard]
    public function withRedirect(string $url): self
    {
        return clone($this, ['redirect' => $url]);
    }

    /**
     * Force a full page refresh after this response.
     */
    #[NoDiscard]
    public function withRefresh(): self
    {
        return clone($this, ['refresh' => 'true']);
    }

    /**
     * Override the swap strategy via header (allows modifiers like swap:1s).
     */
    #[NoDiscard]
    public function withReswap(string $value): self
    {
        return clone($this, ['reswap' => $value]);
    }

    /**
     * Trigger a client-side event after the response is received.
     */
    #[NoDiscard]
    public function withTrigger(string $event, mixed $detail = null): self
    {
        $events = [...$this->triggerEvents, $event => $detail];

        return clone($this, ['triggerEvents' => $events]);
    }

    /**
     * Trigger a client-side event after the swap settles.
     */
    #[NoDiscard]
    public function withTriggerAfterSettle(string $event, mixed $detail = null): self
    {
        $events = [...$this->triggerAfterSettle, $event => $detail];

        return clone($this, ['triggerAfterSettle' => $events]);
    }

    /**
     * Trigger a client-side event after the swap completes.
     */
    #[NoDiscard]
    public function withTriggerAfterSwap(string $event, mixed $detail = null): self
    {
        $events = [...$this->triggerAfterSwap, $event => $detail];

        return clone($this, ['triggerAfterSwap' => $events]);
    }

    /**
     * Build the final HTTP Response with all px-* headers set.
     */
    #[NoDiscard]
    public function toResponse(): Response
    {
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
        ];

        if ($this->swap !== SwapStrategy::InnerHTML) {
            $headers['PX-Reswap'] = $this->reswap ?? $this->swap->value;
        } elseif ($this->reswap !== null) {
            $headers['PX-Reswap'] = $this->reswap;
        }

        if ($this->target !== null) {
            $headers['PX-Retarget'] = $this->target;
        }

        if ($this->pushUrl !== null) {
            $headers['PX-Push-Url'] = $this->pushUrl;
        }

        if ($this->replaceUrl !== null) {
            $headers['PX-Replace-Url'] = $this->replaceUrl;
        }

        if ($this->redirect !== null) {
            $headers['PX-Redirect'] = $this->redirect;
        }

        if ($this->refresh !== null) {
            $headers['PX-Refresh'] = $this->refresh;
        }

        if ($this->triggerEvents !== []) {
            $headers['PX-Trigger'] = self::encodeEvents($this->triggerEvents);
        }

        if ($this->triggerAfterSettle !== []) {
            $headers['PX-Trigger-After-Settle'] = self::encodeEvents($this->triggerAfterSettle);
        }

        if ($this->triggerAfterSwap !== []) {
            $headers['PX-Trigger-After-Swap'] = self::encodeEvents($this->triggerAfterSwap);
        }

        return new Response(
            body: $this->html,
            status: $this->status,
            headers: new HeaderBag($headers),
        );
    }

    /**
     * Encode event map as JSON or comma-separated names for simple events.
     *
     * @param array<string, mixed> $events
     */
    private static function encodeEvents(array $events): string
    {
        $allNull = true;
        foreach ($events as $detail) {
            if ($detail !== null) {
                $allNull = false;
                break;
            }
        }

        if ($allNull) {
            return implode(', ', array_keys($events));
        }

        return json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
