<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_merge;
use function dirname;
use function file_get_contents;
use function glob;
use function in_array;
use function json_decode;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Guards the completeness AND the trust classification of config/extensions.php
 * against the bundled extensions on disk.
 *
 * Two things drift here. Completeness: an extension absent from the trusted list
 * is capped at Community, and a service-registering extension would fail to boot
 * — so every bundled manifest must have an entry. Classification: a framework
 * that ships application/product extensions (forum, cms, payments, ...) must not
 * grant them the same full trust as its own infrastructure. Products run at
 * VERIFIED (least privilege: register/decorate their own services, but no
 * ContainerWrite override, no crypto-key access, no process exec); only
 * infrastructure and security/compliance extensions run at CORE.
 *
 * This test fails the moment a bundled manifest is added/renamed without an
 * entry, or is set to the wrong tier for its classification. A NEW bundled
 * extension defaults to "must be core" unless it is listed as a product below,
 * which forces a conscious least-privilege decision rather than a silent core
 * grant.
 */
final class ExtensionSandboxDriftTest extends TestCase
{
    /**
     * Bundled application/product extensions that run least-privilege at the
     * VERIFIED tier. Everything else bundled is infrastructure/security and runs
     * at CORE. Keep this the single source of the split.
     *
     * @var list<string>
     */
    private const array PRODUCT_EXTENSIONS = [
        'pulsar/ai-governance',
        'pulsar/analytics',
        'pulsar/booking',
        'pulsar/cms',
        'pulsar/devices',
        'pulsar/feedback',
        'pulsar/forum',
        'pulsar/health-status',
        'pulsar/messaging',
        'pulsar/payments',
        'pulsar/releases',
        'pulsar/subscriptions',
        'pulsar/tickets',
    ];

    #[Test]
    public function everyBundledExtensionIsTrustedAtItsClassifiedTier(): void
    {
        $root = dirname(__DIR__, 4);

        $trusted = $this->trustedExtensions($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'extensions.php');

        // Bundled extensions live either flat (extensions/<name>/pulsar.json) or
        // one group level deep (extensions/compliance/<name>/pulsar.json). Glob
        // BOTH depths: a single-level glob was blind to the compliance group and
        // let 7 extensions fall to the community cap (kernel boot abort) without
        // failing this guard. Test fixtures live under a further tests/ dir, so
        // neither pattern picks them up.
        $extensions = $root . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR;
        $manifests = array_merge(
            glob($extensions . '*' . DIRECTORY_SEPARATOR . 'pulsar.json') ?: [],
            glob($extensions . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'pulsar.json') ?: [],
        );
        self::assertNotEmpty($manifests, 'expected bundled extension manifests to exist');

        foreach ($manifests as $manifestPath) {
            $name = $this->manifestName($manifestPath);

            self::assertArrayHasKey(
                $name,
                $trusted,
                sprintf(
                    'Bundled extension "%s" is missing from config/extensions.php trusted_extensions. '
                    . 'Add it, or the sandbox caps it at community and it fails to boot.',
                    $name,
                ),
            );

            $expectedTier = in_array($name, self::PRODUCT_EXTENSIONS, true) ? 'verified' : 'core';

            self::assertSame(
                $expectedTier,
                $trusted[$name]['tier'] ?? null,
                sprintf(
                    'Bundled extension "%s" must be trusted at "%s" tier (%s). A product runs least-privilege '
                    . 'at verified; infrastructure/security runs at core.',
                    $name,
                    $expectedTier,
                    $expectedTier === 'verified' ? 'product' : 'infrastructure/security',
                ),
            );
        }
    }

    /**
     * @return array<string, array{tier?: string}>
     */
    private function trustedExtensions(string $configFile): array
    {
        $data = require $configFile;
        self::assertIsArray($data);
        self::assertArrayHasKey('trusted_extensions', $data);
        self::assertIsArray($data['trusted_extensions']);

        /** @var array<string, array{tier?: string}> */
        return $data['trusted_extensions'];
    }

    private function manifestName(string $manifestPath): string
    {
        $raw = file_get_contents($manifestPath);
        self::assertIsString($raw, sprintf('unable to read %s', $manifestPath));

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, sprintf('invalid JSON in %s', $manifestPath));
        self::assertArrayHasKey('name', $decoded, sprintf('manifest %s has no name', $manifestPath));

        $name = $decoded['name'];
        self::assertIsString($name);

        return $name;
    }
}
