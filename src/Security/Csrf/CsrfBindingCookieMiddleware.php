<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function bin2hex;
use function ctype_xdigit;
use function implode;
use function is_string;
use function random_bytes;
use function strlen;

/**
 * Establishes the per-browser CSRF binding cookie for {@see StatelessCsrfManager}.
 *
 * On every request it ensures a `__Host-pulsar-csrf` cookie exists — a random
 * 32-byte secret — and publishes its value into {@see CsrfBindingContext} so the
 * stateless manager can bind tokens to it. A freshly minted value is emitted as
 * a `Set-Cookie` on the response.
 *
 * The cookie is `__Host-`-prefixed (so a sibling or subdomain cannot plant it),
 * `Secure`, `HttpOnly` (the server compares it; JavaScript never needs to read
 * it — a cross-site page therefore can neither read nor set it), `Path=/`, and
 * `SameSite=Strict`. That is the "double-submit cookie" half of the stateless
 * CSRF defense: a forged token cannot be paired with the victim's binding.
 *
 * Wire it ahead of the CSRF validation middleware when using the stateless
 * manager. It is a no-op cost for the session-backed default manager, which does
 * not consult the binding, so it is only wired on opt-in.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CsrfBindingCookieMiddleware implements MiddlewareInterface
{
    /** `__Host-` prefix binds the cookie to this exact host, Secure and Path=/. */
    public const string COOKIE_NAME = '__Host-pulsar-csrf';

    /** Secret length in bytes; 32 → 64 hex characters. */
    private const int SECRET_BYTES = 32;

    public function __construct(
        private CsrfBindingContext $context,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $existing = $this->readCookie($request);
        $binding = $existing ?? bin2hex(random_bytes(self::SECRET_BYTES));

        $this->context->set($binding);

        $response = $handler->handle($request);

        // Emit the cookie only when it was newly minted (or replaced a malformed
        // one), so a valid returning cookie is not needlessly rewritten.
        if ($existing === null) {
            return $response->withAddedHeader('Set-Cookie', $this->cookieHeader($binding));
        }

        return $response;
    }

    /**
     * The current cookie value if present AND well-formed (64 hex chars); null
     * otherwise, so a malformed or absent cookie is reminted.
     */
    private function readCookie(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();
        /** @var mixed $value */
        $value = $cookies[self::COOKIE_NAME] ?? null;

        if (is_string($value) && strlen($value) === self::SECRET_BYTES * 2 && ctype_xdigit($value)) {
            return $value;
        }

        return null;
    }

    private function cookieHeader(string $value): string
    {
        // __Host- mandates Path=/, Secure, and no Domain. HttpOnly + SameSite=Strict
        // complete the double-submit hardening.
        return implode('; ', [
            self::COOKIE_NAME . '=' . $value,
            'Path=/',
            'Secure',
            'HttpOnly',
            'SameSite=Strict',
        ]);
    }
}
