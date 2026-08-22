<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Signing;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Metadata for a signed release artifact.
 *
 * Contains the artifact path, its Ed25519 detached signature,
 * the public key used for verification, and a timestamp.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SignatureManifest
{
    /**
     * @param string $artifactPath Relative path to the signed artifact
     * @param string $signature Base64-encoded Ed25519 detached signature
     * @param string $publicKey Base64-encoded Ed25519 public key
     * @param DateTimeImmutable $timestamp When the artifact was signed
     * @param string $algorithm Signing algorithm identifier
     */
    public function __construct(
        public string $artifactPath,
        public string $signature,
        public string $publicKey,
        public DateTimeImmutable $timestamp,
        public string $algorithm = 'ed25519',
    ) {}
}
