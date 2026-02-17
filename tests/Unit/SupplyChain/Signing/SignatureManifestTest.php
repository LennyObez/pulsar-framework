<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Signing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Signing\SignatureManifest;

#[CoversClass(SignatureManifest::class)]
final class SignatureManifestTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $timestamp = new DateTimeImmutable('2026-03-27T12:00:00+00:00');

        $manifest = new SignatureManifest(
            artifactPath: 'dist/pulsar.phar',
            signature: 'base64sig==',
            publicKey: 'base64key==',
            timestamp: $timestamp,
            algorithm: 'ed25519',
        );

        self::assertSame('dist/pulsar.phar', $manifest->artifactPath);
        self::assertSame('base64sig==', $manifest->signature);
        self::assertSame('base64key==', $manifest->publicKey);
        self::assertSame($timestamp, $manifest->timestamp);
        self::assertSame('ed25519', $manifest->algorithm);
    }

    #[Test]
    public function algorithmDefaultsToEd25519(): void
    {
        $manifest = new SignatureManifest(
            artifactPath: 'dist/release.zip',
            signature: 'sig',
            publicKey: 'key',
            timestamp: new DateTimeImmutable(),
        );

        self::assertSame('ed25519', $manifest->algorithm);
    }

    #[Test]
    public function algorithmCanBeOverridden(): void
    {
        $manifest = new SignatureManifest(
            artifactPath: 'dist/release.zip',
            signature: 'sig',
            publicKey: 'key',
            timestamp: new DateTimeImmutable(),
            algorithm: 'custom-algo',
        );

        self::assertSame('custom-algo', $manifest->algorithm);
    }

    #[Test]
    public function timestampPreservesTimezoneInfo(): void
    {
        $timestamp = new DateTimeImmutable('2026-03-27T15:30:00+05:30');

        $manifest = new SignatureManifest(
            artifactPath: 'file.phar',
            signature: 'sig',
            publicKey: 'key',
            timestamp: $timestamp,
        );

        self::assertSame('2026-03-27T15:30:00+05:30', $manifest->timestamp->format('c'));
    }
}
