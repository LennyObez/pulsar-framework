<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Override;
use Pulsar\Api\Internal;

use function hash;
use function str_starts_with;

/**
 * Verifies a client-side SHA-256 computational challenge.
 *
 * The client must find a nonce such that SHA-256(challenge + nonce)
 * starts with a configurable prefix (default: "0000"), making
 * automated mass-submissions computationally expensive.
 */
#[Internal(reason: 'Use ProofOfWorkVerifierInterface')]
final readonly class ProofOfWorkVerifier implements ProofOfWorkVerifierInterface
{
    public function __construct(
        private string $prefix = '0000',
    ) {}

    #[Override]
    public function name(): string
    {
        return 'proof_of_work';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        // Skip for authenticated users with established reputation
        if (!$context->isAnonymous() && $context->reputationTier !== 'new') {
            return AntiSpamCheckResult::pass($this->name());
        }

        $challenge = $context->powChallenge;
        $nonce = $context->powNonce;

        if ($challenge === null || $challenge === '' || $nonce === null || $nonce === '') {
            return AntiSpamCheckResult::fail(
                $this->name(),
                40,
                'Missing proof-of-work challenge or nonce',
            );
        }

        $hash = hash('sha256', $challenge . $nonce);

        if (!str_starts_with($hash, $this->prefix)) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                40,
                'Invalid proof-of-work nonce: hash does not meet difficulty requirement',
            );
        }

        return AntiSpamCheckResult::pass($this->name());
    }
}
