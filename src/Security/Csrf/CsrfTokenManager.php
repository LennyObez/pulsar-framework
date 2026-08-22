<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use Override;
use Pulsar\Config\CsrfConfig;
use Pulsar\Security\Session\SessionInterface;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function bin2hex;
use function ctype_xdigit;
use function hash_equals;
use function hex2bin;
use function is_string;
use function random_bytes;
use function strlen;
use function substr;

/**
 * CSRF token manager using the synchronizer token pattern.
 *
 * The token is stored server-side in the session and compared in constant time
 * against the submitted value on state-changing requests.
 *
 * The session token is stable for the session's lifetime, so it is never
 * transmitted verbatim: every response carries a freshly masked encoding of it
 * (`pad || inner XOR pad`, a one-time pad). Without this, the same secret bytes
 * appear in every compressed response, which is exactly the stable target a
 * BREACH length-oracle needs — an attacker who can influence any reflected part
 * of a compressed page recovers the token byte by byte by watching response
 * sizes. Masking makes the transmitted value differ per response, so its
 * compressed length carries no signal about the secret. This is why Rails
 * (`masked_authenticity_token`) and Django (`_mask_cipher_secret`) mask their
 * session-backed tokens too: masking exists *because* the stored token is
 * stable.
 *
 * The stored form is unchanged, so existing sessions keep working, and
 * validation still accepts the legacy unmasked form so tokens already rendered
 * into a page at deploy time do not start failing.
 *
 * @see StatelessCsrfManager for the same masking on the stateless variant.
 */
final class CsrfTokenManager implements CsrfTokenManagerInterface
{
    /**
     * Session key for storing the CSRF token.
     */
    private const string SESSION_KEY = '_csrf_token';

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly CsrfConfig $config,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Generate a new CSRF token, store it in the session, and return a freshly
     * masked encoding of it.
     *
     * @return string A masked, single-use encoding of the new token
     *
     * @throws RandomException
     */
    #[Override]
    public function generate(): string
    {
        $token = bin2hex($this->randomizer->getBytes(max(1, $this->config->tokenLength)));
        $this->session->set(self::SESSION_KEY, $token);

        return $this->mask($token);
    }

    /**
     * Get the current CSRF token, generating one if none exists.
     *
     * The returned value is masked afresh on every call, so two renders of the
     * same session token never emit the same bytes.
     *
     * @throws RandomException
     */
    #[Override]
    public function getToken(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        // Anything that is not the hex this class writes cannot be masked as
        // binary; treat it as absent and mint a fresh token rather than fall
        // back to emitting it verbatim, which is the exposure being closed.
        if (!is_string($token) || !self::isStoredHex($token)) {
            return $this->generate();
        }

        return $this->mask($token);
    }

    /**
     * Validate a submitted token against the stored session token.
     *
     * Accepts the masked form and, for tokens rendered before this manager
     * started masking, the legacy verbatim form. Comparison is constant-time.
     */
    #[Override]
    public function validate(string $submittedToken): bool
    {
        $storedToken = $this->session->get(self::SESSION_KEY);

        if (!is_string($storedToken) || !self::isStoredHex($storedToken)) {
            return false;
        }

        $unmasked = $this->unmask($submittedToken, strlen($storedToken));

        if ($unmasked === null) {
            return false;
        }

        return hash_equals($storedToken, $unmasked);
    }

    /**
     * Wrap the stored token in a one-time pad: `hex(pad || inner XOR pad)`.
     *
     * The pad is fresh per call, so the transmitted value is unpredictable even
     * though the underlying token is stable, and its compressed length leaks
     * nothing about the secret.
     *
     * @param string $storedToken The session token, hex-encoded
     *
     * @throws RandomException
     */
    private function mask(string $storedToken): string
    {
        // Callers guarantee isStoredHex(), so hex2bin() cannot fail here.
        $inner = (string) hex2bin($storedToken);
        $pad = random_bytes(max(1, strlen($inner)));

        return bin2hex($pad . ($inner ^ $pad));
    }

    /**
     * Whether a session value is in the form this class writes: non-empty,
     * even-length hex. Both mask() and unmask() depend on it, so the check
     * lives in one place.
     */
    private static function isStoredHex(string $token): bool
    {
        return $token !== '' && strlen($token) % 2 === 0 && ctype_xdigit($token);
    }

    /**
     * Recover the stored token from a submitted value.
     *
     * Accepts the masked form (`hex(pad || inner XOR pad)`, twice the stored
     * hex length) and the legacy verbatim form (the stored hex itself), so a
     * deploy does not reject tokens already rendered into open pages. Returns
     * null for anything else.
     *
     * @param int $storedLength Length of the stored hex token
     */
    private function unmask(string $submitted, int $storedLength): ?string
    {
        if (strlen($submitted) === $storedLength) {
            return $submitted;
        }

        if (strlen($submitted) !== 2 * $storedLength || !ctype_xdigit($submitted)) {
            return null;
        }

        $bytes = hex2bin($submitted);

        if ($bytes === false) {
            return null;
        }

        $half = strlen($bytes) >> 1;
        $pad = substr($bytes, 0, $half);
        $masked = substr($bytes, $half);

        return bin2hex($masked ^ $pad);
    }

    /**
     * Rotate the CSRF token (generate a new one, invalidating the old)
     * AND regenerate the underlying session ID.
     *
     * Rotating only the CSRF token without regenerating the session ID
     * leaves a session-fixation window open. The canonical anti-fixation
     * flow on a state boundary (post-login, privilege change, password
     * reset) requires `Session::regenerate(true)` so any session ID an
     * attacker may have fixated is destroyed alongside the old token.
     *
     * Order matters: regenerate the session first so the new token is
     * stored under the new session id; otherwise the freshly stored
     * token would be tied to the old (potentially compromised) session
     * before the regenerate call swept it.
     *
     * @throws RandomException
     */
    #[Override]
    public function rotate(): string
    {
        $this->session->regenerate(true);

        return $this->generate();
    }
}
