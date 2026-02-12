<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Field\Regulated;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\Regulated\AgeVerification;

final class AgeVerificationTest extends TestCase
{
    #[Test]
    public function minimumAgeDefaultsToEighteen(): void
    {
        $field = new AgeVerification('age', 'Age Check', 'age_verification', 'v1', 'You must be 18+');

        self::assertSame(18, $field->getMinimumAge());
    }

    #[Test]
    public function customMinimumAge(): void
    {
        $field = new AgeVerification('age', 'Age Check', 'age_verification', 'v1', 'You must be 21+', minimumAge: 21);

        self::assertSame(21, $field->getMinimumAge());
    }

    #[Test]
    public function isVerifiedReturnsTrueWhenValueIsTruthy(): void
    {
        $field = new AgeVerification('age', 'Age Check', 'age_verification', 'v1', 'Policy');
        $field->setValue(true);

        self::assertTrue($field->isVerified());
    }

    #[Test]
    public function isVerifiedReturnsFalseByDefault(): void
    {
        $field = new AgeVerification('age', 'Age Check', 'age_verification', 'v1', 'Policy');

        self::assertFalse($field->isVerified());
    }
}
