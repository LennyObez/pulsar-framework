<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\TimeTrap;

use NoDiscard;
use Pulsar\Api\Api;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Renders the time-trap stamp into a form as a server-side hidden field.
 *
 * Each render mints a fresh signed `{issuedAt, formId}` stamp and emits a single
 * `<input type="hidden">` carrying it. There is NO JavaScript, NO external
 * request, and nothing inline — it is trivially CSP `script-src 'self'` clean
 * and works for clients with scripting disabled, filling the form-timing gap the
 * (JS-only) managed challenge leaves open.
 *
 * Backs the `@timetrap` directive and the `@shield` directive (via the global
 * instance) and is also injectable for programmatic rendering.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final class TimeTrapRenderer
{
    private static ?self $globalInstance = null;

    public function __construct(
        private readonly TimeTrapService $service,
        private readonly string $fieldName,
    ) {}

    /**
     * Render the hidden stamp field, minting a fresh signed token.
     *
     * @param string $formId Binds the stamp to one form/route. Must match the
     *        AntiSpamContext::$formId supplied at submission, or the time-trap
     *        check rejects it as a cross-form replay. Defaults to the empty
     *        string (no form binding; fill-time window still enforced).
     */
    #[NoDiscard]
    public function render(string $formId = ''): string
    {
        $token = $this->service->issue($formId);

        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::escape($this->fieldName),
            self::escape($token),
        );
    }

    /**
     * Render via the global instance (used by the @timetrap and @shield directives).
     *
     * Returns an empty string when the time-trap is not configured, so a template
     * using the directive without the feature enabled degrades quietly.
     */
    #[NoDiscard]
    public static function renderGlobal(string $formId = ''): string
    {
        return self::$globalInstance?->render($formId) ?? '';
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
