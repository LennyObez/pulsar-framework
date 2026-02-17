<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Eidas\Config\EidasConfig;

final class EidasConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = EidasConfig::fromArray([]);

        self::assertSame('hmac', $config->signatureService);
        self::assertSame('hmac', $config->sealService);
        self::assertSame('local', $config->timestampService);
        self::assertSame('memory', $config->deliveryService);
        self::assertSame('jades', $config->defaultSignatureFormat);
        self::assertSame('Pulsar Local TSA', $config->tsaName);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = EidasConfig::fromArray([
            'signature_service' => 'rsa',
            'seal_service' => 'pkcs11',
            'timestamp_service' => 'rfc3161',
            'delivery_service' => 'smtp',
            'default_signature_format' => 'pades',
            'tsa_name' => 'Production TSA',
        ]);

        self::assertSame('rsa', $config->signatureService);
        self::assertSame('pkcs11', $config->sealService);
        self::assertSame('rfc3161', $config->timestampService);
        self::assertSame('smtp', $config->deliveryService);
        self::assertSame('pades', $config->defaultSignatureFormat);
        self::assertSame('Production TSA', $config->tsaName);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $config = EidasConfig::fromArray([
            'signature_service' => 42,
            'tsa_name' => false,
        ]);

        self::assertSame('hmac', $config->signatureService);
        self::assertSame('Pulsar Local TSA', $config->tsaName);
    }
}
