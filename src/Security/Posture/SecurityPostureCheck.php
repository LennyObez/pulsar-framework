<?php

declare(strict_types=1);

namespace Pulsar\Security\Posture;

use Pulsar\Api\Api;
use Pulsar\Config\SecurityConfig;
use Pulsar\Core\Wiring\Contract\DegradedFeature;

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
     * @param list<DegradedFeature> $degradedSecurityFeatures Security features
     *        disabled by a missing binding (from the wiring-contract detector).
     */
    public function __construct(
        private SecurityConfig $config,
        private bool $isProduction,
        private bool $debugMode,
        private ?string $masterKey,
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

        return SecurityPostureItem::ok('debug_mode', 'Debug mode is enabled (expected outside production)');
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

        if (!$this->isProduction) {
            return SecurityPostureItem::ok('https_hsts', 'HTTPS/HSTS not enforced outside production');
        }

        if (!$hsts->enabled) {
            return SecurityPostureItem::fail(
                'https_hsts',
                'HSTS is not enabled — connections may be downgraded to HTTP',
                'Set security.headers.hsts.enabled=true',
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

    private function checkMasterKey(): SecurityPostureItem
    {
        if ($this->masterKey === null || $this->masterKey === '') {
            return $this->failOrDegrade(
                'master_key',
                'PULSAR_MASTER_KEY is not set — encryption, signing and replay protection cannot operate',
                'Generate a 32-byte key and set PULSAR_MASTER_KEY (64 hex chars)',
            );
        }

        if (strlen($this->masterKey) < self::MIN_MASTER_KEY_HEX_LENGTH) {
            return $this->failOrDegrade(
                'master_key',
                sprintf('Master key is %d hex chars, below the required %d (32 bytes)', strlen($this->masterKey), self::MIN_MASTER_KEY_HEX_LENGTH),
                'Use a full 32-byte key (64 hex chars) for PULSAR_MASTER_KEY',
            );
        }

        return SecurityPostureItem::ok('master_key', 'Master key is present and of sufficient length');
    }

    private function checkSessionEncryption(): SecurityPostureItem
    {
        if ($this->config->session->encryption) {
            return SecurityPostureItem::ok('session_encryption', 'Session encryption is enabled');
        }

        return $this->failOrDegrade(
            'session_encryption',
            'Session encryption is disabled — session payloads are stored in cleartext',
            'Set security.session.encryption=true',
        );
    }

    private function checkSessionCookieSecure(): SecurityPostureItem
    {
        if ($this->config->session->cookieSecure) {
            return SecurityPostureItem::ok('session_cookie_secure', 'Session cookie has the Secure flag');
        }

        if (!$this->isProduction) {
            return SecurityPostureItem::ok('session_cookie_secure', 'Secure flag relaxed outside production (HTTP dev)');
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
