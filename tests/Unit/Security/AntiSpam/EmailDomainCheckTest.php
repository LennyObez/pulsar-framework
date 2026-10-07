<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\DisposableEmailDomains;
use Pulsar\Security\AntiSpam\EmailDomainCheck;
use Pulsar\Security\AntiSpam\EmailDomainCheckConfig;
use Pulsar\Security\AntiSpam\EmailDomainSignalMode;
use Pulsar\Security\AntiSpam\MxDeliverabilityResolverInterface;

#[CoversClass(EmailDomainCheck::class)]
final class EmailDomainCheckTest extends TestCase
{
    #[Test]
    public function disposableDomainHardGatesAndFailsTheCheck(): void
    {
        $result = $this->check(
            new EmailDomainCheckConfig(disposableBlock: EmailDomainSignalMode::Hard, mxCheckEnabled: false),
            null,
        )->check($this->context('bob@mailinator.com'));

        self::assertFalse($result->passed);
        self::assertSame('email_domain', $result->checkName);
        self::assertGreaterThan(0, $result->score);
        self::assertNotNull($result->reason);
        self::assertStringContainsString('disposable', (string) $result->reason);
    }

    #[Test]
    public function disposableInScoreModeContributesScoreWithoutFailing(): void
    {
        $result = $this->check(
            new EmailDomainCheckConfig(disposableBlock: EmailDomainSignalMode::Score, mxCheckEnabled: false),
            null,
        )->check($this->context('bob@mailinator.com'));

        self::assertTrue($result->passed, 'score mode must not fail the check');
        self::assertSame(40, $result->score);
    }

    #[Test]
    public function disposableOffIgnoresTheDomain(): void
    {
        $result = $this->check(
            new EmailDomainCheckConfig(disposableBlock: EmailDomainSignalMode::Off, mxCheckEnabled: false),
            null,
        )->check($this->context('bob@mailinator.com'));

        self::assertTrue($result->passed);
        self::assertSame(0, $result->score);
    }

    #[Test]
    public function matchesADisposableSubdomainViaItsRegistrableParent(): void
    {
        $result = $this->check(
            new EmailDomainCheckConfig(disposableBlock: EmailDomainSignalMode::Hard, mxCheckEnabled: false),
            null,
        )->check($this->context('bob@inbox.mailinator.com'));

        self::assertFalse($result->passed);
    }

    #[Test]
    public function undeliverableDomainHardGatesWhenMxIsHard(): void
    {
        $result = $this->check(
            new EmailDomainCheckConfig(
                disposableBlock: EmailDomainSignalMode::Off,
                mxCheckEnabled: true,
                mxBlock: EmailDomainSignalMode::Hard,
            ),
            false, // provably undeliverable
        )->check($this->context('bob@no-such-mail.example'));

        self::assertFalse($result->passed);
        self::assertStringContainsString('MX', (string) $result->reason);
    }

    #[Test]
    public function mxFailsOpenWhenTheResolverIsUnreachable(): void
    {
        $result = $this->check(
            new EmailDomainCheckConfig(
                disposableBlock: EmailDomainSignalMode::Off,
                mxCheckEnabled: true,
                mxBlock: EmailDomainSignalMode::Hard,
            ),
            null, // resolver unreachable → unknown
        )->check($this->context('bob@example.org'));

        self::assertTrue($result->passed, 'an unreachable resolver must never block');
        self::assertSame(0, $result->score);
    }

    #[Test]
    public function deliverableNonDisposableDomainPasses(): void
    {
        $result = $this->check(
            new EmailDomainCheckConfig(
                disposableBlock: EmailDomainSignalMode::Hard,
                mxCheckEnabled: true,
                mxBlock: EmailDomainSignalMode::Hard,
            ),
            true,
        )->check($this->context('alice@a-real-company.example'));

        self::assertTrue($result->passed);
        self::assertSame(0, $result->score);
    }

    #[Test]
    public function bothSignalsHardCombineIntoOneFailure(): void
    {
        $result = $this->check(
            new EmailDomainCheckConfig(
                disposableBlock: EmailDomainSignalMode::Hard,
                mxCheckEnabled: true,
                mxBlock: EmailDomainSignalMode::Hard,
            ),
            false,
        )->check($this->context('bob@mailinator.com'));

        self::assertFalse($result->passed);
        self::assertSame(75, $result->score);
        self::assertStringContainsString('disposable', (string) $result->reason);
        self::assertStringContainsString('MX', (string) $result->reason);
    }

    #[Test]
    public function anUnparseableOrDotlessAddressIsSimplyOk(): void
    {
        $check = $this->check(
            new EmailDomainCheckConfig(disposableBlock: EmailDomainSignalMode::Hard),
            false,
        );

        self::assertTrue($check->check($this->context('not-an-address'))->passed);
        self::assertTrue($check->check($this->context('bob@localhost'))->passed);
        self::assertTrue($check->check($this->context(null))->passed);
    }

    #[Test]
    public function readsTheAddressFromTheConventionalEmailFormField(): void
    {
        $result = $this->check(
            new EmailDomainCheckConfig(disposableBlock: EmailDomainSignalMode::Hard, mxCheckEnabled: false),
            null,
        )->check(new AntiSpamContext(
            body: 'hello',
            ipHash: 'abc',
            formFields: ['email' => 'bob@mailinator.com'],
        ));

        self::assertFalse($result->passed, 'the check must read the conventional email form field');
    }

    private function check(EmailDomainCheckConfig $config, ?bool $deliverable): EmailDomainCheck
    {
        $resolver = $this->createStub(MxDeliverabilityResolverInterface::class);
        $resolver->method('isDeliverable')->willReturn($deliverable);

        return new EmailDomainCheck(
            new DisposableEmailDomains(['mailinator.com', 'jmailservice.com']),
            $resolver,
            $config,
        );
    }

    private function context(?string $email): AntiSpamContext
    {
        return new AntiSpamContext(body: 'hello', ipHash: 'abc', email: $email);
    }
}
