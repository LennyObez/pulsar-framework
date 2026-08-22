<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Posture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\SecurityConfig;
use Pulsar\Core\Wiring\Contract\DegradedFeature;
use Pulsar\Security\Posture\SecurityPostureCheck;
use Pulsar\Security\Posture\SecurityPostureItem;
use Pulsar\Security\Posture\SecurityPostureStatus;

use function array_filter;
use function array_values;

#[CoversClass(SecurityPostureCheck::class)]
#[CoversClass(SecurityPostureItem::class)]
final class SecurityPostureCheckTest extends TestCase
{
    private const string STRONG_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function tearDown(): void
    {
        putenv('APP_ENV');
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $csrf
     * @param array<string, mixed> $hsts
     */
    private function config(array $session = [], array $csrf = ['enabled' => true], array $hsts = ['enabled' => true, 'max_age' => 63_072_000]): SecurityConfig
    {
        return SecurityConfig::fromArray([
            'session' => [
                'encryption' => true,
                'cookie_secure' => true,
                'cookie_httponly' => true,
                'cookie_samesite' => 'Strict',
                ...$session,
            ],
            'csrf' => $csrf,
            'headers' => ['hsts' => $hsts],
            'rate_limiting' => ['enabled' => false],
        ], Environment::load());
    }

    private function item(\Pulsar\Security\Posture\SecurityPostureReport $report, string $name): SecurityPostureItem
    {
        $matches = array_values(array_filter(
            $report->items,
            static fn(SecurityPostureItem $i): bool => $i->name === $name,
        ));

        self::assertNotEmpty($matches, "Expected a posture item named {$name}");

        return $matches[0];
    }

    #[Test]
    public function aFullyConfiguredProductionPostureHasNoFailures(): void
    {
        $check = new SecurityPostureCheck($this->config(), isProduction: true, debugMode: false, masterKey: self::STRONG_KEY);

        $report = $check->evaluate();

        self::assertFalse($report->hasFailures(), 'A correctly configured production posture should not fail');
        self::assertSame(SecurityPostureStatus::Ok, $this->item($report, 'csrf_protection')->status);
        self::assertSame(SecurityPostureStatus::Ok, $this->item($report, 'master_key')->status);
    }

    #[Test]
    public function httpsHstsFailsInProductionWhenNeitherEnabledNorEdgeTerminated(): void
    {
        $check = new SecurityPostureCheck(
            $this->config(hsts: ['enabled' => false]),
            isProduction: true,
            debugMode: false,
            masterKey: self::STRONG_KEY,
        );

        self::assertSame(SecurityPostureStatus::Fail, $this->item($check->evaluate(), 'https_hsts')->status);
    }

    #[Test]
    public function httpsHstsPassesWhenTerminatedAtTheEdge(): void
    {
        // App emits no HSTS header (enabled=false) but the edge asserts it:
        // the posture must be OK, not a false HTTP-only failure on every request.
        $check = new SecurityPostureCheck(
            $this->config(hsts: ['enabled' => false, 'emitted_at_edge' => true]),
            isProduction: true,
            debugMode: false,
            masterKey: self::STRONG_KEY,
        );

        self::assertSame(SecurityPostureStatus::Ok, $this->item($check->evaluate(), 'https_hsts')->status);
    }

    #[Test]
    public function aDegradedSecurityFeatureIsReportedAsFail(): void
    {
        // The acceptance case: a captcha whose single-use replay cache is unbound
        // (TaggedCacheInterface missing) is inert and MUST be a hard FAIL.
        $degraded = new DegradedFeature(
            component: 'AntiSpam',
            feature: 'Managed challenge single-use replay protection',
            missingBinding: 'Pulsar\\Cache\\Application\\TaggedCacheInterface',
            fix: 'Enable the cache so CacheWiring binds TaggedCacheInterface',
            security: true,
        );

        $check = new SecurityPostureCheck($this->config(), isProduction: true, debugMode: false, masterKey: self::STRONG_KEY, degradedSecurityFeatures: [$degraded]);

        $report = $check->evaluate();

        self::assertTrue($report->hasFailures());
        $item = $this->item($report, 'feature:AntiSpam.Managed challenge single-use replay protection');
        self::assertSame(SecurityPostureStatus::Fail, $item->status);
        self::assertStringContainsString('TaggedCacheInterface', $item->reason);
    }

    #[Test]
    public function csrfDisabledFailsInProductionButOnlyDegradesInDev(): void
    {
        $prod = new SecurityPostureCheck($this->config(csrf: ['enabled' => false]), isProduction: true, debugMode: false, masterKey: self::STRONG_KEY);
        self::assertSame(SecurityPostureStatus::Fail, $this->item($prod->evaluate(), 'csrf_protection')->status);

        $dev = new SecurityPostureCheck($this->config(csrf: ['enabled' => false]), isProduction: false, debugMode: true, masterKey: self::STRONG_KEY);
        self::assertSame(SecurityPostureStatus::Degraded, $this->item($dev->evaluate(), 'csrf_protection')->status);
    }

    #[Test]
    public function missingMasterKeyFailsInProduction(): void
    {
        $check = new SecurityPostureCheck($this->config(), isProduction: true, debugMode: false, masterKey: null);

        self::assertSame(SecurityPostureStatus::Fail, $this->item($check->evaluate(), 'master_key')->status);
    }

    #[Test]
    public function debugModeFailsInProductionButIsAcceptedInDev(): void
    {
        $prod = new SecurityPostureCheck($this->config(), isProduction: true, debugMode: true, masterKey: self::STRONG_KEY);
        self::assertSame(SecurityPostureStatus::Fail, $this->item($prod->evaluate(), 'debug_mode')->status);

        $dev = new SecurityPostureCheck($this->config(), isProduction: false, debugMode: true, masterKey: self::STRONG_KEY);
        self::assertSame(SecurityPostureStatus::Ok, $this->item($dev->evaluate(), 'debug_mode')->status);
    }
}
