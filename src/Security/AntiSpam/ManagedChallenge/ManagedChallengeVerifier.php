<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\ManagedChallenge;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Security\AntiSpam\AntiSpamCheckResult;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\CaptchaVerifierInterface;

/**
 * Self-hosted CAPTCHA verifier backed by the managed-challenge proof-of-work.
 *
 * A drop-in alternative to {@see \Pulsar\Security\AntiSpam\TurnstileVerifier}
 * and {@see \Pulsar\Security\AntiSpam\HCaptchaVerifier} that performs no
 * external request: it validates the signed, single-use proof-of-work token
 * the {@see ManagedChallengeService} issued and the browser solved. Selected
 * via `captcha_provider: 'managed'`.
 */
#[Internal(reason: 'Use CaptchaVerifierInterface')]
final readonly class ManagedChallengeVerifier implements CaptchaVerifierInterface
{
    public function __construct(
        private ManagedChallengeService $service,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'captcha';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        if ($context->captchaToken === null || $context->captchaToken === '') {
            return AntiSpamCheckResult::fail(
                $this->name(),
                30,
                'Managed challenge token is missing',
            );
        }

        if (!$this->verify($context->captchaToken, $context->ipHash)) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                30,
                'Managed challenge verification failed',
            );
        }

        return AntiSpamCheckResult::pass($this->name());
    }

    /**
     * Verify a solved managed-challenge token.
     *
     * The token is self-describing and self-hosted, so $remoteIp is not used:
     * the managed challenge deliberately does not bind to client IP (which
     * breaks behind proxies and mobile networks), matching Turnstile's model.
     */
    #[Override]
    public function verify(string $token, string $remoteIp): bool
    {
        return $this->service->verify($token);
    }
}
