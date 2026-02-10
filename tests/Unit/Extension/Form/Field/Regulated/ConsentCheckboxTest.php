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
#[CoversClass(ConsentCheckbox::class)]
#[CoversClass(ConsentEvidence::class)]
#[CoversClass(DataProcessingAgreement::class)]
#[CoversClass(AgeVerification::class)]
#[CoversClass(SignatureField::class)]
final class ConsentCheckboxTest extends TestCase
{
    #[Test]
    public function consent_checkbox_captures_evidence(): void
    {
        $field = new ConsentCheckbox(
            'marketing',
            'I agree to receive marketing',
            'marketing',
            'v1.2',
            'I consent to receiving marketing communications.',
        );

        $field->setValue(true);
        self::assertTrue($field->isConsented());

        $evidence = $field->captureEvidence(
            locale: 'en-US',
            subject: 'user-123',
            correlationId: 'req-abc',
            templateHash: 'tmpl-hash-456',
        );

        self::assertSame('marketing', $evidence->purpose);
        self::assertSame('v1.2', $evidence->policyVersion);
        self::assertSame('en-US', $evidence->locale);
        self::assertSame('user-123', $evidence->subject);
        self::assertSame('req-abc', $evidence->correlationId);
        self::assertSame('tmpl-hash-456', $evidence->templateHash);

        // policy_text_hash is a SHA-256 of the consent text
        $expectedHash = hash('sha256', 'I consent to receiving marketing communications.');
        self::assertSame($expectedHash, $evidence->policyTextHash);

        // Verify evidence is retrievable
        self::assertSame($evidence, $field->getEvidence());
    }

    #[Test]
    public function consent_checkbox_tracks_consent_state(): void
    {
        $field = new ConsentCheckbox('gdpr', 'GDPR Consent', 'gdpr', 'v1', 'text');

        self::assertFalse($field->isConsented());

        $field->setValue(true);
        self::assertTrue($field->isConsented());

        $field->setValue(false);
        self::assertFalse($field->isConsented());
    }

    #[Test]
    public function consent_evidence_serializes_to_array(): void
    {
        $evidence = new ConsentEvidence(
            timestamp: '2026-02-17T10:00:00+00:00',
            purpose: 'marketing',
            policyVersion: 'v1',
            locale: 'en',
            subject: 'user-1',
            correlationId: 'req-1',
            templateHash: 'hash1',
            policyTextHash: 'hash2',
        );

        $array = $evidence->toArray();

        self::assertSame('2026-02-17T10:00:00+00:00', $array['timestamp']);
        self::assertSame('marketing', $array['purpose']);
        self::assertSame('v1', $array['policy_version']);
        self::assertSame('en', $array['locale']);
        self::assertSame('user-1', $array['subject']);
        self::assertSame('req-1', $array['correlation_id']);
        self::assertSame('hash1', $array['template_hash']);
        self::assertSame('hash2', $array['policy_text_hash']);
    }

    #[Test]
    public function data_processing_agreement_tracks_acceptance(): void
    {
        $field = new DataProcessingAgreement('dpa', 'DPA', 'data_processing', 'v2', 'DPA text');

        self::assertFalse($field->isAccepted());
        $field->setValue(true);
        self::assertTrue($field->isAccepted());
    }

    #[Test]
    public function age_verification_has_minimum_age(): void
    {
        $field = new AgeVerification('age', 'Age Check', 'age_verification', 'v1', 'You must be 18+', minimumAge: 21);

        self::assertSame(21, $field->getMinimumAge());
        self::assertFalse($field->isVerified());
        $field->setValue(true);
        self::assertTrue($field->isVerified());
    }

    #[Test]
    public function signature_field_captures_typed_name(): void
    {
        $field = new SignatureField('sig', 'Signature', 'legal_signature', 'v1', 'By signing...');

        self::assertFalse($field->isSigned());
        self::assertSame('', $field->getSignature());

        $field->setValue('John Doe');
        self::assertTrue($field->isSigned());
        self::assertSame('John Doe', $field->getSignature());
    }

    #[Test]
    public function policy_text_hash_changes_when_text_changes(): void
    {
        $field1 = new ConsentCheckbox('consent', 'Consent', 'purpose', 'v1', 'Original text');
        $field2 = new ConsentCheckbox('consent', 'Consent', 'purpose', 'v1', 'Modified text');

        $evidence1 = $field1->captureEvidence('en', 'user', 'req', 'tmpl');
        $evidence2 = $field2->captureEvidence('en', 'user', 'req', 'tmpl');

        self::assertNotSame($evidence1->policyTextHash, $evidence2->policyTextHash);
    }
}
