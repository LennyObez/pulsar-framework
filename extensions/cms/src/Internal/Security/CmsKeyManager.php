<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Security;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\MasterKey;

/**
 * CMS-specific key derivation manager.
 *
 * Derives purpose-specific subkeys from the application MasterKey for CMS operations.
 * Uses subkey IDs 10-12 to avoid collision with core IDs 1-5.
 *
 * Context strings are exactly 8 bytes per libsodium KDF requirements.
 *
 * @psalm-api Resolved from the DI container by services that need to derive
 *            HMAC keys; not instantiated by name.
 */
#[Internal(reason: 'CMS security internals; use via service binding')]
final readonly class CmsKeyManager
{
    public function __construct(
        private MasterKey $masterKey,
    ) {}

    /**
     * Derive the preview signing key (e.g., for preview session tokens).
     *
     * SubkeyID: 10, Context: 'cms_prev'
     */
    public function previewKey(): string
    {
        return $this->masterKey->deriveSubKey(10, 'cms_prev');
    }

    /**
     * Derive the media signing key (e.g., for signed media URLs).
     *
     * SubkeyID: 11, Context: 'cms_mdia'
     */
    public function mediaSigningKey(): string
    {
        return $this->masterKey->deriveSubKey(11, 'cms_mdia');
    }

    /**
     * Derive the export encryption key (e.g., for content export archives).
     *
     * SubkeyID: 12, Context: 'cms_xprt'
     */
    public function exportKey(): string
    {
        return $this->masterKey->deriveSubKey(12, 'cms_xprt');
    }

    /**
     * Derive the download token signing key (e.g., for digital download HMAC tokens).
     *
     * SubkeyID: 13, Context: 'cms_dwnl'
     */
    public function downloadKey(): string
    {
        return $this->masterKey->deriveSubKey(13, 'cms_dwnl');
    }

    /**
     * Derive the export evidence hash key (e.g., for order export integrity).
     *
     * SubkeyID: 14, Context: 'cms_evid'
     */
    public function evidenceKey(): string
    {
        return $this->masterKey->deriveSubKey(14, 'cms_evid');
    }

    /**
     * Derive the API-key HMAC pepper.
     *
     * Used by CmsApiKeyMiddleware to compute the storage hash of incoming
     * API keys. Domain-separated from every other CMS subkey via a unique
     * SubkeyID + 8-byte context, so leaking the API key digest cannot
     * cross-contaminate preview tokens, media URLs, etc.
     *
     * SubkeyID: 15, Context: 'cms_apik'
     */
    public function apiKeyHashKey(): string
    {
        return $this->masterKey->deriveSubKey(15, 'cms_apik');
    }
}
