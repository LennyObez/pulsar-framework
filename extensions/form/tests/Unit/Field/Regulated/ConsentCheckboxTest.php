<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Field\Regulated;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\Regulated\ConsentCheckbox;

final class ConsentCheckboxTest extends TestCase
{
    #[Test]
    public function typeReturnsCheckbox(): void
    {
        $field = new ConsentCheckbox('consent', 'I agree', 'marketing', 'v1.0', 'Policy text');

        self::assertSame('checkbox', $field->getType());
    }

    #[Test]
    public function isConsentedReturnsTrueWhenValueIsTruthy(): void
    {
        $field = new ConsentCheckbox('consent', 'I agree', 'marketing', 'v1.0', 'Policy text');
        $field->setValue(true);

        self::assertTrue($field->isConsented());
    }

    #[Test]
    public function isConsentedReturnsFalseWhenValueIsFalsy(): void
    {
        $field = new ConsentCheckbox('consent', 'I agree', 'marketing', 'v1.0', 'Policy text');

        self::assertFalse($field->isConsented());
    }

    #[Test]
    public function regulatedFieldExposesComplianceMetadata(): void
    {
        $field = new ConsentCheckbox('gdpr', 'GDPR Consent', 'data_processing', 'v2.1', 'We process your data...');

        self::assertSame('data_processing', $field->purpose);
        self::assertSame('v2.1', $field->policyVersion);
        self::assertSame('We process your data...', $field->policyText);
    }

    #[Test]
    public function evidenceIsNullBeforeCapture(): void
    {
        $field = new ConsentCheckbox('consent', 'I agree', 'marketing', 'v1.0', 'Policy text');

        self::assertNull($field->getEvidence());
    }
}
