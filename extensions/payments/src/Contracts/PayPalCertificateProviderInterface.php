<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use Pulsar\Api\Internal;

/**
 * Resolves the PayPal webhook signing certificate's public key.
 *
 * Internal seam so {@see \Pulsar\Extension\Payments\Internal\Webhook\PayPalWebhookHandler}
 * can verify RSA signatures without performing (or being tested against) a live
 * certificate download.
 */
#[Internal]
interface PayPalCertificateProviderInterface
{
    /**
     * Return a PEM-encoded public key for the PayPal signing certificate at
     * $certUrl, or null when the URL is not an allow-listed PayPal host, the
     * fetch fails, or the certificate/public key cannot be parsed.
     *
     * A null return MUST be treated by the caller as "reject the webhook".
     */
    public function publicKeyPemFor(string $certUrl): ?string;
}
