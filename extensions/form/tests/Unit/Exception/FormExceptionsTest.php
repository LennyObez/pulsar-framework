<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Exception\CsrfException;
use Pulsar\Extension\Form\Exception\FormException;
use Pulsar\Extension\Form\Exception\UploadException;
use Pulsar\Extension\Form\Exception\WizardException;

final class FormExceptionsTest extends TestCase
{
    #[Test]
    public function formExceptionFactories(): void
    {
        self::assertStringContainsString('email', FormException::invalidField('email')->getMessage());
        self::assertStringContainsString('already', FormException::alreadySubmitted()->getMessage());
        self::assertStringContainsString('not been submitted', FormException::notSubmitted()->getMessage());
        self::assertStringContainsString('custom error', FormException::configurationError('custom error')->getMessage());
    }

    #[Test]
    public function csrfExceptionFactories(): void
    {
        self::assertInstanceOf(FormException::class, CsrfException::tokenMissing());
        self::assertInstanceOf(FormException::class, CsrfException::tokenInvalid());
        self::assertInstanceOf(FormException::class, CsrfException::tokenExpired());
    }

    #[Test]
    public function uploadExceptionFactories(): void
    {
        self::assertStringContainsString('avatar', UploadException::fileTooLarge('avatar', 5_000_000)->getMessage());
        self::assertStringContainsString('image/png', UploadException::invalidMimeType('file', 'text/plain', 'image/png')->getMessage());
        self::assertStringContainsString('Antivirus', UploadException::antivirusScanFailed('doc')->getMessage());
        self::assertStringContainsString('AntivirusPort', UploadException::antivirusNotConfigured()->getMessage());
        self::assertStringContainsString('/uploads', UploadException::moveFailed('doc', '/uploads/test.pdf')->getMessage());
    }

    #[Test]
    public function wizardExceptionFactories(): void
    {
        self::assertStringContainsString('expired', WizardException::expired()->getMessage());
        self::assertStringContainsString('invalid', WizardException::invalidResumeToken()->getMessage());
        self::assertStringContainsString('Step 3', WizardException::stepReplay(3)->getMessage());
        self::assertStringContainsString('step 1', WizardException::invalidTransition(1, 3)->getMessage());
        self::assertStringContainsString('decrypt', WizardException::decryptionFailed()->getMessage());
    }
}
