<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\ExtensionKind;
use Pulsar\Extensibility\ExtensionLoader;

use function array_diff;
use function array_keys;
use function array_merge;
use function dirname;
use function glob;
use function implode;
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
 *
 * The two axes are audited separately, and they have to be: `kind` decides
 * whether an extension LOADS and the tier decides what it may DO, and inferring
 * one from the other would make a privilege grant a side effect of an
 * enable-state decision.
 *
 * There is deliberately no core-tier exception list any more. One existed, for
 * `pulsar/ai-governance`, and its stated ground — that the sandbox denies an
 * extension the ids it registers for itself — was false against this tree:
 * `ScopeRegistrations` and `ScopedContainerProxy::isOwnCode()` both rescue an
 * extension's own ids in `assertCanResolve()`. What actually failed at verified
 * was a HOST service, `ExtensionConfigRegistry`, missing from the restriction
 * map; it also took `pulsar/booking` and `pulsar/payments` down inside `boot()`.
 * Classifying it fixed all three, so the exception had nothing left to justify.
 * A future exception must be established the way that one was disproved — by
 * booting the extension at the tier in question and reading the error — and
 * `ShippedDeploymentGateTest` is the shape that does it.
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
            $manifest = new ExtensionLoader()->readManifest($manifestPath);
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
                    . 'at verified; infrastructure/security runs at core. There is no core-tier exception for '
                    . 'a product: if one appears to be needed, boot the extension at verified, read the error, '
                    . 'and fix what it names.',
                    $name,
                    $expectedTier,
                    $isProduct ? 'product' : 'infrastructure/security',
                ),
            );
        }
    }

    #[Test]
    public function everyGrantInTheShippedConfigNamesABundledExtension(): void
    {
        // The other direction, and it was blind for the whole RC phase.
        // The test above asks "does every manifest have an entry", which catches
        // an extension that would fall to the community cap and fail to boot --
        // a loud failure. It never asked "does every entry have a manifest", and
        // that failure is silent: a row granting core tier to a name nothing
        // ships resolves against no extension, changes no behaviour, and sits in
        // the file ADR-0023 calls the record an auditor reads to learn what code
        // was permitted.
        //
        // One was there. 'pulsar/social-sso' => core, for an extension
        // extensions/auth absorbed and declares in its own "replaces" list. It
        // survived review by looking exactly like the thirty-four rows around it.
        //
        // Scope: this reads the FRAMEWORK's shipped config, not an
        // application's. An operator's own third-party grants are theirs to make.
        $root = dirname(__DIR__, 4);

        $trusted = $this->trustedExtensions($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'extensions.php');
        $bundled = $this->bundledExtensionNames($root);

        self::assertNotEmpty($bundled, 'expected bundled extension manifests to exist');

        $orphans = array_diff(array_keys($trusted), $bundled);

        self::assertSame(
            [],
            $orphans,
            sprintf(
                'config/extensions.php grants a trust tier to %s, which no bundled pulsar.json '
                . 'declares. A grant that names nothing is a record of a permission that was '
                . 'never given to anything -- remove the row, or add the extension it was '
                . 'written for. See ADR-0070.',
                implode(', ', $orphans),
            ),
        );
    }

    /**
     * Bundled extension names, from the manifests themselves.
     *
     * @return list<string>
     */
    private function bundledExtensionNames(string $root): array
    {
        $extensions = $root . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR;

        $names = [];

        foreach (array_merge(
            glob($extensions . '*' . DIRECTORY_SEPARATOR . 'pulsar.json') ?: [],
            glob($extensions . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'pulsar.json') ?: [],
        ) as $manifestPath) {
            $names[] = new ExtensionLoader()->readManifest($manifestPath)->name;
        }

        return $names;
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
