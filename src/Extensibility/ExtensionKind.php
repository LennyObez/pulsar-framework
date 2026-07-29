<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Api;

use function is_string;

/**
 * Classifies a bundled extension as framework infrastructure or an
 * application/product.
 *
 * This is an axis distinct from {@see TrustTier} (how much the host trusts the
 * code): kind describes what the extension *is*, and it drives the default
 * enable posture. Infrastructure and security/compliance extensions load by
 * default; application/product extensions (a forum, a CMS, a payments stack)
 * are present on disk but OFF by default — a regulated app should not inherit a
 * forum or a beta-signup PII collector it never asked for. Opt a product in via
 * `extensions.enabled_products` in config/app.php.
 *
 * Declared per extension in its pulsar.json (`"kind": "product"`); absent means
 * {@see self::Infrastructure}, so an extension is only ever off-by-default when
 * it deliberately says so.
 * @api
 */
#[Api(since: '1.0.0')]
enum ExtensionKind: string
{
    case Infrastructure = 'infrastructure';
    case Product = 'product';

    /**
     * Resolve a kind from a manifest value, defaulting to Infrastructure for an
     * absent or unrecognized declaration. An unknown string is treated as
     * infrastructure rather than rejected so a manifest typo never silently
     * disables an extension — the drift guard catches misclassification instead.
     */
    public static function fromManifest(mixed $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            is_string($value) => self::tryFrom($value) ?? self::Infrastructure,
            default => self::Infrastructure,
        };
    }

    /**
     * Whether an extension of this kind loads by default (no explicit opt-in).
     */
    public function loadsByDefault(): bool
    {
        return $this === self::Infrastructure;
    }
}
