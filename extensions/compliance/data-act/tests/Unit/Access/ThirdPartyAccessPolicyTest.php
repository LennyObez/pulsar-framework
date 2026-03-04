<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\Access\ThirdPartyAccessPolicy;
use Pulsar\Extension\DataAct\Config\DataActConfig;

#[CoversClass(ThirdPartyAccessPolicy::class)]
final class ThirdPartyAccessPolicyTest extends TestCase
{
    #[Test]
    public function grantsAccessForValidRequest(): void
    {
        $policy = $this->createPolicy(enabled: true);

        $result = $policy->evaluate('partner-a', 'user-1', 'analytics');

        self::assertTrue($result->granted);
        self::assertSame('partner-a', $result->requestingParty);
        self::assertSame('user-1', $result->dataSubjectId);
        self::assertSame('analytics', $result->purpose);
    }

    #[Test]
    public function deniesWhenDisabled(): void
    {
        $policy = $this->createPolicy(enabled: false);

        $result = $policy->evaluate('partner-a', 'user-1', 'analytics');

        self::assertFalse($result->granted);
        self::assertStringContainsString('not enabled', $result->reason);
    }

    #[Test]
    public function deniesEmptyRequestingParty(): void
    {
        $policy = $this->createPolicy(enabled: true);

        $result = $policy->evaluate('', 'user-1', 'analytics');

        self::assertFalse($result->granted);
        self::assertStringContainsString('must be identified', $result->reason);
    }

    #[Test]
    public function deniesEmptyDataSubject(): void
    {
        $policy = $this->createPolicy(enabled: true);

        $result = $policy->evaluate('partner-a', '', 'analytics');

        self::assertFalse($result->granted);
    }

    #[Test]
    public function deniesEmptyPurpose(): void
    {
        $policy = $this->createPolicy(enabled: true);

        $result = $policy->evaluate('partner-a', 'user-1', '');

        self::assertFalse($result->granted);
        self::assertStringContainsString('Art. 6(2)(b)', $result->reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deniedPurposeProvider(): iterable
    {
        yield 'profiling' => ['profiling'];
        yield 'advertising' => ['advertising'];
        yield 'surveillance' => ['surveillance'];
    }

    #[Test]
    #[DataProvider('deniedPurposeProvider')]
    public function deniesProhibitedPurpose(string $purpose): void
    {
        $policy = $this->createPolicy(enabled: true);

        $result = $policy->evaluate('partner-a', 'user-1', $purpose);

        self::assertFalse($result->granted);
        self::assertStringContainsString('prohibited', $result->reason);
        self::assertStringContainsString('Art. 6(2)(e)', $result->reason);
    }

    private function createPolicy(bool $enabled): ThirdPartyAccessPolicy
    {
        return new ThirdPartyAccessPolicy(new DataActConfig(enabled: $enabled));
    }
}
