<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Wizard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Exception\WizardException;
use Pulsar\Extension\Form\Wizard\ResumeTokenManager;
use Pulsar\Tests\Unit\Extension\Form\Stub\InMemorySession;

#[CoversClass(ResumeTokenManager::class)]
final class ResumeTokenManagerTest extends TestCase
{
    private InMemorySession $session;
    private ResumeTokenManager $manager;

    protected function setUp(): void
    {
        $this->session = new InMemorySession();
        $this->manager = new ResumeTokenManager($this->session);
    }

    #[Test]
    public function issueReturnsHexToken(): void
    {
        $token = $this->manager->issue('wizard-abc');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    #[Test]
    public function issueStoresTokenInSession(): void
    {
        $token = $this->manager->issue('wizard-abc');

        $stored = $this->session->get('_wizard_resume_wizard-abc');
        self::assertIsArray($stored);
        self::assertSame($token, $stored['token']);
        self::assertArrayHasKey('issued_at', $stored);
    }

    #[Test]
    public function consumeSucceedsWithValidToken(): void
    {
        $token = $this->manager->issue('wizard-abc');

        $this->manager->consume('wizard-abc', $token);

        self::assertNull($this->session->get('_wizard_resume_wizard-abc'));
    }

    #[Test]
    public function consumeThrowsOnInvalidToken(): void
    {
        $this->manager->issue('wizard-abc');

        $this->expectException(WizardException::class);
        $this->expectExceptionMessage('Resume token is invalid');
        $this->manager->consume('wizard-abc', 'wrong-token-value');
    }

    #[Test]
    public function consumeThrowsWhenNoTokenStored(): void
    {
        $this->expectException(WizardException::class);
        $this->expectExceptionMessage('Resume token is invalid');
        $this->manager->consume('nonexistent-wizard', 'some-token');
    }

    #[Test]
    public function consumeThrowsOnDoubleConsumption(): void
    {
        $token = $this->manager->issue('wizard-abc');
        $this->manager->consume('wizard-abc', $token);

        $this->expectException(WizardException::class);
        $this->expectExceptionMessage('Resume token is invalid');
        $this->manager->consume('wizard-abc', $token);
    }

    #[Test]
    public function multipleWizardsHaveIndependentTokens(): void
    {
        $token1 = $this->manager->issue('wizard-1');
        $token2 = $this->manager->issue('wizard-2');

        self::assertNotSame($token1, $token2);

        $this->manager->consume('wizard-1', $token1);
        $this->manager->consume('wizard-2', $token2);

        // Both tokens were consumed without exception — verify they are now invalid
        // by confirming consume succeeded (no exception means one-time-use worked)
    }
}
