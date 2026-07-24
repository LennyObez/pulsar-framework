<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\TimeTrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapCheck;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapService;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapToken;

use function substr;

#[CoversClass(TimeTrapCheck::class)]
final class TimeTrapCheckTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef'; // 32 bytes

    private const string FIELD = 'pulsar-form-ts';

    private const int ISSUED_AT = 1_700_000_000;

    private function service(): TimeTrapService
    {
        return new TimeTrapService(self::KEY);
    }

    private function check(TimeTrapService $service): TimeTrapCheck
    {
        return new TimeTrapCheck($service, self::FIELD, minSeconds: 3);
    }

    /**
     * Build a context whose submission time is $age seconds after the stamp was
     * issued, so the fill duration is deterministic and independent of the clock.
     */
    private function contextForAge(string $token, int $age, string $formId = 'contact'): AntiSpamContext
    {
        return new AntiSpamContext(
            body: 'hello world',
            ipHash: 'iphash',
            formFields: [self::FIELD => $token],
            submissionTimestamp: self::ISSUED_AT + $age,
            formId: $formId,
        );
    }

    private function stamp(string $formId = 'contact'): string
    {
        return $this->service()->sign(new TimeTrapToken(self::ISSUED_AT, $formId));
    }

    #[Test]
    public function submissionWithinTheWindowPasses(): void
    {
        $service = $this->service();
        $result = $this->check($service)->check($this->contextForAge($this->stamp(), age: 10));

        self::assertTrue($result->passed);
        self::assertSame('time_trap', $result->checkName);
    }

    #[Test]
    public function tooFastSubmissionIsFlagged(): void
    {
        // The ONE blocking case: a validly-signed stamp submitted faster than a
        // human could plausibly fill the form.
        $service = $this->service();
        $result = $this->check($service)->check($this->contextForAge($this->stamp(), age: 1));

        self::assertFalse($result->passed);
        self::assertSame(30, $result->score);
    }

    #[Test]
    public function staleSubmissionFailsOpen(): void
    {
        // A slow human with a stale tab must never lose their submission.
        $service = $this->service();
        $result = $this->check($service)->check($this->contextForAge($this->stamp(), age: 7200));

        self::assertTrue($result->passed);
    }

    #[Test]
    public function futureStampBeyondSkewFailsOpen(): void
    {
        // A stamp dated in the future is a clock anomaly, not "too fast" evidence.
        $service = $this->service();
        $result = $this->check($service)->check($this->contextForAge($this->stamp(), age: -60));

        self::assertTrue($result->passed);
    }

    #[Test]
    public function tamperedStampFailsOpen(): void
    {
        // A forged stamp cannot be positive bot evidence — never block on it.
        $service = $this->service();
        $valid = $this->stamp();
        $lastChar = substr($valid, -1) === 'A' ? 'B' : 'A';
        $tampered = substr($valid, 0, -1) . $lastChar;

        $result = $this->check($service)->check($this->contextForAge($tampered, age: 1));

        self::assertTrue($result->passed);
    }

    #[Test]
    public function stampMintedForAnotherFormFailsOpen(): void
    {
        // A stamp bound to another form is not evidence this form was auto-filled.
        $service = $this->service();
        $foreignStamp = $this->stamp('newsletter');

        $result = $this->check($service)->check(
            $this->contextForAge($foreignStamp, age: 1, formId: 'contact'),
        );

        self::assertTrue($result->passed);
    }

    #[Test]
    public function missingStampFailsOpen(): void
    {
        // No stamp ⇒ no timing evidence ⇒ honeypot/captcha/rate-limit cover it.
        $service = $this->service();
        $context = new AntiSpamContext(
            body: 'hello world',
            ipHash: 'iphash',
            formFields: [],
            submissionTimestamp: self::ISSUED_AT + 1,
            formId: 'contact',
        );

        $result = $this->check($service)->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function boundaryAtMinSecondsPasses(): void
    {
        $service = $this->service();
        // Exactly minSeconds (3) old: not "faster than" the minimum, so it passes.
        $result = $this->check($service)->check($this->contextForAge($this->stamp(), age: 3));

        self::assertTrue($result->passed);
    }
}
