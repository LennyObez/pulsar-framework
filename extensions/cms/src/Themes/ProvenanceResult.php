<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;

/**
 * Result of theme provenance and integrity verification.
 */
#[Api(since: '1.0.0')]
final readonly class ProvenanceResult
{
    /**
     * @param bool $hashValid Whether the package hash matches the expected value
     * @param bool $signatureValid Whether the cryptographic signature is valid
     * @param bool $signaturePresent Whether a signature was present in the package
     * @param string|null $error Error message if verification failed
     */
    public function __construct(
        public bool $hashValid,
        public bool $signatureValid,
        public bool $signaturePresent,
        public ?string $error = null,
    ) {}

    /**
     * Create a fully verified result (hash and signature both valid).
     */
    public static function verified(): self
    {
        return new self(
            hashValid: true,
            signatureValid: true,
            signaturePresent: true,
        );
    }

    /**
     * Create a result for a valid hash but unsigned package.
     */
    public static function unsigned(): self
    {
        return new self(
            hashValid: true,
            signatureValid: false,
            signaturePresent: false,
        );
    }

    /**
     * Create a failed verification result.
     */
    public static function failed(string $error): self
    {
        return new self(
            hashValid: false,
            signatureValid: false,
            signaturePresent: false,
            error: $error,
        );
    }

    /**
     * Whether the result is acceptable for installation given the signing requirement.
     */
    public function isAcceptable(bool $requireSigned): bool
    {
        if (!$this->hashValid) {
            return false;
        }

        if ($requireSigned) {
            return $this->signaturePresent && $this->signatureValid;
        }

        return true;
    }
}
