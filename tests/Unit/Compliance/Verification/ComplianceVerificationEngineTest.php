<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\Evidence\EvidenceRecord;
use Pulsar\Compliance\Evidence\EvidenceStoreInterface;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;
use Pulsar\Compliance\Verification\ComplianceCheckInterface;
use Pulsar\Compliance\Verification\ComplianceRegressionException;
use Pulsar\Compliance\Verification\ComplianceVerificationEngine;
use Pulsar\Compliance\Verification\ConflictDetector;
use Pulsar\Compliance\Verification\CustomControlRegistry;
use Pulsar\Compliance\Verification\DataPathVerifier;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\RegressionDetector;
use Pulsar\Compliance\Verification\RegressionInput;
use Pulsar\Compliance\Verification\RuntimeVerifier;
use Pulsar\Compliance\Verification\VerificationConfig;
use RuntimeException;
use Stringable;

use function count;

#[CoversClass(ComplianceVerificationEngine::class)]
final class ComplianceVerificationEngineTest extends TestCase
{
    public function testVerifyRunsAllChecks(): void
    {
        $profile = $this->createProfile();

        $pluggableCheck = $this->createStub(ComplianceCheckInterface::class);
        $pluggableCheck->method('execute')->willReturn(
            CheckResult::pass('pluggable.check', 'ok', ComplianceCheckDomain::Encryption),
        );

        $engine = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile, true, true, true, true),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(),
            checks: [$pluggableCheck],
        );

        $report = $engine->verify();

        // Should have pluggable check result + runtime results
        self::assertGreaterThan(1, $report->totalCount());
        self::assertSame($profile->enabledFrameworks, $report->frameworks);
        self::assertNotNull($report->generatedAt);
    }

    public function testVerifyWithRoutesIncludesDataPathResults(): void
    {
        $profile = $this->createProfile();

        $engine = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile, true, true, true, true),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(),
        );

        $dataPathVerifier = new DataPathVerifier($profile);

        $routes = [
            ['path' => '/api/payments', 'classification' => 'pci', 'middleware' => ['encryption', 'authentication', 'audit', 'csrf']],
        ];

        $report = $engine->verifyWithRoutes($routes, $dataPathVerifier);

        // Should include data path result
        $datapathResults = array_filter(
            $report->results,
            static fn(CheckResult $r): bool => str_starts_with($r->checkId, 'datapath.'),
        );
        self::assertNotEmpty($datapathResults);
    }

    public function testVerifyWithRegressionsDetectsViolations(): void
    {
        $profile = $this->createProfile();

        $engine = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile, true, true, true, true),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(strictMode: false),
        );

        $regressionDetector = new RegressionDetector($profile);
        $input = new RegressionInput(
            sessionIdleTimeout: 7200,
            passwordMinLength: 6,
            hstsMaxAge: 0,
            encryptionAtRest: false,
            mfaScope: 'none',
            tamperEvidentAudit: false,
        );

        $report = $engine->verifyWithRegressions($regressionDetector, $input);

        self::assertTrue($report->hasRegressions());
        self::assertGreaterThan(0, count($report->regressions));
    }

    public function testVerifyWithRegressionsThrowsInStrictMode(): void
    {
        $profile = $this->createProfile();

        $engine = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile, true, true, true, true),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(strictMode: true),
        );

        $regressionDetector = new RegressionDetector($profile);
        $input = new RegressionInput(
            sessionIdleTimeout: 7200,
            passwordMinLength: 6,
            hstsMaxAge: 0,
            encryptionAtRest: false,
            mfaScope: 'none',
            tamperEvidentAudit: false,
        );

        $this->expectException(ComplianceRegressionException::class);
        (void) $engine->verifyWithRegressions($regressionDetector, $input);
    }

    public function testIsEnabled(): void
    {
        $profile = $this->createProfile();

        $engineEnabled = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(enabled: true),
        );

        $engineDisabled = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(enabled: false),
        );

        self::assertTrue($engineEnabled->isEnabled());
        self::assertFalse($engineDisabled->isEnabled());
    }

    public function testShouldCheckAtBoot(): void
    {
        $profile = $this->createProfile();

        $engine1 = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(enabled: true, bootCheck: true),
        );

        $engine2 = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(enabled: true, bootCheck: false),
        );

        $engine3 = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(enabled: false, bootCheck: true),
        );

        self::assertTrue($engine1->shouldCheckAtBoot());
        self::assertFalse($engine2->shouldCheckAtBoot());
        self::assertFalse($engine3->shouldCheckAtBoot());
    }

    public function testVerifyWithCustomControls(): void
    {
        $profile = $this->createProfile();
        $registry = new CustomControlRegistry();
        $registry->register(new \Pulsar\Compliance\Verification\CustomControl(
            id: 'org.backup',
            name: 'Backup',
            description: 'Check backups',
            verifier: static fn(): CheckResult => CheckResult::pass('org.backup', 'Backups verified'),
        ));

        $engine = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile, true, true, true, true),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: $registry,
            config: new VerificationConfig(),
        );

        $report = $engine->verify();

        $customResults = array_filter(
            $report->results,
            static fn(CheckResult $r): bool => $r->checkId === 'org.backup',
        );
        self::assertCount(1, $customResults);
    }

    public function testVerifyDetectsConflicts(): void
    {
        $profile = new ComplianceProfile(
            enabledFrameworks: [ComplianceFramework::Gdpr, ComplianceFramework::PciDss],
            passwordMinLength: 12,
            sessionIdleTimeout: 900,
            breachNotificationHours: 72,
            auditRetentionDays: 365,
            dataRetentionDays: 2190,
            mfaRequirement: 'always',
            encryptionAtRest: true,
            encryptionInTransit: true,
            tamperEvidentAudit: true,
            explicitConsent: true,
            consentWithdrawal: true,
            individualNotification: true,
            breachRegister: true,
        );

        $engine = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile, true, true, true, true),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(),
        );

        $report = $engine->verify();

        self::assertTrue($report->hasConflicts());
        self::assertGreaterThan(0, count($report->conflicts));
    }

    public function testEvidenceChainFailurePreservesReportAndIsLogged(): void
    {
        $profile = $this->createProfile();

        // A store whose store() throws makes EvidenceChain::record() throw.
        $throwingStore = new class implements EvidenceStoreInterface {
            public function store(EvidenceRecord $record): void
            {
                throw new RuntimeException('evidence store unavailable');
            }

            /** @return list<EvidenceRecord> */
            public function forControl(string $controlId): array
            {
                return [];
            }

            /** @return list<EvidenceRecord> */
            public function all(): array
            {
                return [];
            }

            public function get(string $id): ?EvidenceRecord
            {
                return null;
            }

            public function countForControl(string $controlId): int
            {
                return 0;
            }
        };

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            /**
             * @param array<array-key, mixed> $context
             */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->errors[] = (string) $message;
            }
        };

        $engine = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile, true, true, true, true),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(),
            evidenceChain: new EvidenceChain($throwingStore, 'test-evidence-key-long-enough-for-blake2b'),
            logger: $logger,
        );

        // The already-built report must be returned despite the recording failure.
        $report = $engine->verify();

        self::assertGreaterThan(0, $report->totalCount());
        self::assertNotEmpty($logger->errors);
    }

    private function createProfile(): ComplianceProfile
    {
        return new ComplianceProfile(
            enabledFrameworks: [ComplianceFramework::Gdpr],
            passwordMinLength: 12,
            sessionIdleTimeout: 900,
            breachNotificationHours: 72,
            auditRetentionDays: 365,
            dataRetentionDays: 2190,
            mfaRequirement: 'always',
            encryptionAtRest: true,
            encryptionInTransit: true,
            tamperEvidentAudit: true,
            explicitConsent: true,
            consentWithdrawal: true,
            individualNotification: true,
            breachRegister: true,
        );
    }
}
