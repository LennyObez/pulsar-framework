<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\I18n\LocaleNegotiatorInterface;

use function in_array;
use function is_string;

/**
 * Locale negotiator that checks cookies and session before Accept-Language.
 *
 * Priority (highest to lowest):
 * 1. Cookie `pulsar_locale`
 * 2. Session attribute `_locale`
 * 3. Query parameter `?locale=xx`
 * 4. Request attribute `_locale` (from route parameter)
 * 5. Accept-Language header (RFC 7231, quality-sorted)
 * 6. Config default
 */
#[Internal]
final readonly class CookieAwareLocaleNegotiator implements LocaleNegotiatorInterface
{
    private const string COOKIE_NAME = 'pulsar_locale';

    public function __construct(
        private LocaleNegotiator $inner,
    ) {}

    public function negotiate(ServerRequestInterface $request, array $supported, string $default): string
    {
        if ($supported === []) {
            return $default;
        }

        // 1. Cookie
        $cookies = $request->getCookieParams();
        /** @var mixed $cookieLocale */
        $cookieLocale = $cookies[self::COOKIE_NAME] ?? null;

        if (is_string($cookieLocale) && $cookieLocale !== '' && in_array($cookieLocale, $supported, true)) {
            return $cookieLocale;
        }

        // 2. Session attribute
        /** @var mixed $sessionLocale */
        $sessionLocale = $request->getAttribute('session_locale');

        if (is_string($sessionLocale) && $sessionLocale !== '' && in_array($sessionLocale, $supported, true)) {
            return $sessionLocale;
        }

        // 3-6. Delegate to core negotiator (query, route attribute, Accept-Language, default)
        return $this->inner->negotiate($request, $supported, $default);
    }
}
