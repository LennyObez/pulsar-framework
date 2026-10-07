<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Internal\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Internal\Security\AnalyticsKeyManager;
use Pulsar\Extension\Analytics\Internal\Security\VisitorConsentIdentity;
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function preg_match;
use function random_bytes;

#[CoversClass(VisitorConsentIdentity::class)]
final class VisitorConsentIdentityTest extends TestCase
{
    private AnalyticsKeyManager $keyManager;

    protected function setUp(): void
    {
        $this->keyManager = new AnalyticsKeyManager(MasterKey::fromHex(bin2hex(random_bytes(32))));
    }

    #[Test]
    public function deriveIsDeterministicAndAKeyedHashNotTheRawIp(): void
    {
        $identity = new VisitorConsentIdentity($this->keyManager, new AnalyticsConfig());
        $request = $this->request('198.51.100.4', 'UA/1.0');

        $subject = $identity->forRequest($request);

        self::assertSame($subject, $identity->forRequest($this->request('198.51.100.4', 'UA/1.0')));
        self::assertNotSame('198.51.100.4', $subject);
        self::assertSame(1, preg_match('/^[0-9a-f]{64}$/', $subject), 'subject must be a 64-char hex HMAC');
        self::assertSame($identity->compute('198.51.100.4', 'UA/1.0'), $subject);
    }

    #[Test]
    public function ignoresForwardedForFromAnUntrustedRemote(): void
    {
        // No trusted proxies configured: a client-supplied X-Forwarded-For must
        // NOT move the subject, or anyone could forge someone else's identifier.
        $identity = new VisitorConsentIdentity($this->keyManager, new AnalyticsConfig());
        $request = $this->request('192.0.2.9', 'UA/1.0', forwardedFor: '203.0.113.77');

        self::assertSame($identity->compute('192.0.2.9', 'UA/1.0'), $identity->forRequest($request));
        self::assertNotSame($identity->compute('203.0.113.77', 'UA/1.0'), $identity->forRequest($request));
    }

    #[Test]
    public function honoursForwardedForOnlyFromAConfiguredTrustedProxy(): void
    {
        // Behind a trusted proxy the real client is the first non-proxy hop.
        $identity = new VisitorConsentIdentity(
            $this->keyManager,
            new AnalyticsConfig(trustedProxies: ['10.0.0.1']),
        );
        $request = $this->request('10.0.0.1', 'UA/1.0', forwardedFor: '203.0.113.7, 10.0.0.1');

        self::assertSame($identity->compute('203.0.113.7', 'UA/1.0'), $identity->forRequest($request));
    }

    private function request(string $remoteAddr, string $userAgent, string $forwardedFor = ''): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $remoteAddr]);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => match ($name) {
                'User-Agent' => $userAgent,
                'X-Forwarded-For' => $forwardedFor,
                default => '',
            },
        );

        return $request;
    }
}
