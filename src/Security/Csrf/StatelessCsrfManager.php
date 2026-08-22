<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use Closure;
use InvalidArgumentException;
use LogicException;
use NoDiscard;
use Override;
use Pulsar\Api\Api;
use RuntimeException;
use SensitiveParameter;
use SodiumException;

use function bin2hex;
use function hex2bin;
use function pack;
use function random_bytes;
use function sodium_crypto_auth;
use function sodium_crypto_auth_verify;
use function sprintf;
use function strlen;
use function substr;
use function time;
use function unpack;

/**
 * Stateless CSRF protection using a keyed MAC bound to a per-browser secret.
 *
 * Inner token = timestamp_bytes || hmac(timestamp || len(action) || action ||
 * binding, secret_key). Transmitted token = hex(pad || (inner XOR pad)) with a
 * fresh random `pad` per call.
 *
 * **The binding is what makes this a CSRF defense.** Without it, the MAC would
 * cover only (timestamp, action) — server-side values any visitor, including an
 * attacker, can reproduce — so anyone could mint a token valid for every victim
 * in the window. The `binding` is a per-browser secret carried in a `__Host-`
 * cookie the attacker's cross-site page can neither read (HttpOnly, cross-origin)
 * nor set (`__Host-` prefix blocks sibling/subdomain planting): a signed
 * double-submit cookie, OWASP's stateless-CSRF pattern. Wire
 * {@see \Pulsar\Security\Csrf\CsrfBindingCookieMiddleware}; the binding is read
 * fresh per operation, so the manager stays a singleton while the value is
 * request-scoped. Operations fail closed when no binding is present.
 *
 * The action is length-prefixed in the MAC message so the action/binding
 * boundary is unambiguous — otherwise ("ab", "cd") and ("a", "bcd") would
 * authenticate identically.
 *
 * The inner token is deterministic for a given (second, action, binding), which
 * would be a stable BREACH compression-oracle target if emitted verbatim in a
 * compressed response; the per-response one-time-pad mask (as in Django/Rails)
 * randomises the transmitted value while the verifiable inner token is
 * unchanged. Only the masked form is accepted (there are no legacy verbatim
 * tokens: this class binds a value the old format could not carry).
 *
 * No server-side state; compatible with CDN/Varnish and stateless APIs. Each
 * token is action-bound. rotate() cannot revoke outstanding tokens (there is no
 * state to change) — the short window and key rotation are the only bounds.
 * Deploy behind Origin validation (on by default) for defense in depth.
 *
 * Uses sodium_crypto_auth (HMAC-SHA-512/256) for constant-time verification.
 * @api
 */
#[Api(since: '1.0.0')]
final class StatelessCsrfManager implements CsrfTokenManagerInterface
{
    private const int TIMESTAMP_BYTES = 8;

    /** Length of the inner (unmasked) token: timestamp + HMAC. */
    private const int INNER_TOKEN_BYTES = self::TIMESTAMP_BYTES + SODIUM_CRYPTO_AUTH_BYTES;

    /**
     * @param string $secretKey MAC key; must be SODIUM_CRYPTO_AUTH_KEYBYTES long.
     * @param (Closure(): string) $bindingProvider Returns the current request's
     *     per-browser binding secret (the `__Host-` CSRF cookie value). Read
     *     fresh per operation; must yield a non-empty value or operations fail
     *     closed.
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly string $secretKey,
        private readonly Closure $bindingProvider,
        private readonly int $windowSeconds = 600,
        private readonly string $defaultAction = '_default',
    ) {
        if (strlen($secretKey) !== SODIUM_CRYPTO_AUTH_KEYBYTES) {
            // Fail at construction, not with a SodiumException on first use.
            throw new InvalidArgumentException(sprintf(
                'StatelessCsrfManager secret key must be %d bytes (SODIUM_CRYPTO_AUTH_KEYBYTES); got %d.',
                SODIUM_CRYPTO_AUTH_KEYBYTES,
                strlen($secretKey),
            ));
        }
    }

    /**
     * Generate a stateless CSRF token for the default action.
     *
     * @throws SodiumException
     */
    #[Override]
    public function generate(): string
    {
        return $this->generateForAction($this->defaultAction);
    }

