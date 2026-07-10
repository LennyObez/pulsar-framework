<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Behavior;

use NoDiscard;
use Pulsar\Api\Api;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Renders the behavioural-signals collector into a form.
 *
 * Emits an empty hidden field (marked `data-pulsar-behavior`) the same-origin
 * collector script fills on submit, plus the script tag itself — CSP
 * `script-src 'self'` clean (the request nonce is stamped through). With no
 * JavaScript the field stays empty and the server scores it neutrally, so the
 * widget is safe to place in any form.
 *
 * Backs the `@shield` directive (via the global instance) and is also
 * injectable for programmatic rendering.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final class BehaviorCollectorRenderer
{
    private static ?self $globalInstance = null;

    public function __construct(
        private readonly string $fieldName,
        private readonly string $scriptUrl,
    ) {}

    /**
     * @param string|null $cspNonce The request CSP nonce to stamp on the script tag.
     */
    #[NoDiscard]
    public function render(?string $cspNonce = null): string
    {
        $nonceAttr = $cspNonce !== null && $cspNonce !== ''
            ? sprintf(' nonce="%s"', self::escape($cspNonce))
            : '';

        return sprintf(
            '<input type="hidden" name="%s" value="" data-pulsar-behavior>'
            . '<script src="%s"%s defer></script>',
            self::escape($this->fieldName),
            self::escape($this->scriptUrl),
            $nonceAttr,
        );
    }

    /**
     * Render via the global instance (used by the @shield directive). Returns an
     * empty string when the behavioural check is not configured.
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
