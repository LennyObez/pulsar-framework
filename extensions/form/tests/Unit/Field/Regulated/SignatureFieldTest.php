<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Field\Regulated;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\Regulated\SignatureField;

final class SignatureFieldTest extends TestCase
{
    #[Test]
    public function typeReturnsText(): void
    {
        $field = new SignatureField('sig', 'Signature', 'legal', 'v1', 'Sign here');

        self::assertSame('text', $field->getType());
    }

    #[Test]
    public function getSignatureReturnsStringifiedValue(): void
    {
        $field = new SignatureField('sig', 'Signature', 'legal', 'v1', 'Policy');
        $field->setValue('John Doe');

        self::assertSame('John Doe', $field->getSignature());
    }

    #[Test]
    public function getSignatureReturnsEmptyStringWhenNull(): void
    {
        $field = new SignatureField('sig', 'Signature', 'legal', 'v1', 'Policy');

        self::assertSame('', $field->getSignature());
    }

    #[Test]
    public function isSignedReturnsTrueWhenValuePresent(): void
    {
        $field = new SignatureField('sig', 'Signature', 'legal', 'v1', 'Policy');
        $field->setValue('Jane');

        self::assertTrue($field->isSigned());
    }

    #[Test]
    public function isSignedReturnsFalseWhenEmpty(): void
    {
        $field = new SignatureField('sig', 'Signature', 'legal', 'v1', 'Policy');

        self::assertFalse($field->isSigned());
    }
}
