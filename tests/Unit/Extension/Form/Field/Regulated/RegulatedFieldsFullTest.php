<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Field\Regulated;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\Regulated\AbstractRegulatedField;
use Pulsar\Extension\Form\Field\Regulated\AgeVerification;
use Pulsar\Extension\Form\Field\Regulated\ConsentCheckbox;
use Pulsar\Extension\Form\Field\Regulated\ConsentEvidence;
use Pulsar\Extension\Form\Field\Regulated\DataProcessingAgreement;
use Pulsar\Extension\Form\Field\Regulated\SignatureField;

#[CoversClass(AbstractRegulatedField::class)]
#[CoversClass(AgeVerification::class)]
#[CoversClass(ConsentCheckbox::class)]
#[CoversClass(DataProcessingAgreement::class)]
#[CoversClass(SignatureField::class)]
#[CoversClass(ConsentEvidence::class)]
final class RegulatedFieldsFullTest extends TestCase
{
    // --- AgeVerification ---

    #[Test]
    public function ageVerificationTypeIsCheckbox(): void
    {
        $field = new AgeVerification('age_check', 'Age Check', 'verification', 'v1', 'Are you 18+?');
        self::assertSame('checkbox', $field->getType());
    }

    #[Test]
    public function ageVerificationDefaultMinimumAge(): void
    {
        $field = new AgeVerification('age_check', 'Age Check', 'verification', 'v1', 'policy');
        self::assertSame(18, $field->getMinimumAge());
    }

    #[Test]
    public function ageVerificationCustomMinimumAge(): void
    {
        $field = new AgeVerification('age_check', 'Age Check', 'verification', 'v1', 'policy', 21);
        self::assertSame(21, $field->getMinimumAge());
    }

    #[Test]
    public function ageVerificationIsVerifiedWhenTrue(): void
    {
        $field = new AgeVerification('age_check', 'Age Check', 'verification', 'v1', 'policy');
        $field->setValue(true);
        self::assertTrue($field->isVerified());
    }

    #[Test]
    public function ageVerificationIsNotVerifiedWhenFalse(): void
    {
        $field = new AgeVerification('age_check', 'Age Check', 'verification', 'v1', 'policy');
        $field->setValue(false);
        self::assertFalse($field->isVerified());
    }

    #[Test]
    public function ageVerificationIsNotVerifiedWhenNull(): void
    {
        $field = new AgeVerification('age_check', 'Age Check', 'verification', 'v1', 'policy');
        self::assertFalse($field->isVerified());
    }

    // --- ConsentCheckbox ---

    #[Test]
    public function consentCheckboxTypeIsCheckbox(): void
    {
        $field = new ConsentCheckbox('gdpr', 'GDPR Consent', 'marketing', 'v2', 'I consent');
        self::assertSame('checkbox', $field->getType());
    }

    #[Test]
    public function consentCheckboxIsConsentedWhenTrue(): void
    {
        $field = new ConsentCheckbox('gdpr', 'GDPR', 'consent', 'v1', 'text');
        $field->setValue(true);
        self::assertTrue($field->isConsented());
    }

    #[Test]
    public function consentCheckboxIsNotConsentedWhenFalseOrNull(): void
    {
        $field = new ConsentCheckbox('gdpr', 'GDPR', 'consent', 'v1', 'text');
        self::assertFalse($field->isConsented());

        $field->setValue(false);
        self::assertFalse($field->isConsented());
    }

    // --- DataProcessingAgreement ---

    #[Test]
    public function dpaTypeIsCheckbox(): void
    {
        $field = new DataProcessingAgreement('dpa', 'DPA', 'processing', 'v1', 'Agreement text');
        self::assertSame('checkbox', $field->getType());
    }

    #[Test]
    public function dpaIsAcceptedWhenTrue(): void
    {
        $field = new DataProcessingAgreement('dpa', 'DPA', 'processing', 'v1', 'Agreement text');
        $field->setValue(true);
        self::assertTrue($field->isAccepted());
    }

    #[Test]
    public function dpaIsNotAcceptedWhenFalseOrNull(): void
    {
        $field = new DataProcessingAgreement('dpa', 'DPA', 'processing', 'v1', 'Agreement text');
        self::assertFalse($field->isAccepted());

        $field->setValue(false);
        self::assertFalse($field->isAccepted());
    }

