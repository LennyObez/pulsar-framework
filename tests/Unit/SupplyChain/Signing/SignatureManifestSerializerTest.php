<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Signing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Signing\SignatureManifest;
use Pulsar\SupplyChain\Signing\SignatureManifestSerializer;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(SignatureManifestSerializer::class)]
final class SignatureManifestSerializerTest extends TestCase
{
    private SignatureManifestSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new SignatureManifestSerializer();
    }

    #[Test]
    public function serializeProducesValidJson(): void
    {
        $manifests = [$this->createManifest('dist/app.phar', 'sig1', 'key1')];

        $json = $this->serializer->serialize($manifests);

        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
    }

    #[Test]
    public function serializeIncludesSchemaVersion(): void
    {
        $json = $this->serializer->serialize([]);

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(1, $data['schema_version']);
    }

    #[Test]
    public function serializeIncludesSignaturesArray(): void
    {
        $manifests = [
            $this->createManifest('a.phar', 'sigA', 'keyA'),
            $this->createManifest('b.zip', 'sigB', 'keyB'),
        ];

        $json = $this->serializer->serialize($manifests);

        /** @var array{signatures: list<array<string, string>>} $data */
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

        self::assertCount(2, $data['signatures']);
    }

    #[Test]
    public function serializeIncludesAllManifestFields(): void
    {
        $manifests = [$this->createManifest('dist/release.tar.gz', 'base64sig', 'base64key')];

        $json = $this->serializer->serialize($manifests);

        /** @var array{signatures: list<array<string, string>>} $data */
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

        $entry = $data['signatures'][0];

        self::assertSame('dist/release.tar.gz', $entry['artifact_path']);
        self::assertSame('base64sig', $entry['signature']);
        self::assertSame('base64key', $entry['public_key']);
        self::assertSame('ed25519', $entry['algorithm']);
        self::assertArrayHasKey('timestamp', $entry);
    }

    #[Test]
    public function deserializeReconstructsManifests(): void
    {
        $original = [
            $this->createManifest('app.phar', 'sigA', 'keyA'),
            $this->createManifest('lib.zip', 'sigB', 'keyB'),
        ];

        $json = $this->serializer->serialize($original);
        $restored = $this->serializer->deserialize($json);

        self::assertCount(2, $restored);
        self::assertSame('app.phar', $restored[0]->artifactPath);
        self::assertSame('sigA', $restored[0]->signature);
        self::assertSame('keyA', $restored[0]->publicKey);
        self::assertSame('ed25519', $restored[0]->algorithm);
        self::assertSame('lib.zip', $restored[1]->artifactPath);
    }

    #[Test]
    public function serializeAndDeserializeRoundtrip(): void
    {
        $timestamp = new DateTimeImmutable('2026-03-27T10:00:00+00:00');
        $original = [
            new SignatureManifest('x.phar', 'sig', 'key', $timestamp, 'ed25519'),
        ];

        $json = $this->serializer->serialize($original);
        $restored = $this->serializer->deserialize($json);

        self::assertCount(1, $restored);
        self::assertSame('x.phar', $restored[0]->artifactPath);
        self::assertSame('sig', $restored[0]->signature);
        self::assertSame('key', $restored[0]->publicKey);
        self::assertSame('ed25519', $restored[0]->algorithm);
        self::assertSame('2026-03-27', $restored[0]->timestamp->format('Y-m-d'));
    }

    #[Test]
    public function deserializeHandlesEmptySignaturesArray(): void
    {
        $json = '{"signatures": [], "schema_version": 1}';

        $result = $this->serializer->deserialize($json);

        self::assertSame([], $result);
    }

    #[Test]
    public function deserializeSkipsNonArrayEntries(): void
    {
        $json = '{"signatures": ["not-an-array", {"artifact_path": "a.phar", "signature": "s", "public_key": "k", "timestamp": "2026-03-27T00:00:00+00:00", "algorithm": "ed25519"}], "schema_version": 1}';

        $result = $this->serializer->deserialize($json);

        self::assertCount(1, $result);
        self::assertSame('a.phar', $result[0]->artifactPath);
    }

    #[Test]
    public function deserializeDefaultsMissingFieldsGracefully(): void
    {
        $json = '{"signatures": [{}], "schema_version": 1}';

        $result = $this->serializer->deserialize($json);

        self::assertCount(1, $result);
        self::assertSame('', $result[0]->artifactPath);
        self::assertSame('', $result[0]->signature);
        self::assertSame('', $result[0]->publicKey);
        self::assertSame('ed25519', $result[0]->algorithm);
    }

    private function createManifest(string $path, string $sig, string $key): SignatureManifest
    {
        return new SignatureManifest(
            artifactPath: $path,
            signature: $sig,
            publicKey: $key,
            timestamp: new DateTimeImmutable('2026-03-27T10:00:00+00:00'),
        );
    }
}
