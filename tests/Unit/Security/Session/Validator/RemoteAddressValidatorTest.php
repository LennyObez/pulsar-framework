<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\Message\ServerRequest;
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
    public function test_subnet_mode_ipv4_same_subnet_passes(): void
    {
        $validator = new RemoteAddressValidator(mode: 'subnet', ipv4Mask: 24);

        $metadata = $this->createMetadataWithIp('192.168.1.100');
        $request = $this->createRequestWithRemoteAddr('192.168.1.200');

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function test_subnet_mode_ipv4_different_subnet_fails(): void
    {
        $validator = new RemoteAddressValidator(mode: 'subnet', ipv4Mask: 24);

        $metadata = $this->createMetadataWithIp('192.168.1.100');
        $request = $this->createRequestWithRemoteAddr('192.168.2.100');

        self::assertFalse($validator->validate($metadata, $request));
    }

    #[Test]
    public function test_strict_mode_exact_match_passes(): void
    {
        $validator = new RemoteAddressValidator(mode: 'strict');

        $metadata = $this->createMetadataWithIp('10.0.0.1');
        $request = $this->createRequestWithRemoteAddr('10.0.0.1');

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function test_strict_mode_different_ip_fails(): void
    {
        $validator = new RemoteAddressValidator(mode: 'strict');

        $metadata = $this->createMetadataWithIp('10.0.0.1');
        $request = $this->createRequestWithRemoteAddr('10.0.0.2');

        self::assertFalse($validator->validate($metadata, $request));
    }

    #[Test]
    public function test_get_name_returns_remote_address(): void
    {
        $validator = new RemoteAddressValidator();

        self::assertSame('remote_address', $validator->getName());
    }

    #[Test]
    public function test_subnet_mode_ipv4_wide_mask_passes(): void
    {
        $validator = new RemoteAddressValidator(mode: 'subnet', ipv4Mask: 16);

        $metadata = $this->createMetadataWithIp('192.168.1.100');
        $request = $this->createRequestWithRemoteAddr('192.168.200.50');

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function test_invalid_ip_returns_false(): void
    {
        $validator = new RemoteAddressValidator(mode: 'subnet', ipv4Mask: 24);

        $metadata = $this->createMetadataWithIp('not-an-ip');
        $request = $this->createRequestWithRemoteAddr('192.168.1.1');

        self::assertFalse($validator->validate($metadata, $request));
    }

    #[Test]
    public function test_ipv6_same_subnet_passes(): void
    {
        $validator = new RemoteAddressValidator(mode: 'subnet', ipv6Mask: 48);

        $metadata = $this->createMetadataWithIp('2001:db8:1234::1');
        $request = $this->createRequestWithRemoteAddr('2001:db8:1234::ffff');

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function test_ipv6_different_subnet_fails(): void
    {
        $validator = new RemoteAddressValidator(mode: 'subnet', ipv6Mask: 48);

        $metadata = $this->createMetadataWithIp('2001:db8:1234::1');
        $request = $this->createRequestWithRemoteAddr('2001:db8:5678::1');

        self::assertFalse($validator->validate($metadata, $request));
    }
}
