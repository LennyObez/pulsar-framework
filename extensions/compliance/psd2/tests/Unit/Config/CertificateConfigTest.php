<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Config\CertificateConfig;

#[CoversClass(CertificateConfig::class)]
final class CertificateConfigTest extends TestCase
{
    #[Test]
    public function defaultsRequireQualifiedAndRevocationCheck(): void
    {
        $config = new CertificateConfig();

        self::assertTrue($config->requireQualified);
        self::assertTrue($config->checkRevocation);
        self::assertSame([], $config->trustedIssuers);
        self::assertSame('default', $config->validator);
        // Revocation fails closed by default: an inconclusive check rejects.
        self::assertFalse($config->revocationSoftFail);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = CertificateConfig::fromArray([
            'require_qualified' => false,
            'check_revocation' => false,
            'trusted_issuers' => ['CN=DigiCert', 'CN=Entrust'],
            'validator' => 'custom',
            'revocation_soft_fail' => true,
        ]);

        self::assertFalse($config->requireQualified);
        self::assertFalse($config->checkRevocation);
        self::assertSame(['CN=DigiCert', 'CN=Entrust'], $config->trustedIssuers);
        self::assertSame('custom', $config->validator);
        self::assertTrue($config->revocationSoftFail);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = CertificateConfig::fromArray([]);

        self::assertTrue($config->requireQualified);
        self::assertTrue($config->checkRevocation);
        self::assertSame([], $config->trustedIssuers);
        self::assertSame('default', $config->validator);
    }

    #[Test]
    public function fromArrayIgnoresNonBooleanForBooleanFields(): void
    {
        $config = CertificateConfig::fromArray([
            'require_qualified' => 'yes',
            'check_revocation' => 1,
        ]);

        self::assertTrue($config->requireQualified);
        self::assertTrue($config->checkRevocation);
    }

    #[Test]
    public function fromArrayIgnoresNonArrayForTrustedIssuers(): void
    {
        $config = CertificateConfig::fromArray([
            'trusted_issuers' => 'not-an-array',
        ]);

        self::assertSame([], $config->trustedIssuers);
    }

    #[Test]
    public function fromArrayIgnoresNonStringForValidator(): void
    {
        $config = CertificateConfig::fromArray([
            'validator' => 42,
        ]);

        self::assertSame('default', $config->validator);
    }
}