    /**
     * Get a token (generates a new one; stateless, no storage).
     *
     * @throws SodiumException
     */
    #[Override]
    public function getToken(): string
    {
        return $this->generate();
    }

    /**
     * Validate a submitted token against the default action.
     *
     * @throws SodiumException
     */
    #[Override]
    public function validate(string $submittedToken): bool
    {
        return $this->validateForAction($submittedToken, $this->defaultAction);
    }

    /**
     * Rotate is a no-op for stateless tokens.
     *
     * @throws SodiumException
     */
    #[Override]
    public function rotate(): string
    {
        return $this->generate();
    }

    /**
     * Generate a token bound to a specific action.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public function generateForAction(string $action): string
    {
        $timestampBytes = pack('J', time());
        $message = $this->authenticatedMessage($timestampBytes, $action, $this->binding());

        $mac = sodium_crypto_auth($message, $this->secretKey);
        $inner = $timestampBytes . $mac;

        // One-time-pad mask per response: the transmitted value is randomised
        // so it is not a stable BREACH target, while the inner token is
        // unchanged. Same-length XOR (`$inner ^ $pad`) recovers to the inner
        // token on validation.
        $pad = random_bytes(self::INNER_TOKEN_BYTES);

        return bin2hex($pad . ($inner ^ $pad));
    }

    /**
     * The MAC message: timestamp || len(action) || action || binding.
     *
     * The action length prefix fixes the action/binding boundary so no two
     * (action, binding) pairs produce the same message.
     */
    private function authenticatedMessage(string $timestampBytes, string $action, string $binding): string
    {
        return $timestampBytes . pack('J', strlen($action)) . $action . $binding;
    }

    /**
     * The current request's per-browser binding secret, or fail closed.
     *
     * An empty binding would collapse the double-submit protection, so it is a
     * hard error rather than a token that silently protects nothing.
     */
    private function binding(): string
    {
        $binding = ($this->bindingProvider)();

        if ($binding === '') {
            throw new LogicException(
                'StatelessCsrfManager needs a per-browser binding (the __Host- CSRF cookie); '
                . 'none is present. Wire CsrfBindingCookieMiddleware ahead of CSRF validation.',
            );
        }

        return $binding;
    }

    /**
     * Validate a token against a specific action.
     *
     * Checks both the HMAC and the timestamp window.
     *
     * @throws SodiumException
     */
    public function validateForAction(string $submittedToken, string $action): bool
    {
        $raw = @hex2bin($submittedToken);

        if ($raw === false) {
            return false;
        }

        $inner = $this->unmask($raw);

        if ($inner === null) {
            return false;
        }

        $timestampBytes = substr($inner, 0, self::TIMESTAMP_BYTES);
        $mac = substr($inner, self::TIMESTAMP_BYTES);

        // Verify HMAC first (constant-time), binding the current browser secret.
        $message = $this->authenticatedMessage($timestampBytes, $action, $this->binding());

        if (!sodium_crypto_auth_verify($mac, $message, $this->secretKey)) {
            return false;
        }

        // Verify timestamp window
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', $timestampBytes);
        $tokenTime = $unpacked[1];
        $now = time();

        return ($now - $tokenTime) <= $this->windowSeconds && $tokenTime <= $now;
    }

    /**
     * Recover the inner token from the masked transmitted form
     * (`pad || inner XOR pad`, 2x INNER_TOKEN_BYTES). Returns null for any other
     * length. There is no legacy verbatim form to accept: binding a per-browser
     * secret is new behaviour the old token layout could not carry.
     */
    private function unmask(string $raw): ?string
    {
        if (strlen($raw) !== 2 * self::INNER_TOKEN_BYTES) {
            return null;
        }

        $pad = substr($raw, 0, self::INNER_TOKEN_BYTES);
        $masked = substr($raw, self::INNER_TOKEN_BYTES);

        return $masked ^ $pad;
    }

    public function __debugInfo(): array
    {
        return [
            'secretKey' => '[REDACTED]',
            'windowSeconds' => $this->windowSeconds,
            'defaultAction' => $this->defaultAction,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new RuntimeException('StatelessCsrfManager must not be serialized');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        unset($data);
        throw new RuntimeException('StatelessCsrfManager must not be serialized');
    }
}
