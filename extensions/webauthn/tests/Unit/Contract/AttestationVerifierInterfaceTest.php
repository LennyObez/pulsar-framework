<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Attestation\AttestationResult;
use Pulsar\Extension\WebAuthn\Attestation\AttestationTrustLevel;
use Pulsar\Extension\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\WebAuthn\Exception\WebAuthnException;

#[CoversClass(AttestationVerifierInterface::class)]
final class AttestationVerifierInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanVerifyNoneFormat(): void
    {
        $result = new AttestationResult(
            verified: true,
            format: 'none',
            trustLevel: AttestationTrustLevel::None,
        );

        $stub = $this->createStub(AttestationVerifierInterface::class);
        $stub->method('verify')->willReturn($result);

        $actual = $stub->verify('none', 'att-obj-bytes', 'client-data-json');

        self::assertTrue($actual->verified);
        self::assertSame('none', $actual->format);
        self::assertSame(AttestationTrustLevel::None, $actual->trustLevel);
        self::assertNull($actual->aaguid);
    }

    #[Test]
    public function stubCanVerifyPackedFormat(): void
    {
        $result = new AttestationResult(
            verified: true,
            format: 'packed',
            trustLevel: AttestationTrustLevel::Basic,
            aaguid: '00000000-0000-0000-0000-000000000001',
        );

        $stub = $this->createStub(AttestationVerifierInterface::class);
        $stub->method('verify')->willReturn($result);

        $actual = $stub->verify('packed', 'packed-obj', 'client-json');

        self::assertTrue($actual->verified);
        self::assertSame('packed', $actual->format);
        self::assertSame(AttestationTrustLevel::Basic, $actual->trustLevel);
        self::assertSame('00000000-0000-0000-0000-000000000001', $actual->aaguid);
    }

    #[Test]
    public function stubCanRejectDisallowedFormat(): void
    {
        $stub = $this->createStub(AttestationVerifierInterface::class);
        $stub->method('verify')->willThrowException(
            WebAuthnException::disallowedFormat('android-key'),
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('android-key');

        $stub->verify('android-key', 'obj', 'json');
    }

    #[Test]
    public function stubCanCheckFormatAllowed(): void
    {
        $stub = $this->createStub(AttestationVerifierInterface::class);
        $stub->method('isFormatAllowed')->willReturnMap([
            ['none', true],
            ['packed', true],
            ['android-key', false],
        ]);

        self::assertTrue($stub->isFormatAllowed('none'));
        self::assertTrue($stub->isFormatAllowed('packed'));
        self::assertFalse($stub->isFormatAllowed('android-key'));
    }

    #[Test]
    public function stubCanReturnAllowedFormats(): void
    {
        $stub = $this->createStub(AttestationVerifierInterface::class);
        $stub->method('allowedFormats')->willReturn(['none', 'packed']);

        $formats = $stub->allowedFormats();

        self::assertCount(2, $formats);
        self::assertContains('none', $formats);
        self::assertContains('packed', $formats);
    }
}
