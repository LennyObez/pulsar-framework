<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\TrustedProxy;
use Pulsar\Security\Session\SessionMetadata;
use Pulsar\Security\Session\Validator\RemoteAddressValidator;

#[CoversClass(RemoteAddressValidator::class)]
final class RemoteAddressValidatorTest extends TestCase
{
    private function createRequestWithRemoteAddr(string $ip): ServerRequestInterface
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/',
            serverParams: ['REMOTE_ADDR' => $ip],
        );
    }

    private function createMetadataWithIp(string $ip): SessionMetadata
    {
        return new SessionMetadata(
            createdAt: time(),
            lastActivity: time(),
            ipAddress: $ip,
            userAgent: '',
        );
    }

    #[Test]
    public function subnetModeIpv4SameSubnetPasses(): void
    {
        $validator = new RemoteAddressValidator(mode: 'subnet', ipv4Mask: 24);

        $metadata = $this->createMetadataWithIp('192.168.1.100');
        $request = $this->createRequestWithRemoteAddr('192.168.1.200');

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function subnetModeIpv4DifferentSubnetFails(): void
    {
        $validator = new RemoteAddressValidator(mode: 'subnet', ipv4Mask: 24);

        $metadata = $this->createMetadataWithIp('192.168.1.100');
        $request = $this->createRequestWithRemoteAddr('192.168.2.100');

        self::assertFalse($validator->validate($metadata, $request));
    }

    #[Test]
    public function strictModeExactMatchPasses(): void
    {
        $validator = new RemoteAddressValidator(mode: 'strict');

        $metadata = $this->createMetadataWithIp('10.0.0.1');
        $request = $this->createRequestWithRemoteAddr('10.0.0.1');

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function trustedProxyResolvesForwardedClientForComparison(): void
    {
        $validator = new RemoteAddressValidator(mode: 'strict', trustedProxy: new TrustedProxy(['10.0.0.0/8']));

        // Stored IP is the real client; request arrives via the trusted proxy.
        // Resolution must compare the forwarded client, not the proxy address.
        $metadata = $this->createMetadataWithIp('203.0.113.5');
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['X-Forwarded-For' => '203.0.113.5'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function trustedProxyStillFailsOnRealClientMismatch(): void
    {
        $validator = new RemoteAddressValidator(mode: 'strict', trustedProxy: new TrustedProxy(['10.0.0.0/8']));

        $metadata = $this->createMetadataWithIp('203.0.113.5');
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['X-Forwarded-For' => '198.51.100.9'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        self::assertFalse($validator->validate($metadata, $request));
    }

    #[Test]
    public function strictModeDifferentIpFails(): void
    {
        $validator = new RemoteAddressValidator(mode: 'strict');

        $metadata = $this->createMetadataWithIp('10.0.0.1');
        $request = $this->createRequestWithRemoteAddr('10.0.0.2');

        self::assertFalse($validator->validate($metadata, $request));
    }

    #[Test]
    public function getNameReturnsRemoteAddress(): void
    {
        $validator = new RemoteAddressValidator();

        self::assertSame('remote_address', $validator->getName());
    }

    #[Test]
    public function subnetModeIpv4WideMaskPasses(): void
    {
        $validator = new RemoteAddressValidator(mode: 'subnet', ipv4Mask: 16);

        $metadata = $this->createMetadataWithIp('192.168.1.100');
        $request = $this->createRequestWithRemoteAddr('192.168.200.50');

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function invalidIpReturnsFalse(): void
    {
        $validator = new RemoteAddressValidator(mode: 'subnet', ipv4Mask: 24);

        $metadata = $this->createMetadataWithIp('not-an-ip');
        $request = $this->createRequestWithRemoteAddr('192.168.1.1');

        self::assertFalse($validator->validate($metadata, $request));
    }

    #[Test]
    public function ipv6SameSubnetPasses(): void
    {
        $validator = new RemoteAddressValidator(mode: 'subnet', ipv6Mask: 48);

        $metadata = $this->createMetadataWithIp('2001:db8:1234::1');
        $request = $this->createRequestWithRemoteAddr('2001:db8:1234::ffff');

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function ipv6DifferentSubnetFails(): void
    {
        $validator = new RemoteAddressValidator(mode: 'subnet', ipv6Mask: 48);

        $metadata = $this->createMetadataWithIp('2001:db8:1234::1');
        $request = $this->createRequestWithRemoteAddr('2001:db8:5678::1');

        self::assertFalse($validator->validate($metadata, $request));
    }
}
