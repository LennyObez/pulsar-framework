<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Webhook\ConfigurableIpAllowlist;
use Pulsar\Mail\Webhook\WebhookRequest;

#[CoversClass(ConfigurableIpAllowlist::class)]
final class ConfigurableIpAllowlistTest extends TestCase
{
    #[Test]
    public function emptyRangesAllowsAll(): void
    {
        $allowlist = new ConfigurableIpAllowlist();

        self::assertTrue($allowlist->isAllowed('1.2.3.4', 'mailgun'));
    }

    #[Test]
    public function emptyRangesForProviderAllowsAll(): void
    {
        $allowlist = new ConfigurableIpAllowlist(['sendgrid' => ['10.0.0.0/8']]);

        self::assertTrue($allowlist->isAllowed('1.2.3.4', 'mailgun'));
    }

    #[Test]
    public function exactIpMatch(): void
    {
        $allowlist = new ConfigurableIpAllowlist([
            'mailgun' => ['198.51.100.1'],
        ]);

        self::assertTrue($allowlist->isAllowed('198.51.100.1', 'mailgun'));
        self::assertFalse($allowlist->isAllowed('198.51.100.2', 'mailgun'));
    }

    #[Test]
    #[DataProvider('ipv4CidrProvider')]
    public function ipv4CidrMatching(string $ip, string $cidr, bool $expected): void
    {
        $allowlist = new ConfigurableIpAllowlist([
            'provider' => [$cidr],
        ]);

        self::assertSame($expected, $allowlist->isAllowed($ip, 'provider'));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function ipv4CidrProvider(): iterable
    {
        yield 'in /24 range' => ['192.168.1.50', '192.168.1.0/24', true];
        yield 'outside /24 range' => ['192.168.2.1', '192.168.1.0/24', false];
        yield 'in /16 range' => ['10.0.50.1', '10.0.0.0/16', true];
        yield 'outside /16 range' => ['10.1.0.1', '10.0.0.0/16', false];
        yield '/0 matches all' => ['1.2.3.4', '0.0.0.0/0', true];
        yield '/32 is exact match' => ['10.0.0.1', '10.0.0.1/32', true];
        yield '/32 mismatch' => ['10.0.0.2', '10.0.0.1/32', false];
    }

    #[Test]
    public function providerNameIsCaseInsensitive(): void
    {
        $allowlist = new ConfigurableIpAllowlist([
            'mailgun' => ['10.0.0.1'],
        ]);

        self::assertTrue($allowlist->isAllowed('10.0.0.1', 'MAILGUN'));
        self::assertTrue($allowlist->isAllowed('10.0.0.1', 'Mailgun'));
    }

    #[Test]
    public function multipleRangesPerProvider(): void
    {
        $allowlist = new ConfigurableIpAllowlist([
            'provider' => ['10.0.0.0/8', '172.16.0.0/12'],
        ]);

        self::assertTrue($allowlist->isAllowed('10.1.2.3', 'provider'));
        self::assertTrue($allowlist->isAllowed('172.20.0.1', 'provider'));
        self::assertFalse($allowlist->isAllowed('192.168.1.1', 'provider'));
    }

    #[Test]
    public function webHookRequestProperties(): void
    {
        $request = new WebhookRequest(
            payload: '{}',
            headers: ['X-Key' => 'value'],
            sourceIp: '10.0.0.1',
            timestamp: 1700000000,
            provider: 'ses',
        );

        self::assertSame('{}', $request->payload);
        self::assertSame(['X-Key' => 'value'], $request->headers);
        self::assertSame('10.0.0.1', $request->sourceIp);
        self::assertSame(1700000000, $request->timestamp);
        self::assertSame('ses', $request->provider);
    }
}
