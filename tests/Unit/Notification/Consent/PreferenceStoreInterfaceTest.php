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
use Pulsar\Notification\Consent\PreferenceStoreInterface;
use Pulsar\Notification\Consent\UserPreferences;

#[CoversClass(PreferenceStoreInterface::class)]
final class PreferenceStoreInterfaceTest extends TestCase
{
    #[Test]
    public function getPreferencesReturnsUserPreferences(): void
    {
        $store = new class implements PreferenceStoreInterface {
            public function getPreferences(string $notifiableId): UserPreferences
            {
                return new UserPreferences(
                    notifiableId: $notifiableId,
                    channelPreferences: ['mail' => true, 'sms' => false],
                );
            }

            public function updatePreference(
                string $notifiableId,
                string $channel,
                ConsentType $consentType,
                LegalBasis $legalBasis,
                ConsentSource $source,
                ?string $ipHash = null,
            ): void {}

            public function getConsentHistory(string $notifiableId): array
            {
                return [];
            }
        };

        $prefs = $store->getPreferences('user-42');

        self::assertSame('user-42', $prefs->notifiableId);
        self::assertSame(['mail' => true, 'sms' => false], $prefs->channelPreferences);
    }

    #[Test]
    public function updatePreferenceReceivesAllParameters(): void
    {
        $store = new class implements PreferenceStoreInterface {
            /** @var array<string, mixed> */
            public array $receivedArgs = [];

            public function getPreferences(string $notifiableId): UserPreferences
            {
                return new UserPreferences(notifiableId: $notifiableId);
            }

            public function updatePreference(
                string $notifiableId,
                string $channel,
                ConsentType $consentType,
                LegalBasis $legalBasis,
                ConsentSource $source,
                ?string $ipHash = null,
            ): void {
                $this->receivedArgs = [
                    'notifiableId' => $notifiableId,
                    'channel' => $channel,
                    'consentType' => $consentType,
                    'legalBasis' => $legalBasis,
                    'source' => $source,
                    'ipHash' => $ipHash,
                ];
            }

            public function getConsentHistory(string $notifiableId): array
            {
                return [];
            }
        };

        $store->updatePreference(
            'user-1',
            'mail',
            ConsentType::OptIn,
            LegalBasis::Consent,
            ConsentSource::UserAction,
            'abc123',
        );

        self::assertSame('user-1', $store->receivedArgs['notifiableId']);
        self::assertSame('mail', $store->receivedArgs['channel']);
        self::assertSame(ConsentType::OptIn, $store->receivedArgs['consentType']);
        self::assertSame(LegalBasis::Consent, $store->receivedArgs['legalBasis']);
        self::assertSame(ConsentSource::UserAction, $store->receivedArgs['source']);
        self::assertSame('abc123', $store->receivedArgs['ipHash']);
    }

    #[Test]
    public function updatePreferenceDefaultsIpHashToNull(): void
    {
        $store = new class implements PreferenceStoreInterface {
            public ?string $receivedIpHash = 'sentinel';

            public function getPreferences(string $notifiableId): UserPreferences
            {
                return new UserPreferences(notifiableId: $notifiableId);
            }

            public function updatePreference(
                string $notifiableId,
                string $channel,
                ConsentType $consentType,
                LegalBasis $legalBasis,
                ConsentSource $source,
                ?string $ipHash = null,
            ): void {
                $this->receivedIpHash = $ipHash;
            }

            public function getConsentHistory(string $notifiableId): array
            {
                return [];
            }
        };

        $store->updatePreference('u1', 'sms', ConsentType::OptOut, LegalBasis::LegitimateInterest, ConsentSource::Api);

        self::assertNull($store->receivedIpHash);
    }

    #[Test]
    public function getConsentHistoryReturnsConsentRecordList(): void
    {
        $record = new ConsentRecord(
            timestamp: 1700000000,
            consentType: ConsentType::OptIn,
            channel: 'mail',
            legalBasis: LegalBasis::Consent,
            source: ConsentSource::UserAction,
            ipAddressHash: null,
        );

        $store = new class ($record) implements PreferenceStoreInterface {
            public function __construct(private readonly ConsentRecord $record) {}

            public function getPreferences(string $notifiableId): UserPreferences
            {
                return new UserPreferences(notifiableId: $notifiableId);
            }

            public function updatePreference(
                string $notifiableId,
                string $channel,
                ConsentType $consentType,
                LegalBasis $legalBasis,
                ConsentSource $source,
                ?string $ipHash = null,
            ): void {}

            public function getConsentHistory(string $notifiableId): array
            {
                return [$this->record];
            }
        };

        $history = $store->getConsentHistory('user-1');

        self::assertCount(1, $history);
        self::assertSame(1700000000, $history[0]->timestamp);
        self::assertSame(ConsentType::OptIn, $history[0]->consentType);
    }
}
