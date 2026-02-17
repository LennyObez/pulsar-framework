<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit\Internal\Signature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Eidas\Domain\SignatureFormat;
use Pulsar\Extension\Eidas\Exception\EidasException;
use Pulsar\Extension\Eidas\Internal\Signature\HmacSignatureService;

final class HmacSignatureServiceTest extends TestCase
{
    #[Test]
    public function signAndVerifyRoundTrip(): void
    {
        $service = new HmacSignatureService(keys: ['key_001' => 'secret123'], appEnv: 'testing');
        $data = 'Important document content';

        $signature = $service->sign($data, 'key_001');
        $info = $service->verify($data, $signature);

        self::assertTrue($info->valid);
        self::assertSame('key_001', $info->signerName);
        self::assertSame(SignatureFormat::JAdES, $info->format);
    }

    #[Test]
    public function signWithUnknownKeyThrows(): void
    {
        $service = new HmacSignatureService(keys: [], appEnv: 'testing');

        $this->expectException(EidasException::class);
        $this->expectExceptionMessage('Unknown signer key');

        $service->sign('data', 'nonexistent');
    }

    #[Test]
    public function verifyWithTamperedDataReturnsFalse(): void
    {
        $service = new HmacSignatureService(keys: ['key_001' => 'secret123'], appEnv: 'testing');
        $signature = $service->sign('original data', 'key_001');

        $info = $service->verify('tampered data', $signature);

        self::assertFalse($info->valid);
        self::assertSame('MAC mismatch', $info->reason);
    }

    #[Test]
    public function verifyWithUnknownKeyReturnsFalse(): void
    {
        $service = new HmacSignatureService(keys: ['key_001' => 'secret123'], appEnv: 'testing');
        $signature = $service->sign('data', 'key_001');

        // Verify with a service that doesn't know the key
        $otherService = new HmacSignatureService(keys: [], appEnv: 'testing');
        $info = $otherService->verify('data', $signature);

        self::assertFalse($info->valid);
        self::assertSame('Unknown signer key', $info->reason);
    }

    #[Test]
    public function signWithDifferentFormats(): void
    {
        $service = new HmacSignatureService(keys: ['key_001' => 'secret'], appEnv: 'testing');

        $sig1 = $service->sign('data', 'key_001', SignatureFormat::XAdES);
        $sig2 = $service->sign('data', 'key_001', SignatureFormat::PAdES);

        $info1 = $service->verify('data', $sig1, SignatureFormat::XAdES);
        $info2 = $service->verify('data', $sig2, SignatureFormat::PAdES);

        self::assertTrue($info1->valid);
        self::assertTrue($info2->valid);
    }

    #[Test]
    public function differentDataProducesDifferentSignatures(): void
    {
        $service = new HmacSignatureService(keys: ['key_001' => 'secret'], appEnv: 'testing');

        $sig1 = $service->sign('data1', 'key_001');
        $sig2 = $service->sign('data2', 'key_001');

        self::assertNotSame($sig1, $sig2);
    }
}
