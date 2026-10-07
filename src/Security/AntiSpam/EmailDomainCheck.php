<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Override;
use Pulsar\Api\Internal;

use function implode;
use function min;
use function preg_match;
use function rtrim;
use function str_contains;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Inspects the sender's e-mail DOMAIN, closing the gap the body-only checks
 * leave: a bot that runs no JS (so no managed-challenge token), respects the
 * time-trap and skips the honeypot reaches only the content scorers, which
 * never block. A domain check catches it on every path.
 *
 * Two independent signals, each hard-gating, scoring, or off:
 *  - Disposable: the domain (or a registrable parent) is a known throwaway.
 *  - Deliverability: the domain publishes no MX and no A/AAAA fallback, so it
 *    cannot receive mail. Fails OPEN when the resolver is unreachable.
 *
 * The check never throws: address FORMAT validation is the caller's Email rule,
 * so an absent or unparseable address is simply "OK" here.
 */
#[Internal(reason: 'Use AntiSpamCheckInterface')]
final readonly class EmailDomainCheck implements AntiSpamCheckInterface
{
    private const int DISPOSABLE_SCORE = 40;
    private const int MX_SCORE = 35;

    public function __construct(
        private DisposableEmailDomains $disposableDomains,
        private MxDeliverabilityResolverInterface $mxResolver,
        private EmailDomainCheckConfig $config,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'email_domain';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        $email = $context->email();

        if ($email === null) {
            return AntiSpamCheckResult::pass($this->name());
        }

        $domain = $this->domainOf($email);

        if ($domain === null) {
            // Unparseable / dot-less: format is the caller's Email rule, not ours.
            return AntiSpamCheckResult::pass($this->name());
        }

        $score = 0;
        $hard = false;
        $reasons = [];

        if (
            $this->config->disposableBlock !== EmailDomainSignalMode::Off
            && $this->disposableDomains->contains($domain)
        ) {
            $score += self::DISPOSABLE_SCORE;
            $reasons[] = 'Sender domain is a disposable/throwaway mailbox provider';

            if ($this->config->disposableBlock === EmailDomainSignalMode::Hard) {
                $hard = true;
            }
        }

        if ($this->config->mxCheckEnabled && $this->config->mxBlock !== EmailDomainSignalMode::Off) {
            // false = provably undeliverable; null = resolver unreachable → fail open.
            if ($this->mxResolver->isDeliverable($domain) === false) {
                $score += self::MX_SCORE;
                $reasons[] = 'Sender domain publishes no MX or address record and cannot receive mail';

                if ($this->config->mxBlock === EmailDomainSignalMode::Hard) {
                    $hard = true;
                }
            }
        }

        if ($reasons === []) {
            return AntiSpamCheckResult::pass($this->name());
        }

        $reason = implode('; ', $reasons);
        $score = min(100, $score);

        if ($hard) {
            return AntiSpamCheckResult::fail($this->name(), $score, $reason);
        }

        // Score-only: contribute to the aggregate score without failing the
        // check, leaving the block threshold to the controller.
        return new AntiSpamCheckResult(
            passed: true,
            checkName: $this->name(),
            score: $score,
            reason: $reason,
        );
    }

    /**
     * Extract the lowercased domain from an address, or null when there is no
     * usable domain (no '@', dot-less host, or whitespace).
     */
    private function domainOf(string $email): ?string
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return null;
        }

        $domain = rtrim(strtolower(trim(substr($email, $at + 1))), '.');

        if ($domain === '' || preg_match('/\s/', $domain) === 1) {
            return null;
        }

        // A routable mail domain has at least one dot; 'localhost' and bare
        // labels are not something MX/disposable checks can meaningfully judge.
        if (!str_contains($domain, '.')) {
            return null;
        }

        return $domain;
    }
}
