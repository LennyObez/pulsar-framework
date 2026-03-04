<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Override;
use Pulsar\Api\Internal;

use function sprintf;

/**
 * Enforces a minimum account age before allowing submissions.
 *
 * Prevents newly created throwaway accounts from immediately
 * posting spam. Anonymous users are always gated.
 */
#[Internal(reason: 'Use AccountAgeGateInterface')]
final readonly class AccountAgeGate implements AccountAgeGateInterface
{
    public function __construct(
        private int $minAgeSeconds = 300,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'account_age';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        // Anonymous users: always pass (other checks handle anonymous abuse)
        if ($context->isAnonymous()) {
            return AntiSpamCheckResult::pass($this->name());
        }

        // No age information available; fail safe
        if ($context->accountAgeSeconds === null) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                20,
                'Account age information unavailable',
            );
        }

        if ($context->accountAgeSeconds < $this->minAgeSeconds) {
            $remainingSeconds = $this->minAgeSeconds - $context->accountAgeSeconds;

            return AntiSpamCheckResult::fail(
                $this->name(),
                20,
                sprintf(
                    'Account too new: %d seconds remaining before posting is allowed',
                    $remainingSeconds,
                ),
            );
        }

        return AntiSpamCheckResult::pass($this->name());
    }
}
