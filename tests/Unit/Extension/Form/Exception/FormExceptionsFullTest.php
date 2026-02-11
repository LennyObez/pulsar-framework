<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Exception\CsrfException;
use Pulsar\Extension\Form\Exception\FormException;
use Pulsar\Extension\Form\Exception\UploadException;
use Pulsar\Extension\Form\Exception\WizardException;

#[CoversClass(FormException::class)]
#[CoversClass(CsrfException::class)]
#[CoversClass(UploadException::class)]
#[CoversClass(WizardException::class)]
final class FormExceptionsFullTest extends TestCase
{
    // --- FormException ---

    #[Test]
    public function invalidField(): void
    {
        $e = FormException::invalidField('email');
        self::assertStringContainsString('email', $e->getMessage());
    }

    #[Test]
    public function alreadySubmitted(): void
    {
        $e = FormException::alreadySubmitted();
        self::assertStringContainsString('already been submitted', $e->getMessage());
    }

    #[Test]
    public function notSubmitted(): void
    {
        $e = FormException::notSubmitted();
        self::assertStringContainsString('not been submitted', $e->getMessage());
    }

    #[Test]
    public function configurationError(): void
    {
        $e = FormException::configurationError('bad config');
        self::assertStringContainsString('bad config', $e->getMessage());
    }

    // --- CsrfException ---

    #[Test]
    public function csrfTokenMissing(): void
    {
        $e = CsrfException::tokenMissing();
        self::assertStringContainsString('missing', $e->getMessage());
        self::assertInstanceOf(FormException::class, $e);
    }

    #[Test]
    public function csrfTokenInvalid(): void
    {
        $e = CsrfException::tokenInvalid();
        self::assertStringContainsString('invalid', $e->getMessage());
    }

    #[Test]
    public function csrfTokenExpired(): void
    {
        $e = CsrfException::tokenExpired();
        self::assertStringContainsString('expired', $e->getMessage());
    }

    // --- UploadException ---

    #[Test]
    public function uploadFileTooLarge(): void
    {
        $e = UploadException::fileTooLarge('avatar', 5242880);
        self::assertStringContainsString('avatar', $e->getMessage());
        self::assertStringContainsString('5242880', $e->getMessage());
    }

    #[Test]
    public function uploadInvalidMimeType(): void
    {
        $e = UploadException::invalidMimeType('doc', 'text/html', 'application/pdf');
        self::assertStringContainsString('text/html', $e->getMessage());
        self::assertStringContainsString('application/pdf', $e->getMessage());
    }

    #[Test]
    public function uploadAntivirusScanFailed(): void
    {
        $e = UploadException::antivirusScanFailed('attachment');
        self::assertStringContainsString('Antivirus', $e->getMessage());
    }

    #[Test]
    public function uploadAntivirusNotConfigured(): void
    {
        $e = UploadException::antivirusNotConfigured();
        self::assertStringContainsString('AntivirusPort', $e->getMessage());
    }

    #[Test]
    public function uploadMoveFailed(): void
    {
        $e = UploadException::moveFailed('file', '/tmp/dest');
        self::assertStringContainsString('/tmp/dest', $e->getMessage());
    }

    // --- WizardException ---

    #[Test]
    public function wizardExpired(): void
    {
        $e = WizardException::expired();
        self::assertStringContainsString('expired', $e->getMessage());
    }

    #[Test]
    public function wizardInvalidResumeToken(): void
    {
        $e = WizardException::invalidResumeToken();
        self::assertStringContainsString('invalid', $e->getMessage());
    }

    #[Test]
    public function wizardStepReplay(): void
    {
        $e = WizardException::stepReplay(2);
        self::assertStringContainsString('Step 2', $e->getMessage());
        self::assertStringContainsString('already been completed', $e->getMessage());
    }

    #[Test]
    public function wizardInvalidTransition(): void
    {
        $e = WizardException::invalidTransition(1, 5);
        self::assertStringContainsString('step 1', $e->getMessage());
        self::assertStringContainsString('step 5', $e->getMessage());
    }

    #[Test]
    public function wizardDecryptionFailed(): void
    {
        $e = WizardException::decryptionFailed();
        self::assertStringContainsString('decrypt', $e->getMessage());
    }
}