    // --- SignatureField ---

    #[Test]
    public function signatureFieldTypeIsText(): void
    {
        $field = new SignatureField('sig', 'Signature', 'signing', 'v1', 'Sign here');
        self::assertSame('text', $field->getType());
    }

    #[Test]
    public function signatureFieldGetSignatureReturnsEmptyWhenNull(): void
    {
        $field = new SignatureField('sig', 'Signature', 'signing', 'v1', 'Sign here');
        self::assertSame('', $field->getSignature());
    }

    #[Test]
    public function signatureFieldGetSignatureReturnsStringValue(): void
    {
        $field = new SignatureField('sig', 'Signature', 'signing', 'v1', 'Sign here');
        $field->setValue('John Doe');
        self::assertSame('John Doe', $field->getSignature());
    }

    #[Test]
    public function signatureFieldGetSignatureCoercesNumericValue(): void
    {
        $field = new SignatureField('sig', 'Signature', 'signing', 'v1', 'Sign here');
        $field->setValue(42);
        self::assertSame('42', $field->getSignature());
    }

    #[Test]
    public function signatureFieldIsSignedWhenValuePresent(): void
    {
        $field = new SignatureField('sig', 'Signature', 'signing', 'v1', 'Sign here');
        $field->setValue('Jane');
        self::assertTrue($field->isSigned());
    }

    #[Test]
    public function signatureFieldIsNotSignedWhenEmpty(): void
    {
        $field = new SignatureField('sig', 'Signature', 'signing', 'v1', 'Sign here');
        self::assertFalse($field->isSigned());

        $field->setValue('');
        self::assertFalse($field->isSigned());
    }

    // --- AbstractRegulatedField shared behavior ---

    #[Test]
    public function regulatedFieldExposesMetadata(): void
    {
        $field = new ConsentCheckbox('gdpr', 'GDPR Consent', 'marketing', 'v2.1', 'Full policy text here');

        self::assertSame('marketing', $field->purpose);
        self::assertSame('v2.1', $field->policyVersion);
        self::assertSame('Full policy text here', $field->policyText);
    }

    #[Test]
    public function captureEvidenceCreatesAndStoresEvidence(): void
    {
        $field = new ConsentCheckbox('gdpr', 'GDPR', 'marketing', 'v1', 'Policy text');

        self::assertNull($field->getEvidence());

        $evidence = $field->captureEvidence('en-US', 'user-42', 'corr-1', 'tmpl-hash');

        self::assertInstanceOf(ConsentEvidence::class, $evidence);
        self::assertSame('marketing', $evidence->purpose);
        self::assertSame('v1', $evidence->policyVersion);
        self::assertSame('en-US', $evidence->locale);
        self::assertSame('user-42', $evidence->subject);
        self::assertSame('corr-1', $evidence->correlationId);
        self::assertSame('tmpl-hash', $evidence->templateHash);
        self::assertSame(hash('sha256', 'Policy text'), $evidence->policyTextHash);
        self::assertSame($evidence, $field->getEvidence());
    }

    #[Test]
    public function captureEvidenceOverwritesPreviousEvidence(): void
    {
        $field = new ConsentCheckbox('gdpr', 'GDPR', 'marketing', 'v1', 'Policy text');

        $first = $field->captureEvidence('en', 'user-1', 'c1', 't1');
        $second = $field->captureEvidence('fr', 'user-2', 'c2', 't2');

        self::assertNotSame($first, $second);
        self::assertSame($second, $field->getEvidence());
        self::assertSame('fr', $second->locale);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function consentedEdgeCases(): iterable
    {
        yield 'int 1' => [1, true];
        yield 'string 1' => ['1', true];
        yield 'int 0' => [0, false];
        yield 'empty string' => ['', false];
    }

    #[Test]
    #[DataProvider('consentedEdgeCases')]
    public function consentCheckboxIsConsentedWithVariousTypes(mixed $value, bool $expected): void
    {
        $field = new ConsentCheckbox('gdpr', 'GDPR', 'consent', 'v1', 'text');
        $field->setValue($value);
        self::assertSame($expected, $field->isConsented());
    }
}
