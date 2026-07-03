<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Forms;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamDetectorInterface;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamResult;
use Pulsar\Security\AntiSpam\CaptchaVerifierInterface;

use function is_string;

/**
 * Verifies the self-hosted managed-challenge proof-of-work token a form carries.
 *
 * Replaces the former hand-rolled prefix proof-of-work: the token is now minted,
 * signed, and verified by the framework's managed-challenge engine, so it cannot
 * be forged, reused after its TTL, or replayed (single-use). This detector only
 * bridges the form-submission pipeline to the framework's #[Api]
 * {@see CaptchaVerifierInterface}; all cryptography lives in the core engine.
 *
 * @psalm-api Aggregated by SpamScorer through the SpamDetectorInterface contract;
 *            resolved from the DI container, not instantiated by name.
 */
#[Internal(reason: 'Spam detector; use SpamDetectorInterface')]
final readonly class ManagedChallengeDetector implements SpamDetectorInterface
{
    /** Default field the managed-challenge widget writes its solved token into. */
    public const string DEFAULT_FIELD = 'pulsar-challenge-response';

    public function __construct(
        private CaptchaVerifierInterface $verifier,
        private string $fieldName = self::DEFAULT_FIELD,
    ) {}

    #[Override]
    public function detect(array $data, array $meta): SpamResult
    {
        // The submission controller lifts the token into meta; fall back to the
        // raw field for direct callers that pass it in $data.
        /** @var mixed $raw */
        $raw = $meta['captcha_token'] ?? $data[$this->fieldName] ?? null;
        $token = is_string($raw) ? $raw : '';

        if ($token === '') {
            return new SpamResult(true, 9.0, 'Missing managed-challenge token');
        }

        // The managed challenge is self-hosted and deliberately not IP-bound
        // (matching the engine's design, which breaks behind proxies/NAT), so an
        // empty remote IP is passed.
        if (!$this->verifier->verify($token, '')) {
            return new SpamResult(true, 9.0, 'Managed-challenge verification failed');
        }

        return new SpamResult(false, 0.0, null);
    }
}
