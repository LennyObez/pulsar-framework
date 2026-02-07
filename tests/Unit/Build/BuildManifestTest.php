<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\ArtifactEntry;
use Pulsar\Build\BuildManifest;

#[CoversClass(BuildManifest::class)]
#[CoversClass(ArtifactEntry::class)]
final class BuildManifestTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesManifestWithAllFields(): void
    {
        $manifest = BuildManifest::fromArray([
            'version' => 2,
            'algorithm' => 'sha256',
            'artifacts' => [
                'extensions' => [
                    'path' => 'extensions.manifest.php',
                    'hash' => 'aaa111',
                    'size' => 1024,
                ],
            ],
            'contentHashes' => [
                'ext-a' => 'hash_a',
            ],
            'signature' => 'sig_abc',
        ]);

        self::assertSame(2, $manifest->version);
        self::assertSame('sha256', $manifest->algorithm);
        self::assertCount(1, $manifest->artifacts);
        self::assertArrayHasKey('extensions', $manifest->artifacts);
        self::assertSame('aaa111', $manifest->artifacts['extensions']->hash);
        self::assertSame(['ext-a' => 'hash_a'], $manifest->contentHashes);
        self::assertSame('sig_abc', $manifest->signature);
    }

    #[Test]
    public function fromArrayDefaultsMissingFields(): void
    {
        $manifest = BuildManifest::fromArray([]);

        self::assertSame(1, $manifest->version);
        self::assertSame('sha256', $manifest->algorithm);
        self::assertSame([], $manifest->artifacts);
        self::assertSame([], $manifest->contentHashes);
        self::assertNull($manifest->signature);
    }

    #[Test]
    public function toArrayExportsAllFields(): void
    {
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'routes' => new ArtifactEntry('routes.compiled.php', 'bbb222', 512),
            ],
            contentHashes: ['src' => 'hash_src'],
            signature: 'hmac_sig',
        );

        $array = $manifest->toArray();

        self::assertSame(1, $array['version']);
        self::assertSame('sha256', $array['algorithm']);
        self::assertSame('hmac_sig', $array['signature']);
        self::assertSame(['path' => 'routes.compiled.php', 'hash' => 'bbb222', 'size' => 512], $array['artifacts']['routes']);
        self::assertSame(['src' => 'hash_src'], $array['contentHashes']);
    }

    #[Test]
    public function fromArrayToArrayRoundTrip(): void
    {
        $data = [
            'version' => 1,
            'algorithm' => 'sha256',
            'artifacts' => [
                'container' => ['path' => 'container.compiled.php', 'hash' => 'ccc333', 'size' => 2048],
                'extensions' => ['path' => 'extensions.manifest.php', 'hash' => 'ddd444', 'size' => 1024],
            ],
            'contentHashes' => ['alpha' => 'h1', 'beta' => 'h2'],
            'signature' => null,
        ];

        $manifest = BuildManifest::fromArray($data);
        $exported = $manifest->toArray();

        self::assertSame($data, $exported);
    }

    #[Test]
    public function fromJsonToJsonRoundTrip(): void
    {
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'extensions' => new ArtifactEntry('extensions.manifest.php', 'eee555', 4096),
            ],
            contentHashes: ['core' => 'hash_core'],
            signature: null,
        );

        $json = $manifest->toJson();
        $restored = BuildManifest::fromJson($json);

        self::assertSame($manifest->version, $restored->version);
        self::assertSame($manifest->algorithm, $restored->algorithm);
        self::assertSame($manifest->signature, $restored->signature);
        self::assertSame($manifest->contentHashes, $restored->contentHashes);
        self::assertSame(
            $manifest->artifacts['extensions']->hash,
            $restored->artifacts['extensions']->hash,
        );
    }

    #[Test]
    public function toJsonProducesDeterministicSortedOutput(): void
    {
        // Create with unsorted keys
        $manifest1 = BuildManifest::fromArray([
            'version' => 1,
            'algorithm' => 'sha256',
            'artifacts' => [
                'zebra' => ['path' => 'z.php', 'hash' => 'z', 'size' => 1],
                'alpha' => ['path' => 'a.php', 'hash' => 'a', 'size' => 2],
            ],
            'contentHashes' => ['z_ext' => 'hz', 'a_ext' => 'ha'],
            'signature' => null,
        ]);

        $manifest2 = BuildManifest::fromArray([
            'version' => 1,
            'algorithm' => 'sha256',
            'artifacts' => [
                'alpha' => ['path' => 'a.php', 'hash' => 'a', 'size' => 2],
                'zebra' => ['path' => 'z.php', 'hash' => 'z', 'size' => 1],
            ],
            'contentHashes' => ['a_ext' => 'ha', 'z_ext' => 'hz'],
            'signature' => null,
        ]);

        // Both should produce identical JSON regardless of input order
        self::assertSame($manifest1->toJson(), $manifest2->toJson());

        // Verify alphabetical key ordering in JSON
        $json = $manifest1->toJson();
        $alphaPos = strpos($json, '"alpha"');
        $zebraPos = strpos($json, '"zebra"');
        self::assertIsInt($alphaPos);
        self::assertIsInt($zebraPos);
        self::assertLessThan($zebraPos, $alphaPos);
    }
}
