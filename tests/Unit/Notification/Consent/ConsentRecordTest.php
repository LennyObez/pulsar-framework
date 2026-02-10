<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Consent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Consent\ConsentRecord;
use Pulsar\Notification\Consent\ConsentSource;
use Pulsar\Notification\Consent\ConsentType;
use Pulsar\Notification\Consent\LegalBasis;
use ReflectionClass;

#[CoversClass(ConsentRecord::class)]
final class ConsentRecordTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_fields(): void
    {
        $record = new ConsentRecord(
            timestamp: 1700000000,
            consentType: ConsentType::OptIn,
            channel: 'mail',
            legalBasis: LegalBasis::Consent,
            source: ConsentSource::UserAction,
            ipAddressHash: 'hashed_ip',
        );

        self::assertSame(1700000000, $record->timestamp);
        self::assertSame(ConsentType::OptIn, $record->consentType);
        self::assertSame('mail', $record->channel);
        self::assertSame(LegalBasis::Consent, $record->legalBasis);
        self::assertSame(ConsentSource::UserAction, $record->source);
        self::assertSame('hashed_ip', $record->ipAddressHash);
    }

    #[Test]
    public function it_constructs_without_ip_hash(): void
    {
        $record = new ConsentRecord(
            timestamp: 1700000000,
            consentType: ConsentType::OptOut,
            channel: 'sms',
            legalBasis: LegalBasis::LegitimateInterest,
            source: ConsentSource::Api,
        );

        self::assertNull($record->ipAddressHash);
        self::assertSame(ConsentType::OptOut, $record->consentType);
        self::assertSame(ConsentSource::Api, $record->source);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $reflection = new ReflectionClass(ConsentRecord::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
