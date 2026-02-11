<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Forms;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamDetectorInterface;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamResult;

use function hash;
use function is_string;
use function str_starts_with;

/**
 * Verifies client-side proof of work to deter automated submissions.
 *
 * The client must find a nonce such that SHA-256(challenge + nonce)
 * starts with a configurable prefix (default: "0000").
 */
#[Internal(reason: 'Spam detector — use SpamDetectorInterface')]
final readonly class ProofOfWorkVerifier implements SpamDetectorInterface
{
    public function __construct(
        private string $prefix = '0000',
    ) {}

    #[Override]
    public function detect(array $data, array $meta): SpamResult
    {
        $rawChallenge = $meta['_pow_challenge'] ?? null;
        $challenge = is_string($rawChallenge) ? $rawChallenge : '';
        // Read nonce from $meta (extracted by controller), fallback to $data for direct usage.
        $rawNonce = $meta['_pow_nonce'] ?? $data['_pow_nonce'] ?? null;
        $nonce = is_string($rawNonce) ? $rawNonce : '';

        if ($challenge === '' || $nonce === '') {
            return new SpamResult(true, 9.0, 'Missing proof-of-work nonce or challenge');
        }

        $hash = hash('sha256', $challenge . $nonce);

        if (!str_starts_with($hash, $this->prefix)) {
            return new SpamResult(true, 9.0, 'Invalid proof-of-work nonce');
        }

        return new SpamResult(false, 0.0, null);
    }
}
