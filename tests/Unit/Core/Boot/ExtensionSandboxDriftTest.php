<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\ExtensionKind;
use Pulsar\Extensibility\ExtensionManifest;

use function array_merge;
use function dirname;
use function glob;
use function in_array;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Guards the trust classification AND the enable posture of the bundled
 * extensions against silent drift, with each bundled manifest's `kind` as the
 * single runtime source of truth.
 *
 * Three things drift here:
 *  - Completeness: an extension absent from config/extensions.php is capped at
 *    Community and a service-registering extension fails to boot — so every
 *    bundled manifest must have an entry.
 *  - Classification (kind): a framework that ships application/products (forum,
 *    cms, payments, ...) must not load them by default. Products declare
 *    `"kind": "product"`, which turns them OFF unless opted in via
 *    extensions.enabled_products. Infrastructure/security extensions load by
 *    default. If a product manifest forgets its kind it would silently ship
 *    enabled — so the audited product backstop below forces each product's
 *    manifest to declare kind=product.
 *  - Trust tier: products run least-privilege at VERIFIED (register/decorate
 *    their own services, but no ContainerWrite override, no crypto-key access,
 *    no process exec); infrastructure/security runs at CORE.
 *
 * Every bundled manifest must agree with the backstop on BOTH axes: its `kind`
 * and its config tier. A new bundled extension defaults to infrastructure/core
 * unless it is listed as a product below, which forces a conscious
 * least-privilege decision rather than a silent core-and-enabled grant.
 */
final class ExtensionSandboxDriftTest extends TestCase
{
    /**
     * Audited application/product extensions: off by default (kind=product) and
     * least-privilege at the verified tier. Everything else bundled is
     * infrastructure/security — on by default, core tier. The single
     * human-audited source of the split; the manifests and config must match it.
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
    public function everyBundledExtensionIsClassifiedAndTrustedConsistently(): void
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
        $manifestPaths = array_merge(
            glob($extensions . '*' . DIRECTORY_SEPARATOR . 'pulsar.json') ?: [],
            glob($extensions . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'pulsar.json') ?: [],
        );
        self::assertNotEmpty($manifestPaths, 'expected bundled extension manifests to exist');

        foreach ($manifestPaths as $manifestPath) {
            $manifest = ExtensionManifest::fromFile($manifestPath);
            $name = $manifest->name;

            $isProduct = in_array($name, self::PRODUCT_EXTENSIONS, true);
            $expectedKind = $isProduct ? ExtensionKind::Product : ExtensionKind::Infrastructure;
            $expectedTier = $isProduct ? 'verified' : 'core';

            // Kind is the runtime switch: it decides whether the extension loads
            // by default. A product that forgets kind=product would silently ship
            // enabled — fail loudly instead.
            self::assertSame(
                $expectedKind,
                $manifest->kind,
                sprintf(
                    'Bundled extension "%s" must declare kind "%s" in its pulsar.json. A product (%s) '
                    . 'declares "kind": "product" so it is off by default; infrastructure omits kind.',
                    $name,
                    $expectedKind->value,
                    $isProduct ? 'off by default' : 'on by default',
                ),
            );

            self::assertArrayHasKey(
                $name,
                $trusted,
                sprintf(
                    'Bundled extension "%s" is missing from config/extensions.php trusted_extensions. '
                    . 'Add it, or the sandbox caps it at community and it fails to boot.',
                    $name,
                ),
            );

            self::assertSame(
                $expectedTier,
                $trusted[$name]['tier'] ?? null,
                sprintf(
                    'Bundled extension "%s" must be trusted at "%s" tier (%s). A product runs least-privilege '
                    . 'at verified; infrastructure/security runs at core.',
                    $name,
                    $expectedTier,
                    $isProduct ? 'product' : 'infrastructure/security',
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
}
