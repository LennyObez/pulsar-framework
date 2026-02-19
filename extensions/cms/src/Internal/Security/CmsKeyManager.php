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
 */
#[Internal(reason: 'CMS security internals — use via service binding')]
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
}
