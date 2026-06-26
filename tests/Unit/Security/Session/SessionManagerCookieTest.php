<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionManager;

use function explode;
use function str_contains;

/**
 * Unit tests for the session-cookie emission added to {@see SessionManager}
 * (pendingSetCookieHeader). Covers the new/returning/rotated/destroyed states
 * and the full RFC 6265 attribute matrix, including the `__Host-` and
 * `SameSite=None` Secure-flag requirements.
 */
#[CoversClass(SessionManager::class)]
final class SessionManagerCookieTest extends TestCase
{
    private const string VALID_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; // 64 hex

    private function config(
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
        string $path = '/',
        string $domain = '',
        int $lifetime = 3600,
        bool $hostPrefix = false,
    ): SessionConfig {
        return new SessionConfig(
            cookieName: 'PULSAR_SESSION',
            lifetime: $lifetime,
            cookieHttpOnly: $httpOnly,
            cookieSecure: $secure,
            cookieSameSite: $sameSite,
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
            cookiePath: $path,
            cookieDomain: $domain,
            cookieHostPrefix: $hostPrefix,
        );
    }

    private function manager(SessionConfig $config): SessionManager
    {
        return new SessionManager(new ArrayHandler(), $config);
    }

    private function requestWithCookie(string $name, string $value): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'PHPUnit'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: [$name => $value],
        );
    }

    #[Test]
    public function new_session_emits_set_cookie_with_generated_id(): void
    {
        $sm = $this->manager($this->config());
        $sm->start();

        $header = $sm->pendingSetCookieHeader();

        self::assertNotNull($header);
        self::assertStringStartsWith('PULSAR_SESSION=' . $sm->id(), $header);
        self::assertStringContainsString('Path=/', $header);
        self::assertStringContainsString('Max-Age=3600', $header);
        self::assertStringContainsString('Secure', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringNotContainsString('Domain=', $header);
    }

    #[Test]
    public function returning_session_id_is_not_re_emitted(): void
    {
        $sm = $this->manager($this->config());
        $sm->startWithRequest($this->requestWithCookie('PULSAR_SESSION', self::VALID_ID));

        self::assertSame(self::VALID_ID, $sm->id(), 'precondition: id taken from the incoming cookie');
        self::assertNull($sm->pendingSetCookieHeader(), 'a client-supplied id must not be re-sent');
    }

    #[Test]
    public function rotated_session_emits_the_new_id(): void
    {
        $sm = $this->manager($this->config());
        $sm->startWithRequest($this->requestWithCookie('PULSAR_SESSION', self::VALID_ID));
        self::assertNull($sm->pendingSetCookieHeader());

        $sm->regenerate();
        $newId = $sm->id();

        $header = $sm->pendingSetCookieHeader();
        self::assertNotNull($header);
        self::assertNotSame(self::VALID_ID, $newId);
        self::assertStringStartsWith('PULSAR_SESSION=' . $newId, $header);
    }

    #[Test]
    public function destroyed_session_emits_an_expiring_cookie(): void
    {
        $sm = $this->manager($this->config());
        $sm->startWithRequest($this->requestWithCookie('PULSAR_SESSION', self::VALID_ID));

        $sm->destroy();

        $header = $sm->pendingSetCookieHeader();
        self::assertNotNull($header);
        self::assertStringStartsWith('PULSAR_SESSION=;', $header, 'cleared cookie has an empty value');
        self::assertStringContainsString('Max-Age=0', $header);
    }

    #[Test]
    public function untouched_session_emits_nothing(): void
    {
        // startWithRequest with a returning id, no rotation, no destroy.
        $sm = $this->manager($this->config());
        $sm->startWithRequest($this->requestWithCookie('PULSAR_SESSION', self::VALID_ID));

        self::assertNull($sm->pendingSetCookieHeader());
    }

    #[Test]
    public function host_prefix_forces_secure_root_path_and_no_domain(): void
    {
        // Misconfigured on purpose: secure=false, a non-root path and a domain —
        // the __Host- prefix must override all three for a valid cookie.
        $sm = $this->manager($this->config(
            secure: false,
            path: '/app',
            domain: 'example.com',
            hostPrefix: true,
        ));
        $sm->start();

        $header = $sm->pendingSetCookieHeader();
        self::assertNotNull($header);
        self::assertStringStartsWith('__Host-PULSAR_SESSION=', $header);
        self::assertStringContainsString('Path=/', $header);
        self::assertStringNotContainsString('Path=/app', $header);
        self::assertStringNotContainsString('Domain=', $header);
        self::assertStringContainsString('Secure', $header);
    }

    #[Test]
    public function samesite_none_forces_secure_even_when_not_configured(): void
    {
        $sm = $this->manager($this->config(secure: false, sameSite: 'None'));
        $sm->start();

        $header = $sm->pendingSetCookieHeader();
        self::assertNotNull($header);
        self::assertStringContainsString('SameSite=None', $header);
        self::assertStringContainsString('Secure', $header, 'SameSite=None requires Secure or the browser drops it');
    }

    #[Test]
    public function zero_lifetime_omits_max_age_for_a_browser_session_cookie(): void
    {
        $sm = $this->manager($this->config(lifetime: 0));
        $sm->start();

        $header = $sm->pendingSetCookieHeader();
        self::assertNotNull($header);
        self::assertStringNotContainsString('Max-Age', $header);
    }

    #[Test]
    public function domain_is_included_when_configured_without_host_prefix(): void
    {
        $sm = $this->manager($this->config(domain: 'example.com'));
        $sm->start();

        $header = $sm->pendingSetCookieHeader();
        self::assertNotNull($header);
        self::assertStringContainsString('Domain=example.com', $header);
    }

    #[Test]
    public function httponly_omitted_when_disabled(): void
    {
        $sm = $this->manager($this->config(httpOnly: false));
        $sm->start();

        $header = $sm->pendingSetCookieHeader();
        self::assertNotNull($header);
        self::assertStringNotContainsString('HttpOnly', $header);

        // Sanity: the attribute separator format is correct (no trailing/empty parts).
        foreach (explode('; ', $header) as $part) {
            self::assertNotSame('', $part);
            self::assertFalse(str_contains($part, ';'));
        }
    }

    #[Test]
    public function not_started_session_emits_nothing(): void
    {
        $sm = $this->manager($this->config());

        self::assertNull($sm->pendingSetCookieHeader());
    }
}
