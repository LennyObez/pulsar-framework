<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\ManagedChallenge;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\I18n\TranslatorInterface;

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
    /**
     * Translation domain for the widget's user-facing strings. The host may
     * provide a catalog for this domain in any locale; absent keys fall back to
     * the built-in English defaults, so no host configuration is required.
     */
    private const string I18N_DOMAIN = 'shield';

    private static ?self $globalInstance = null;

    public function __construct(
        private readonly ManagedChallengeService $service,
        private readonly string $fieldName,
        private readonly string $scriptUrl,
        private readonly string $workerUrl,
        private readonly string $refreshUrl = '',
        private readonly int $ttlSeconds = 0,
        private readonly ?TranslatorInterface $translator = null,
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
        // The user-facing strings (the no-JS fallback and the screen-reader
        // status announcements the widget injects per state) are resolved from
        // the 'shield' translation domain, falling back to English. The status
        // strings travel as data-pmc-msg-* attributes so the worker localizes
        // without a second hardcoded copy in the JS bundle.
        return sprintf(
            '<div class="pulsar-managed-challenge" data-pmc-challenge="%s" data-pmc-id="%s" data-pmc-bits="%d"'
            . ' data-pmc-field="%s" data-pmc-worker="%s" data-pmc-refresh="%s" data-pmc-ttl="%d"'
            . ' data-pmc-msg-solving="%s" data-pmc-msg-solved="%s" data-pmc-msg-error="%s"'
            . ' role="status" aria-live="polite">'
            . '<input type="hidden" name="%s" value="">'
            . '<noscript>%s</noscript>'
            . '</div>'
            . '<script src="%s"%s defer></script>',
            self::escape($token),
            self::escape($challenge->id),
            $challenge->bits,
            $field,
            self::escape($this->workerUrl),
            self::escape($this->refreshUrl),
            $this->ttlSeconds,
            self::escape($this->localize('verifying', 'Verifying your request…')),
            self::escape($this->localize('complete', 'Security check complete.')),
            self::escape($this->localize('error', 'Security verification failed. Please reload the page.')),
            $field,
            self::escape($this->localize('noscript', 'This form requires JavaScript to complete a security check.')),
            self::escape($this->scriptUrl),
            $nonceAttr,
        );
    }

    /**
     * Resolve a widget string from the 'shield' domain, falling back to the
     * built-in English default. The default is used both when no translator is
     * wired and when the host has not provided the key (has() is checked so a
     * missing key never returns the raw key string or throws in strict mode).
     */
    private function localize(string $key, string $default): string
    {
        if ($this->translator === null || !$this->translator->has($key, null, self::I18N_DOMAIN)) {
            return $default;
        }

        return $this->translator->translate($key, [], null, self::I18N_DOMAIN);
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
