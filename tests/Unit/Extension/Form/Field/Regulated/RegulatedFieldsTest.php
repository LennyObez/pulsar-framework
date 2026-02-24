<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Field\Regulated;

use PHPUnit\Framework\Attributes\CoversClass;
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
#[CoversClass(ConsentEvidence::class)]
#[CoversClass(DataProcessingAgreement::class)]
#[CoversClass(SignatureField::class)]
final class RegulatedFieldsTest extends TestCase
{
    // --- AbstractRegulatedField via AgeVerification ---

    #[Test]
    public function regulatedFieldReturnsPurpose(): void
    {
        $field = new AgeVerification(
            'age_confirm',
            'Age Confirmation',
            'age_gate',
            '2.1',
            'I confirm I am 18 or older.',
        );

        self::assertSame('age_gate', $field->getPurpose());
    }

    #[Test]
    public function regulatedFieldReturnsPolicyVersion(): void
    {
        $field = new AgeVerification(
            'age_confirm',
            'Age Confirmation',
            'age_gate',
            '2.1',
            'I confirm I am 18 or older.',
        );

        self::assertSame('2.1', $field->getPolicyVersion());
    }

    #[Test]
    public function regulatedFieldReturnsPolicyText(): void
    {
        $policyText = 'I confirm I am 18 years of age or older and agree to the terms.';
        $field = new AgeVerification(
            'age_confirm',
            'Age Confirmation',
            'age_gate',
            '2.1',
            $policyText,
        );

        self::assertSame($policyText, $field->getPolicyText());
    }

    #[Test]
    public function captureEvidenceBuildsCorrectEvidence(): void
    {
        $field = new AgeVerification(
            'age_check',
            'Age Check',
            'age_verification',
            '1.0',
            'I confirm I am over 21.',
        );

        $evidence = $field->captureEvidence(
            locale: 'en-US',
            subject: 'user-550e8400-e29b-41d4-a716-446655440000',
            correlationId: 'session-abc123',
            templateHash: 'sha256:deadbeef',
        );

        self::assertSame('age_verification', $evidence->purpose);
        self::assertSame('1.0', $evidence->policyVersion);
        self::assertSame('en-US', $evidence->locale);
        self::assertSame('user-550e8400-e29b-41d4-a716-446655440000', $evidence->subject);
        self::assertSame('session-abc123', $evidence->correlationId);
        self::assertSame('sha256:deadbeef', $evidence->templateHash);
        self::assertSame(hash('sha256', 'I confirm I am over 21.'), $evidence->policyTextHash);
        self::assertNotEmpty($evidence->timestamp);
    }

    #[Test]
    public function getEvidenceReturnsNullBeforeCapture(): void
    {
        $field = new AgeVerification('age', 'Age', 'purpose', '1.0', 'Policy text');

        self::assertNull($field->getEvidence());
    }

    #[Test]
    public function getEvidenceReturnsEvidenceAfterCapture(): void
    {
        $field = new AgeVerification('age', 'Age', 'purpose', '1.0', 'Policy text');
        $field->captureEvidence('en', 'user-1', 'corr-1', 'hash-1');

        self::assertNotNull($field->getEvidence());
    }

    // --- AgeVerification specific ---

    #[Test]
    public function ageVerificationReturnsCheckboxType(): void
    {
        $field = new AgeVerification('age', 'Age Check', 'age', '1.0', 'Confirm age');
        self::assertSame('checkbox', $field->getType());
    }

    #[Test]
    public function ageVerificationDefaultMinimumAge(): void
    {
        $field = new AgeVerification('age', 'Age', 'age', '1.0', 'Policy');
        self::assertSame(18, $field->getMinimumAge());
    }

    #[Test]
    public function ageVerificationCustomMinimumAge(): void
    {
        $field = new AgeVerification('age', 'Age', 'age', '1.0', 'Policy', minimumAge: 21);
        self::assertSame(21, $field->getMinimumAge());
    }

