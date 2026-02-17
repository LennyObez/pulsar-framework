<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\WebSocket\Internal\HandshakeResult;
use Pulsar\WebSocket\Internal\HandshakeValidator;

#[CoversClass(HandshakeValidator::class)]
#[CoversClass(HandshakeResult::class)]
final class HandshakeValidatorTest extends TestCase
{
    private HandshakeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new HandshakeValidator();
    }

    #[Test]
    public function validHandshakeIsAccepted(): void
    {
        $result = $this->validator->validate('GET', [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-version' => '13',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ]);

        self::assertTrue($result->accepted);
        self::assertNotEmpty($result->acceptKey);
        self::assertSame('', $result->rejectionReason);
    }

    #[Test]
    public function postMethodIsRejected(): void
    {
        $result = $this->validator->validate('POST', [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-version' => '13',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ]);

        self::assertFalse($result->accepted);
        self::assertStringContainsString('GET', $result->rejectionReason);
    }

    #[Test]
    public function missingUpgradeHeaderIsRejected(): void
    {
        $result = $this->validator->validate('GET', [
            'connection' => 'Upgrade',
            'sec-websocket-version' => '13',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ]);

        self::assertFalse($result->accepted);
        self::assertStringContainsString('Upgrade', $result->rejectionReason);
    }

    #[Test]
    public function wrongUpgradeValueIsRejected(): void
    {
        $result = $this->validator->validate('GET', [
            'upgrade' => 'h2c',
            'connection' => 'Upgrade',
            'sec-websocket-version' => '13',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ]);

        self::assertFalse($result->accepted);
    }

    #[Test]
    public function missingConnectionUpgradeIsRejected(): void
    {
        $result = $this->validator->validate('GET', [
            'upgrade' => 'websocket',
            'connection' => 'keep-alive',
            'sec-websocket-version' => '13',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ]);

        self::assertFalse($result->accepted);
    }

    #[Test]
    public function wrongVersionIsRejected(): void
    {
        $result = $this->validator->validate('GET', [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-version' => '8',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ]);

        self::assertFalse($result->accepted);
        self::assertStringContainsString('version', $result->rejectionReason);
    }

    #[Test]
    public function missingKeyIsRejected(): void
    {
        $result = $this->validator->validate('GET', [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-version' => '13',
        ]);

        self::assertFalse($result->accepted);
        self::assertStringContainsString('Key', $result->rejectionReason);
    }

    #[Test]
    public function computeAcceptKeyFollowsRfc6455Algorithm(): void
    {
        // Verify the algorithm: SHA-1(key + GUID) → base64
        $clientKey = 'dGhlIHNhbXBsZSBub25jZQ==';
        $guid = '258EAFA5-E914-47DA-95CA-5AB5DC11D700';
        $expected = base64_encode(sha1($clientKey . $guid, true));

        $acceptKey = $this->validator->computeAcceptKey($clientKey);

        self::assertSame($expected, $acceptKey);
    }

    #[Test]
    public function buildAcceptResponseContainsCorrectHeaders(): void
    {
        $acceptKey = 'dummyAcceptKey123=';
        $response = $this->validator->buildAcceptResponse($acceptKey);

        self::assertStringContainsString('HTTP/1.1 101 Switching Protocols', $response);
        self::assertStringContainsString('Upgrade: websocket', $response);
        self::assertStringContainsString('Connection: Upgrade', $response);
        self::assertStringContainsString('Sec-WebSocket-Accept: ' . $acceptKey, $response);
        self::assertStringEndsWith("\r\n\r\n", $response);
    }

    #[Test]
    public function caseInsensitiveUpgradeHeader(): void
    {
        $result = $this->validator->validate('GET', [
            'upgrade' => 'WebSocket',
            'connection' => 'upgrade',
            'sec-websocket-version' => '13',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ]);

        self::assertTrue($result->accepted);
    }
}
