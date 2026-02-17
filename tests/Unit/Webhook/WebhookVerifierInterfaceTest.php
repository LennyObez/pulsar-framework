<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\Exception\WebhookException;
use Pulsar\Webhook\WebhookVerifierInterface;

#[CoversClass(WebhookVerifierInterface::class)]
final class WebhookVerifierInterfaceTest extends TestCase
{
    #[Test]
    public function verifyDoesNotThrowOnValidSignature(): void
    {
        $verifier = new class implements WebhookVerifierInterface {
            public function verify(
                string $payload,
                string $signatureHeader,
                string $secret,
                int $toleranceSeconds,
            ): void {
                // Valid signature - no exception
            }
        };

        $verifier->verify('{"event":"test"}', 'v1=abc123', 'secret', 300);

        // If we reach this point, no exception was thrown
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function verifyThrowsOnInvalidSignature(): void
    {
        $verifier = new class implements WebhookVerifierInterface {
            public function verify(
                string $payload,
                string $signatureHeader,
                string $secret,
                int $toleranceSeconds,
            ): void {
                throw WebhookException::invalidSignature();
            }
        };

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('signature verification failed');
        $verifier->verify('tampered', 'v1=bad', 'secret', 300);
    }

    #[Test]
    public function verifyThrowsOnExpiredTimestamp(): void
    {
        $verifier = new class implements WebhookVerifierInterface {
            public function verify(
                string $payload,
                string $signatureHeader,
                string $secret,
                int $toleranceSeconds,
            ): void {
                throw WebhookException::expiredTimestamp(600, $toleranceSeconds);
            }
        };

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('too old');
        $verifier->verify('payload', 'sig', 'secret', 300);
    }

    #[Test]
    public function verifyReceivesAllParameters(): void
    {
        $verifier = new class implements WebhookVerifierInterface {
            public string $receivedPayload = '';
            public string $receivedSig = '';
            public string $receivedSecret = '';
            public int $receivedTolerance = 0;

            public function verify(
                string $payload,
                string $signatureHeader,
                string $secret,
                int $toleranceSeconds,
            ): void {
                $this->receivedPayload = $payload;
                $this->receivedSig = $signatureHeader;
                $this->receivedSecret = $secret;
                $this->receivedTolerance = $toleranceSeconds;
            }
        };

        $verifier->verify('body-bytes', 't=123,v1=sig', 'whsec_abc', 600);

        self::assertSame('body-bytes', $verifier->receivedPayload);
        self::assertSame('t=123,v1=sig', $verifier->receivedSig);
        self::assertSame('whsec_abc', $verifier->receivedSecret);
        self::assertSame(600, $verifier->receivedTolerance);
    }

    #[Test]
    public function verifyThrowsOnMalformedHeader(): void
    {
        $verifier = new class implements WebhookVerifierInterface {
            public function verify(
                string $payload,
                string $signatureHeader,
                string $secret,
                int $toleranceSeconds,
            ): void {
                throw WebhookException::malformedHeader('missing timestamp component');
            }
        };

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('Malformed');
        $verifier->verify('payload', 'bad-header', 'secret', 300);
    }
}
