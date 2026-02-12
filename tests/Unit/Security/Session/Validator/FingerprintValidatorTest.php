<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Session\SessionMetadata;
use Pulsar\Security\Session\Validator\FingerprintValidator;

use function random_bytes;

#[CoversClass(FingerprintValidator::class)]
final class FingerprintValidatorTest extends TestCase
{
    private string $hmacKey;

    private HmacService $hmac;

    protected function setUp(): void
    {
        $this->hmacKey = random_bytes(32);
        $this->hmac = new HmacService();
    }

    /**
     * @param array<string, list<string>|string> $headers
     */
    private function createRequestWithHeaders(array $headers): ServerRequestInterface
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: $headers,
        );
    }

    #[Test]
    public function validatePassesWhenFingerprintIsNull(): void
    {
        $validator = new FingerprintValidator($this->hmac, $this->hmacKey);

        $metadata = new SessionMetadata(
            createdAt: time(),
            lastActivity: time(),
            ipAddress: '127.0.0.1',
            userAgent: '',
            fingerprint: null,
        );

        $request = $this->createRequestWithHeaders([
            'Accept-Language' => 'en-US',
            'Accept-Encoding' => 'gzip',
        ]);

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function validatePassesWhenFingerprintMatches(): void
    {
        $validator = new FingerprintValidator($this->hmac, $this->hmacKey);

        $request = $this->createRequestWithHeaders([
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br',
        ]);

        // Compute the expected fingerprint
        $fingerprint = $validator->computeFingerprint($request);

        $metadata = new SessionMetadata(
            createdAt: time(),
            lastActivity: time(),
            ipAddress: '127.0.0.1',
            userAgent: '',
            fingerprint: $fingerprint,
        );

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function validateFailsWhenFingerprintDiffers(): void
    {
        $validator = new FingerprintValidator($this->hmac, $this->hmacKey);

        $metadata = new SessionMetadata(
            createdAt: time(),
            lastActivity: time(),
            ipAddress: '127.0.0.1',
            userAgent: '',
            fingerprint: 'deadbeef1234567890abcdef',
        );

        $request = $this->createRequestWithHeaders([
            'Accept-Language' => 'en-US',
            'Accept-Encoding' => 'gzip',
        ]);

        self::assertFalse($validator->validate($metadata, $request));
    }

    #[Test]
    public function getNameReturnsFingerprint(): void
    {
        $validator = new FingerprintValidator($this->hmac, $this->hmacKey);

        self::assertSame('fingerprint', $validator->getName());
    }

    #[Test]
    public function computeFingerprintIsDeterministic(): void
    {
        $validator = new FingerprintValidator($this->hmac, $this->hmacKey);

        $request = $this->createRequestWithHeaders([
            'Accept-Language' => 'fr-FR',
            'Accept-Encoding' => 'br',
        ]);

        $fp1 = $validator->computeFingerprint($request);
        $fp2 = $validator->computeFingerprint($request);

        self::assertSame($fp1, $fp2);
    }

    #[Test]
    public function differentHeadersProduceDifferentFingerprints(): void
    {
        $validator = new FingerprintValidator($this->hmac, $this->hmacKey);

        $request1 = $this->createRequestWithHeaders([
            'Accept-Language' => 'en-US',
            'Accept-Encoding' => 'gzip',
        ]);

        $request2 = $this->createRequestWithHeaders([
            'Accept-Language' => 'fr-FR',
            'Accept-Encoding' => 'br',
        ]);

        $fp1 = $validator->computeFingerprint($request1);
        $fp2 = $validator->computeFingerprint($request2);

        self::assertNotSame($fp1, $fp2);
    }

    #[Test]
    public function differentKeysProduceDifferentFingerprints(): void
    {
        $key1 = random_bytes(32);
        $key2 = random_bytes(32);

        $validator1 = new FingerprintValidator($this->hmac, $key1);
        $validator2 = new FingerprintValidator($this->hmac, $key2);

        $request = $this->createRequestWithHeaders([
            'Accept-Language' => 'en-US',
            'Accept-Encoding' => 'gzip',
        ]);

        $fp1 = $validator1->computeFingerprint($request);
        $fp2 = $validator2->computeFingerprint($request);

        self::assertNotSame($fp1, $fp2);
    }

    #[Test]
    public function customAttributesUsedInFingerprint(): void
    {
        $validator = new FingerprintValidator(
            $this->hmac,
            $this->hmacKey,
            ['accept_language'],
        );

        $request1 = $this->createRequestWithHeaders([
            'Accept-Language' => 'en-US',
            'Accept-Encoding' => 'gzip',
        ]);

        $request2 = $this->createRequestWithHeaders([
            'Accept-Language' => 'en-US',
            'Accept-Encoding' => 'br',
        ]);

        // Only Accept-Language is in attributes, so different Accept-Encoding shouldn't matter
        $fp1 = $validator->computeFingerprint($request1);
        $fp2 = $validator->computeFingerprint($request2);

        self::assertSame($fp1, $fp2);
    }

    #[Test]
    public function stabilityAcrossNormalBrowsing(): void
    {
        $validator = new FingerprintValidator($this->hmac, $this->hmacKey);

        // Same browser, same headers — fingerprint should remain stable
        $request = $this->createRequestWithHeaders([
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br, zstd',
        ]);

        $fingerprint = $validator->computeFingerprint($request);

        $metadata = new SessionMetadata(
            createdAt: time() - 3600,
            lastActivity: time(),
            ipAddress: '10.0.0.1',
            userAgent: 'Mozilla/5.0',
            fingerprint: $fingerprint,
        );

        // Repeated validation should always pass
        self::assertTrue($validator->validate($metadata, $request));
        self::assertTrue($validator->validate($metadata, $request));
    }
}
