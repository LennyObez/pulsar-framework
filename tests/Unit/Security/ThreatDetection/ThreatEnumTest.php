<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatResponse;

#[CoversClass(ThreatResponse::class)]
#[CoversClass(ThreatCategory::class)]
final class ThreatEnumTest extends TestCase
{
    // ── ThreatResponse ────────────────────────────────────────────────

    #[Test]
    public function threatResponseHasFiveCases(): void
    {
        self::assertCount(5, ThreatResponse::cases());
    }

    #[Test]
    #[DataProvider('threatResponseProvider')]
    public function threatResponseBackedValues(ThreatResponse $response, string $expected): void
    {
        self::assertSame($expected, $response->value);
    }

    /**
     * @return iterable<string, array{ThreatResponse, string}>
     */
    public static function threatResponseProvider(): iterable
    {
        yield 'Block' => [ThreatResponse::Block, 'block'];
        yield 'RateLimit' => [ThreatResponse::RateLimit, 'rate_limit'];
        yield 'Challenge' => [ThreatResponse::Challenge, 'challenge'];
        yield 'Alert' => [ThreatResponse::Alert, 'alert'];
        yield 'Log' => [ThreatResponse::Log, 'log'];
    }

    #[Test]
    public function threatResponseFromBackedValue(): void
    {
        self::assertSame(ThreatResponse::Block, ThreatResponse::from('block'));
        self::assertSame(ThreatResponse::Log, ThreatResponse::from('log'));
    }

    // ── ThreatCategory ────────────────────────────────────────────────

    #[Test]
    public function threatCategoryHasElevenCases(): void
    {
        self::assertCount(11, ThreatCategory::cases());
    }

    #[Test]
    #[DataProvider('threatCategoryProvider')]
    public function threatCategoryBackedValues(ThreatCategory $category, string $expected): void
    {
        self::assertSame($expected, $category->value);
    }

    /**
     * @return iterable<string, array{ThreatCategory, string}>
     */
    public static function threatCategoryProvider(): iterable
    {
        yield 'BruteForce' => [ThreatCategory::BruteForce, 'brute_force'];
        yield 'CredentialStuffing' => [ThreatCategory::CredentialStuffing, 'credential_stuffing'];
        yield 'InjectionAttempt' => [ThreatCategory::InjectionAttempt, 'injection_attempt'];
        yield 'GeoAnomaly' => [ThreatCategory::GeoAnomaly, 'geo_anomaly'];
        yield 'ApiAbuse' => [ThreatCategory::ApiAbuse, 'api_abuse'];
        yield 'Reconnaissance' => [ThreatCategory::Reconnaissance, 'reconnaissance'];
        yield 'RequestTampering' => [ThreatCategory::RequestTampering, 'request_tampering'];
        yield 'DataLeak' => [ThreatCategory::DataLeak, 'data_leak'];
        yield 'SessionHijack' => [ThreatCategory::SessionHijack, 'session_hijack'];
        yield 'AccountTakeover' => [ThreatCategory::AccountTakeover, 'account_takeover'];
        yield 'BotTraffic' => [ThreatCategory::BotTraffic, 'bot_traffic'];
    }

    #[Test]
    public function threatCategoryFromBackedValue(): void
    {
        self::assertSame(ThreatCategory::BruteForce, ThreatCategory::from('brute_force'));
        self::assertSame(ThreatCategory::SessionHijack, ThreatCategory::from('session_hijack'));
        self::assertSame(ThreatCategory::BotTraffic, ThreatCategory::from('bot_traffic'));
    }
}
