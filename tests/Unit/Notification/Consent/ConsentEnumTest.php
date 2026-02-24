<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Consent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Consent\ConsentSource;
use Pulsar\Notification\Consent\ConsentType;
use Pulsar\Notification\Consent\LegalBasis;
use Pulsar\Notification\Consent\NotificationClassification;

#[CoversClass(ConsentSource::class)]
#[CoversClass(ConsentType::class)]
#[CoversClass(LegalBasis::class)]
#[CoversClass(NotificationClassification::class)]
final class ConsentEnumTest extends TestCase
{
    #[Test]
    public function consentSourceValues(): void
    {
        self::assertSame('user_action', ConsentSource::UserAction->value);
        self::assertSame('api', ConsentSource::Api->value);
        self::assertSame('import', ConsentSource::Import->value);
    }

    #[Test]
    public function consentTypeValues(): void
    {
        self::assertSame('opt_in', ConsentType::OptIn->value);
        self::assertSame('opt_out', ConsentType::OptOut->value);
    }

    #[Test]
    public function legalBasisValues(): void
    {
        self::assertSame('consent', LegalBasis::Consent->value);
        self::assertSame('legitimate_interest', LegalBasis::LegitimateInterest->value);
        self::assertSame('legal_obligation', LegalBasis::LegalObligation->value);
        self::assertSame('vital_interest', LegalBasis::VitalInterest->value);
        self::assertSame('public_task', LegalBasis::PublicTask->value);
        self::assertSame('contract', LegalBasis::Contract->value);
    }

    #[Test]
    public function notificationClassificationValues(): void
    {
        self::assertSame('transactional', NotificationClassification::Transactional->value);
        self::assertSame('marketing', NotificationClassification::Marketing->value);
    }
}