    #[Test]
    public function ageVerificationIsVerifiedReturnsFalseByDefault(): void
    {
        $field = new AgeVerification('age', 'Age', 'age', '1.0', 'Policy');
        self::assertFalse($field->isVerified());
    }

    #[Test]
    public function ageVerificationIsVerifiedReturnsTrueWhenChecked(): void
    {
        $field = new AgeVerification('age', 'Age', 'age', '1.0', 'Policy');
        $field->setValue(true);
        self::assertTrue($field->isVerified());
    }

    // --- DataProcessingAgreement ---

    #[Test]
    public function dpaReturnsCheckboxType(): void
    {
        $field = new DataProcessingAgreement('dpa', 'DPA', 'data_processing', '3.0', 'I agree to processing');
        self::assertSame('checkbox', $field->getType());
    }

    #[Test]
    public function dpaIsAcceptedReturnsFalseByDefault(): void
    {
        $field = new DataProcessingAgreement('dpa', 'DPA', 'data_processing', '3.0', 'Policy');
        self::assertFalse($field->isAccepted());
    }

    #[Test]
    public function dpaIsAcceptedReturnsTrueWhenChecked(): void
    {
        $field = new DataProcessingAgreement('dpa', 'DPA', 'data_processing', '3.0', 'Policy');
        $field->setValue(true);
        self::assertTrue($field->isAccepted());
    }

    // --- SignatureField ---

    #[Test]
    public function signatureFieldReturnsTextType(): void
    {
        $field = new SignatureField('sig', 'Signature', 'contract', '1.0', 'Sign here');
        self::assertSame('text', $field->getType());
    }

    #[Test]
    public function signatureFieldEmptyByDefault(): void
    {
        $field = new SignatureField('sig', 'Signature', 'contract', '1.0', 'Sign here');
        self::assertSame('', $field->getSignature());
        self::assertFalse($field->isSigned());
    }

    #[Test]
    public function signatureFieldWithValue(): void
    {
        $field = new SignatureField('sig', 'Signature', 'contract', '1.0', 'Sign here');
        $field->setValue('John Doe');
        self::assertSame('John Doe', $field->getSignature());
        self::assertTrue($field->isSigned());
    }

    // --- ConsentCheckbox ---

    #[Test]
    public function consentCheckboxReturnsCheckboxType(): void
    {
        $field = new ConsentCheckbox('consent', 'I agree', 'marketing', '1.0', 'Consent text');
        self::assertSame('checkbox', $field->getType());
    }

    #[Test]
    public function consentCheckboxNotConsentedByDefault(): void
    {
        $field = new ConsentCheckbox('consent', 'I agree', 'marketing', '1.0', 'Consent text');
        self::assertFalse($field->isConsented());
    }

    #[Test]
    public function consentCheckboxConsentedWhenChecked(): void
    {
        $field = new ConsentCheckbox('consent', 'I agree', 'marketing', '1.0', 'Consent text');
        $field->setValue(true);
        self::assertTrue($field->isConsented());
    }

    // --- ConsentEvidence ---

    #[Test]
    public function consentEvidenceToArray(): void
    {
        $evidence = new ConsentEvidence(
            timestamp: '2026-03-07T12:00:00+00:00',
            purpose: 'marketing',
            policyVersion: '2.0',
            locale: 'fr-FR',
            subject: 'user-456',
            correlationId: 'req-def',
            templateHash: 'tmpl-hash',
            policyTextHash: 'policy-hash',
        );

        $array = $evidence->toArray();

        self::assertSame('2026-03-07T12:00:00+00:00', $array['timestamp']);
        self::assertSame('marketing', $array['purpose']);
        self::assertSame('2.0', $array['policy_version']);
        self::assertSame('fr-FR', $array['locale']);
        self::assertSame('user-456', $array['subject']);
        self::assertSame('req-def', $array['correlation_id']);
        self::assertSame('tmpl-hash', $array['template_hash']);
        self::assertSame('policy-hash', $array['policy_text_hash']);
    }
}
