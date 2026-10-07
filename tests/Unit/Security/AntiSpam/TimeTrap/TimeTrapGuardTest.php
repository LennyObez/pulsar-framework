<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\TimeTrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapDecision;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapFailurePolicy;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapGuard;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapService;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapToken;

#[CoversClass(TimeTrapGuard::class)]
final class TimeTrapGuardTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef'; // 32 bytes

    private const string FIELD = 'pulsar-form-ts';

    private const int ISSUED_AT = 1_700_000_000;

    private function service(): TimeTrapService
    {
        return new TimeTrapService(self::KEY);
    }

    private function guard(TimeTrapFailurePolicy $policy): TimeTrapGuard
    {
        return new TimeTrapGuard($this->service(), self::FIELD, minSeconds: 3, failurePolicy: $policy);
    }

    private function stamp(string $formId = 'contact'): string
    {
        return $this->service()->sign(new TimeTrapToken(self::ISSUED_AT, $formId));
    }

    /** @return array<string, string> */
    private function fields(string $token): array
    {
        return [self::FIELD => $token];
    }

    #[Test]
    public function aSubmissionWithinTheWindowIsAcceptedUnderEveryPolicy(): void
    {
        foreach (TimeTrapFailurePolicy::cases() as $policy) {
            $decision = $this->guard($policy)->evaluate(
                $this->fields($this->stamp()),
                'contact',
                self::ISSUED_AT + 10,
            );

            self::assertSame(TimeTrapDecision::Accept, $decision, $policy->value);
        }
    }

    #[Test]
    public function tooFastUnderSilentAcceptDropsSilently(): void
    {
        $decision = $this->guard(TimeTrapFailurePolicy::SilentAccept)->evaluate(
            $this->fields($this->stamp()),
            'contact',
            self::ISSUED_AT + 1,
        );

        self::assertSame(TimeTrapDecision::SilentlyDrop, $decision);
    }

    #[Test]
    public function tooFastUnderHardRejectRejects(): void
    {
        $decision = $this->guard(TimeTrapFailurePolicy::HardReject)->evaluate(
            $this->fields($this->stamp()),
            'contact',
            self::ISSUED_AT + 1,
        );

        self::assertSame(TimeTrapDecision::Reject, $decision);
    }

    #[Test]
    public function tooFastUnderScoreOnlyFailsOpen(): void
    {
        // Score-only outside a pipeline never blocks — the zero-lost-lead default.
        $decision = $this->guard(TimeTrapFailurePolicy::ScoreOnly)->evaluate(
            $this->fields($this->stamp()),
            'contact',
            self::ISSUED_AT + 1,
        );

        self::assertSame(TimeTrapDecision::Accept, $decision);
    }

    #[Test]
    public function aMissingStampIsAcceptedUnderEveryPolicy(): void
    {
        foreach (TimeTrapFailurePolicy::cases() as $policy) {
            self::assertSame(
                TimeTrapDecision::Accept,
                $this->guard($policy)->evaluate([], 'contact', self::ISSUED_AT + 1),
                $policy->value,
            );
        }
    }

    #[Test]
    public function aWrongFormStampFailsOpenEvenUnderHardReject(): void
    {
        // A stamp bound to another form is not positive evidence this form was
        // auto-filled, so even the hardest policy must not block on it.
        $decision = $this->guard(TimeTrapFailurePolicy::HardReject)->evaluate(
            $this->fields($this->stamp('newsletter')),
            'contact',
            self::ISSUED_AT + 1,
        );

        self::assertSame(TimeTrapDecision::Accept, $decision);
    }

    #[Test]
    public function isTooFastReportsTheRawTimingSignal(): void
    {
        $guard = $this->guard(TimeTrapFailurePolicy::ScoreOnly);

        self::assertTrue($guard->isTooFast($this->fields($this->stamp()), 'contact', self::ISSUED_AT + 1));
        self::assertFalse($guard->isTooFast($this->fields($this->stamp()), 'contact', self::ISSUED_AT + 10));
    }

    #[Test]
    public function fieldNameIsExposedForEndToEndWiring(): void
    {
        self::assertSame(self::FIELD, $this->guard(TimeTrapFailurePolicy::ScoreOnly)->fieldName());
    }
}
