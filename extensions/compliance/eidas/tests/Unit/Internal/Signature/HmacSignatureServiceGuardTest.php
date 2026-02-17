<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit\Internal\Signature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Eidas\Exception\EidasException;
use Pulsar\Extension\Eidas\Internal\Signature\HmacSignatureService;

final class HmacSignatureServiceGuardTest extends TestCase
{
    #[Test]
    public function signThrowsInProductionEnvironment(): void
    {
        $service = new HmacSignatureService(
            keys: ['key_001' => 'secret'],
            appEnv: 'production',
        );

        $this->expectException(EidasException::class);
        $this->expectExceptionMessage('not permitted outside testing');

        $service->sign('data', 'key_001');
    }

    #[Test]
    public function verifyThrowsInProductionEnvironment(): void
    {
        // Create the signature in testing mode
        $testingService = new HmacSignatureService(
            keys: ['key_001' => 'secret'],
            appEnv: 'testing',
        );
        $signature = $testingService->sign('data', 'key_001');

        // Try to verify in production mode
        $prodService = new HmacSignatureService(
            keys: ['key_001' => 'secret'],
            appEnv: 'production',
        );

        $this->expectException(EidasException::class);
        $this->expectExceptionMessage('not permitted outside testing');

        $prodService->verify('data', $signature);
    }

    #[Test]
    public function signSucceedsInTestingEnvironment(): void
    {
        $service = new HmacSignatureService(
            keys: ['key_001' => 'secret'],
            appEnv: 'testing',
        );

        $signature = $service->sign('data', 'key_001');
        self::assertNotEmpty($signature);
    }

    #[Test]
    public function signSucceedsInTestEnvironment(): void
    {
        $service = new HmacSignatureService(
            keys: ['key_001' => 'secret'],
            appEnv: 'test',
        );

        $signature = $service->sign('data', 'key_001');
        self::assertNotEmpty($signature);
    }

    #[Test]
    public function signThrowsInStagingEnvironment(): void
    {
        $service = new HmacSignatureService(
            keys: ['key_001' => 'secret'],
            appEnv: 'staging',
        );

        $this->expectException(EidasException::class);
        $service->sign('data', 'key_001');
    }

    #[Test]
    public function roundTripWorksInTestingEnvironment(): void
    {
        $service = new HmacSignatureService(
            keys: ['key_001' => 'secret'],
            appEnv: 'testing',
        );

        $data = 'Important eIDAS document';
        $signature = $service->sign($data, 'key_001');
        $info = $service->verify($data, $signature);

        self::assertTrue($info->valid);
        self::assertSame('key_001', $info->signerName);
    }

    #[Test]
    public function nonProductionSignatureExceptionMessageIsDescriptive(): void
    {
        $exception = EidasException::nonProductionSignature();

        self::assertStringContainsString('HMAC-based signatures', $exception->getMessage());
        self::assertStringContainsString('asymmetric cryptography', $exception->getMessage());
        self::assertStringContainsString('OpenSSL', $exception->getMessage());
    }
}
