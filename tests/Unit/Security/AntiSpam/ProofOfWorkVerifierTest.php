<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\ProofOfWorkVerifier;

use function hash;
use function str_starts_with;

#[CoversClass(ProofOfWorkVerifier::class)]
final class ProofOfWorkVerifierTest extends TestCase
{
    #[Test]
    public function nameReturnsProofOfWork(): void
    {
        $verifier = new ProofOfWorkVerifier();
        self::assertSame('proof_of_work', $verifier->name());
    }

    #[Test]
    public function passesWithValidNonce(): void
    {
        $verifier = new ProofOfWorkVerifier('0000');
        $challenge = 'test-challenge';
        $nonce = $this->solveChallenge($challenge, '0000');

        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            powChallenge: $challenge,
            powNonce: $nonce,
        );

        $result = $verifier->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function failsWithInvalidNonce(): void
    {
        $verifier = new ProofOfWorkVerifier('0000');
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            powChallenge: 'challenge',
            powNonce: 'wrong-nonce',
        );

        $result = $verifier->check($context);

        self::assertFalse($result->passed);
        self::assertStringContainsString('does not meet difficulty', $result->reason ?? '');
    }

    #[Test]
    public function failsWithMissingChallenge(): void
    {
        $verifier = new ProofOfWorkVerifier();
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            powNonce: 'some-nonce',
        );

        $result = $verifier->check($context);

        self::assertFalse($result->passed);
        self::assertStringContainsString('Missing', $result->reason ?? '');
    }

    #[Test]
    public function failsWithMissingNonce(): void
    {
        $verifier = new ProofOfWorkVerifier();
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            powChallenge: 'challenge',
        );

        $result = $verifier->check($context);

        self::assertFalse($result->passed);
    }

    #[Test]
    public function failsWithEmptyChallenge(): void
    {
        $verifier = new ProofOfWorkVerifier();
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            powChallenge: '',
            powNonce: 'nonce',
        );

        $result = $verifier->check($context);

        self::assertFalse($result->passed);
    }

    #[Test]
    public function skipsForEstablishedAuthenticatedUser(): void
    {
        $verifier = new ProofOfWorkVerifier();
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            userId: 'user-1',
            reputationTier: 'established',
        );

        $result = $verifier->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function skipsForModerator(): void
    {
        $verifier = new ProofOfWorkVerifier();
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            userId: 'mod-1',
            reputationTier: 'moderator',
        );

        $result = $verifier->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function doesNotSkipForNewAuthenticatedUser(): void
    {
        $verifier = new ProofOfWorkVerifier();
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            userId: 'new-user',
            reputationTier: 'new',
        );

        $result = $verifier->check($context);

        // Should fail because no PoW was provided
        self::assertFalse($result->passed);
    }

    #[Test]
    public function customPrefixIsRespected(): void
    {
        $verifier = new ProofOfWorkVerifier('00');
        $challenge = 'easy-challenge';
        $nonce = $this->solveChallenge($challenge, '00');

        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            powChallenge: $challenge,
            powNonce: $nonce,
        );

        $result = $verifier->check($context);

        self::assertTrue($result->passed);
    }

    /**
     * Brute-force solve a PoW challenge for testing.
     */
    private function solveChallenge(string $challenge, string $prefix): string
    {
        for ($i = 0; $i < 10_000_000; $i++) {
            $nonce = (string) $i;
            $hash = hash('sha256', $challenge . $nonce);

            if (str_starts_with($hash, $prefix)) {
                return $nonce;
            }
        }

        self::fail('Could not solve PoW challenge within iteration limit');
    }
}
