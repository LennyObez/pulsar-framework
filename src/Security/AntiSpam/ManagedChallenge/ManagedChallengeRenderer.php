<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\ManagedChallenge;

use NoDiscard;
use Pulsar\Api\Api;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Renders the self-hosted managed-challenge widget into a form.
 *
 * Emits a hidden field the widget fills with the solved token, a container
 * carrying the freshly-minted signed challenge and difficulty, and a
 * same-origin `<script>` (CSP `script-src 'self'` clean — no inline code, no
 * external CDN, no blob: worker). Each render mints a new single-use challenge.
 *
 * Backs the `@shield` directive (via the global instance) and is also
 * injectable into controllers for programmatic rendering.
 * @api
 */
#[Api(since: '1.0.0')]
final class ManagedChallengeRenderer
{
    private static ?self $globalInstance = null;

    public function __construct(
        private readonly ManagedChallengeService $service,
        private readonly string $fieldName,
        private readonly string $scriptUrl,
        private readonly string $workerUrl,
        private readonly string $refreshUrl = '',
        private readonly int $ttlSeconds = 0,
    ) {}

    /**
     * Render the widget markup, minting a fresh signed challenge.
     *
     * @param string|null $cspNonce The request CSP nonce to stamp on the script tag.
     */
    #[NoDiscard]
    public function render(?string $cspNonce = null): string
    {
        $challenge = $this->service->mint();
        $token = $this->service->sign($challenge);
        $field = self::escape($this->fieldName);

        $nonceAttr = $cspNonce !== null && $cspNonce !== ''
            ? sprintf(' nonce="%s"', self::escape($cspNonce))
            : '';

        // data-pmc-id exposes the challenge id the worker hashes over. It is not
        // trusted server-side: verification recomputes the proof-of-work against
        // the id inside the signed token, so a tampered id simply fails the check.
        // data-pmc-refresh + data-pmc-ttl let the widget silently re-mint a fresh
        // challenge at ~80% of the TTL, so a slow human is never rejected while
        // the server keeps a tight (small replay window) TTL. Both are optional:
        // when the refresh endpoint is not wired the widget simply never refreshes.
        return sprintf(
            '<div class="pulsar-managed-challenge" data-pmc-challenge="%s" data-pmc-id="%s" data-pmc-bits="%d"'
            . ' data-pmc-field="%s" data-pmc-worker="%s" data-pmc-refresh="%s" data-pmc-ttl="%d"'
            . ' role="status" aria-live="polite">'
            . '<input type="hidden" name="%s" value="" autocomplete="off">'
            . '<noscript>This form requires JavaScript to complete a security check.</noscript>'
            . '</div>'
            . '<script src="%s"%s defer></script>',
            self::escape($token),
            self::escape($challenge->id),
            $challenge->bits,
            $field,
            self::escape($this->workerUrl),
            self::escape($this->refreshUrl),
            $this->ttlSeconds,
            $field,
            self::escape($this->scriptUrl),
            $nonceAttr,
        );
    }

    /**
     * Render via the global instance (used by the @shield directive).
     *
     * Returns an empty string when the managed challenge is not configured, so
     * a template using @shield without the 'managed' provider degrades quietly.
     */
    #[NoDiscard]
    public static function renderGlobal(?string $cspNonce = null): string
    {
        return self::$globalInstance?->render($cspNonce) ?? '';
    }

    public static function setGlobalInstance(self $instance): void
    {
        self::$globalInstance = $instance;
    }

    public static function resetGlobalInstance(): void
    {
        self::$globalInstance = null;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
