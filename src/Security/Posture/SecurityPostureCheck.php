<?php

declare(strict_types=1);

namespace Pulsar\Security\Posture;

use Pulsar\Api\Api;
use Pulsar\Config\SecurityConfig;
use Pulsar\Core\Wiring\Contract\DegradedFeature;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;
use SensitiveParameter;
use SodiumException;

use function implode;
use function sprintf;
use function strcasecmp;
use function strlen;

/**
 * Evaluates the application's production security posture into a report of
 * OK / DEGRADED / FAIL items, each with a reason and a fix.
 *
 * Unlike {@see \Pulsar\Security\Assertion\SecurityAssertionRunner} (which only
 * runs in production and either logs or throws), this is environment-aware and
 * exhaustive: outside production the same weaknesses surface as DEGRADED
 * warnings instead of failures, so a control that is silently inert — a captcha
 * whose single-use replay cache is unbound, CSRF disabled, a missing master
 * key — is always visible rather than failing closed without a trace.
 *
 * Two of these items are about a running service, not a setting, and they are
 * the two that used to report a control healthy while it was doing nothing.
 * `master_key` measured a hex STRING's length, so any sixty-four characters
 * passed — including sixty-four that do not decode, which is the input
 * {@see \Pulsar\Core\Wiring\SecurityWiring} rejects, leaving the encryptor, the
 * session encrypter and the audit logger unbound. `session_encryption` read
 * `security.session.encryption` and reported the operator's request back,
 * printing `[ok] session_encryption` over sessions being written in cleartext.
 * Both now decide from {@see SecurityRuntimeBindings} — what the wired container
 * actually holds — and `master_key` additionally runs the supplied value through
 * {@see MasterKey::fromHex()}, the same parse the wiring performs, so this report
 * and that wiring can no longer disagree about whether a key is usable.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityPostureCheck
{
    /** Minimum acceptable HSTS max-age (1 year). */
    private const int MIN_HSTS_MAX_AGE = 31_536_000;

    /** Minimum master-key hex length (32 bytes). */
    private const int MIN_MASTER_KEY_HEX_LENGTH = 64;

    /** Response headers every deployment should set. */
    private const array REQUIRED_HEADERS = ['X-Content-Type-Options', 'X-Frame-Options'];

    /**
     * @param SecurityRuntimeBindings $bindings What the wired container holds.
     *        Required, and deliberately has no "unobserved" value: an item that
     *        cannot tell "nobody looked" from "looked and found nothing" is how
     *        an inert control comes to be reported healthy.
     * @param list<DegradedFeature> $degradedSecurityFeatures Security features
     *        disabled by a missing binding (from the wiring-contract detector).
     */
    public function __construct(
        private SecurityConfig $config,
        private bool $isProduction,
        private bool $debugMode,
        private ?string $masterKey,
        private SecurityRuntimeBindings $bindings,
        private array $degradedSecurityFeatures = [],
    ) {}

    public function evaluate(): SecurityPostureReport
    {
        $items = [
            $this->checkDebugMode(),
            $this->checkCsrf(),
            $this->checkHttpsHsts(),
            $this->checkMasterKey(),
            $this->checkSessionEncryption(),
            $this->checkSessionCookieSecure(),
            $this->checkSessionCookieHttpOnly(),
            $this->checkSessionCookieSameSite(),
            $this->checkSecurityHeaders(),
        ];

        foreach ($this->degradedSecurityFeatures as $feature) {
            $items[] = SecurityPostureItem::fail(
                'feature:' . $feature->component . '.' . $feature->feature,
                sprintf('Security feature is inert — %s is not bound', $feature->missingBinding),
                $feature->fix,
            );
        }

        return new SecurityPostureReport($items);
    }

    /**
     * In production a FAIL; outside production the same weakness is a DEGRADED
     * warning (so it is visible without breaking local development).
     */
    private function failOrDegrade(string $name, string $reason, string $fix): SecurityPostureItem
    {
        return $this->isProduction
            ? SecurityPostureItem::fail($name, $reason, $fix)
            : SecurityPostureItem::degraded($name, $reason, $fix);
    }

    private function checkDebugMode(): SecurityPostureItem
    {
        if (!$this->debugMode) {
            return SecurityPostureItem::ok('debug_mode', 'Debug mode is disabled');
        }

        if ($this->isProduction) {
            return SecurityPostureItem::fail(
                'debug_mode',
                'Debug mode is enabled in production — stack traces and internals leak to clients',
                'Set APP_DEBUG=false (or app.debug=false) in production',
            );
        }

        // Not `ok`: debug mode is ON. The build is not failed over it outside
        // production, which is a decision about this environment, not a finding
        // that stack traces are no longer reaching clients.
        return SecurityPostureItem::relaxed(
            'debug_mode',
            'Debug mode is ENABLED — stack traces and internals leak to clients; not failed '
                . 'outside production',
            'Set APP_DEBUG=false (or app.debug=false) before this deployment handles real data',
        );
    }

    private function checkCsrf(): SecurityPostureItem
    {
        if ($this->config->csrf->enabled) {
            return SecurityPostureItem::ok('csrf_protection', 'CSRF protection is enabled');
        }

        return $this->failOrDegrade(
            'csrf_protection',
            'CSRF protection is disabled — state-changing requests are unprotected',
            'Set security.csrf.enabled=true',
        );
    }

    private function checkHttpsHsts(): SecurityPostureItem
    {
        $hsts = $this->config->headers->hsts;

        if ($hsts->emittedAtEdge) {
            return SecurityPostureItem::ok('https_hsts', 'HTTPS/HSTS asserted at the edge (headers.hsts.emitted_at_edge)');
        }

        // The non-production case used to short-circuit above this point and report
        // Ok with the reason "HTTPS/HSTS not enforced outside production" — a
        // sentence stating the control does not hold, recorded as the control
        // holding, and read as such by the compliance evidence gatherer. It is now
        // evaluated like any other environment and relaxed only where it genuinely
        // is not enforced, which also means a development deployment that DOES
        // enforce HSTS is finally credited for it.
        if (!$hsts->enabled) {
            $fix = 'Set security.headers.hsts.enabled=true, or headers.hsts.emitted_at_edge=true '
                . 'if the edge asserts it';

            return $this->isProduction
                ? SecurityPostureItem::fail(
                    'https_hsts',
                    'HSTS is not asserted — connections may be downgraded to HTTP',
                    $fix,
                )
                : SecurityPostureItem::relaxed(
                    'https_hsts',
                    'HSTS is not asserted, and connections may be downgraded to HTTP; not failed '
                        . 'outside production',
                    $fix,
                );
        }

        if ($hsts->maxAge < self::MIN_HSTS_MAX_AGE) {
            return SecurityPostureItem::degraded(
                'https_hsts',
                sprintf('HSTS max-age is %d, below the recommended %d (1 year)', $hsts->maxAge, self::MIN_HSTS_MAX_AGE),
                sprintf('Set security.headers.hsts.max_age to at least %d', self::MIN_HSTS_MAX_AGE),
            );
        }

        return SecurityPostureItem::ok('https_hsts', 'HSTS is enabled with a sufficient max-age');
    }

    /**
     * A rejected key is a FAIL in every environment, never a DEGRADED.
     *
     * {@see failOrDegrade()} exists for controls that are weaker than they
     * should be but still working, which is not what this is: the operator
     * supplied a key, the crypto stack refused it, and the process is serving
     * requests with the encryptor, the session encrypter and the audit logger
     * all unbound. That is the "absent or inert" case
     * {@see SecurityPostureStatus::Fail} is defined for, and it is exactly as
     * broken on a laptop as it is in production — nothing about a development
     * environment makes an unreadable key readable.
     */
    private function checkMasterKey(): SecurityPostureItem
    {
        if ($this->masterKey === null || $this->masterKey === '') {
            return $this->failOrDegrade(
                'master_key',
                'PULSAR_MASTER_KEY is not set — encryption, signing and replay protection cannot operate',
                'Generate a 32-byte key and set PULSAR_MASTER_KEY (64 hex chars)',
            );
        }

        // The wiring's own verdict, and therefore the authoritative one: it also
        // parses PULSAR_MASTER_KEY_PREVIOUS, which this class never sees, so a
        // rotation key that does not decode fails there and nowhere else.
        if ($this->bindings->masterKeyFailure !== null) {
            return SecurityPostureItem::fail(
                'master_key',
                sprintf(
                    'PULSAR_MASTER_KEY was supplied and rejected (%s) — the encryptor, session '
                        . 'encryption and the audit logger are all unbound',
                    $this->bindings->masterKeyFailure,
                ),
                'Set PULSAR_MASTER_KEY (and PULSAR_MASTER_KEY_PREVIOUS, when rotating) to 64 '
                    . 'hex characters decoding to 32 bytes',
            );
        }

        if (strlen($this->masterKey) < self::MIN_MASTER_KEY_HEX_LENGTH) {
            return $this->failOrDegrade(
                'master_key',
                sprintf('Master key is %d hex chars, below the required %d (32 bytes)', strlen($this->masterKey), self::MIN_MASTER_KEY_HEX_LENGTH),
                'Use a full 32-byte key (64 hex chars) for PULSAR_MASTER_KEY',
            );
        }

        // Length is a property of the string; usability is a property of the key.
        // Sixty-four characters that are not hex are the input that produced
        // `[ok] master_key` beside an unbound encryptor, so the value goes through
        // the same parse the wiring performs rather than past a strlen().
        $rejection = self::parseFailure($this->masterKey);

        if ($rejection !== null) {
            return SecurityPostureItem::fail(
                'master_key',
                sprintf(
                    'PULSAR_MASTER_KEY is %d characters but does not decode to a usable key (%s) — '
                        . 'nothing that encrypts, signs or audits can start',
                    strlen($this->masterKey),
                    $rejection,
                ),
                'Use 64 hex characters decoding to 32 bytes for PULSAR_MASTER_KEY',
            );
        }

        if (!$this->bindings->masterKeyBound) {
            return SecurityPostureItem::fail(
                'master_key',
                'PULSAR_MASTER_KEY parses but no MasterKey is bound — the cryptographic stack '
                    . 'did not start, so encryption, signing and replay protection are inert',
                'Check the boot log for the reason SecurityWiring abandoned the crypto stack',
            );
        }

        if (!$this->bindings->encryptorBound) {
            return SecurityPostureItem::degraded(
                'master_key',
                'A MasterKey is bound but no EncryptorInterface is — subkeys can be derived, '
                    . 'but nothing in the application can encrypt',
                'Check the boot log for the reason SecurityWiring stopped before binding the encryptor',
            );
        }

        return SecurityPostureItem::ok(
            'master_key',
            'Master key decodes to 32 bytes, and MasterKey and the encryptor are both bound',
        );
    }

    /**
     * Why {@see MasterKey::fromHex()} refuses the supplied value, or null when
     * it accepts it.
     *
     * The parse is the point: it is the identical call
     * {@see \Pulsar\Core\Wiring\SecurityWiring} makes, so this report cannot
     * conclude "usable" about a value that wiring threw away. The resulting key
     * is discarded immediately — {@see MasterKey::__destruct()} zeroes its
     * material — because nothing here needs to hold key bytes.
     */
    private static function parseFailure(
        #[SensitiveParameter]
        string $hex,
    ): ?string {
        try {
            $parsed = MasterKey::fromHex($hex);
        } catch (SecurityException | SodiumException $rejected) {
            return $rejected->getMessage();
        }

        unset($parsed);

        return null;
    }

    /**
     * Configuration asks for session encryption; the container decides whether
     * it happens.
     *
     * `security.session.encryption = true` used to be the whole answer here, so
     * this item printed `[ok]` for a deployment whose SessionEncryption was
     * never built — the setting is honoured only inside the block
     * {@see \Pulsar\Core\Wiring\SecurityWiring} skips when the master key is
     * absent or rejected, and every session written afterwards is cleartext.
     */
    private function checkSessionEncryption(): SecurityPostureItem
    {
        if (!$this->config->session->encryption) {
            return $this->failOrDegrade(
                'session_encryption',
                'Session encryption is disabled — session payloads are stored in cleartext',
                'Set security.session.encryption=true',
            );
        }

        if (!$this->bindings->sessionEncryptionBound) {
            return SecurityPostureItem::fail(
                'session_encryption',
                'Session encryption is enabled but no SessionEncryption is bound — session '
                    . 'payloads are being written in cleartext',
                'SessionEncryption is derived from the master key: set PULSAR_MASTER_KEY to 64 '
                    . 'hex characters decoding to 32 bytes',
            );
        }

        return SecurityPostureItem::ok(
            'session_encryption',
            'Session encryption is enabled and a SessionEncryption is bound',
        );
    }

    private function checkSessionCookieSecure(): SecurityPostureItem
    {
        if ($this->config->session->cookieSecure) {
            return SecurityPostureItem::ok('session_cookie_secure', 'Session cookie has the Secure flag');
        }

        if (!$this->isProduction) {
            return SecurityPostureItem::relaxed(
                'session_cookie_secure',
                'Session cookie lacks the Secure flag and can leak over HTTP; not failed outside '
                    . 'production (HTTP dev)',
                'Set security.session.cookie_secure=true (or SESSION_COOKIE_SECURE=true)',
            );
        }

        return SecurityPostureItem::fail(
            'session_cookie_secure',
            'Session cookie lacks the Secure flag in production — it can leak over HTTP',
            'Set security.session.cookie_secure=true (or SESSION_COOKIE_SECURE=true)',
        );
    }

    private function checkSessionCookieHttpOnly(): SecurityPostureItem
    {
        if ($this->config->session->cookieHttpOnly) {
            return SecurityPostureItem::ok('session_cookie_httponly', 'Session cookie has the HttpOnly flag');
        }

        return $this->failOrDegrade(
            'session_cookie_httponly',
            'Session cookie lacks HttpOnly — it is readable from JavaScript (XSS theft)',
            'Set security.session.cookie_httponly=true',
        );
    }

    private function checkSessionCookieSameSite(): SecurityPostureItem
    {
        $sameSite = $this->config->session->cookieSameSite;

        if ($sameSite === '') {
            return $this->failOrDegrade(
                'session_cookie_samesite',
                'Session cookie has no SameSite attribute — weaker CSRF defence in depth',
                "Set security.session.cookie_samesite to 'Lax' or 'Strict'",
            );
        }

        if (strcasecmp($sameSite, 'None') === 0 && !$this->config->session->cookieSecure) {
            return $this->failOrDegrade(
                'session_cookie_samesite',
                'SameSite=None without Secure — the cookie is rejected by browsers',
                'Use SameSite=Lax/Strict, or set cookie_secure=true with SameSite=None',
            );
        }

        return SecurityPostureItem::ok('session_cookie_samesite', sprintf('Session cookie SameSite=%s', $sameSite));
    }

    private function checkSecurityHeaders(): SecurityPostureItem
    {
        $headers = $this->config->headers->effectiveHeaders();
        $missing = [];

        foreach (self::REQUIRED_HEADERS as $header) {
            if (!isset($headers[$header])) {
                $missing[] = $header;
            }
        }

        if ($missing === []) {
            return SecurityPostureItem::ok('security_headers', 'Baseline security response headers are set');
        }

        return $this->failOrDegrade(
            'security_headers',
            'Missing baseline security headers: ' . implode(', ', $missing),
            'Configure security.headers (X-Content-Type-Options, X-Frame-Options, …)',
        );
    }
}
