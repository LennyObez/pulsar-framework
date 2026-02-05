<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\FeatureFlag\Exception\FeatureFlagException;
use Pulsar\FeatureFlag\FeatureFlagManagerInterface;
use Pulsar\FeatureFlag\FlagContext;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagEvaluation;
use Pulsar\FeatureFlag\FlagEvaluationReason;
use Pulsar\FeatureFlag\FlagStorageDriver;
use Pulsar\FeatureFlag\FlagStorageInterface;
use Pulsar\FeatureFlag\FlagType;

#[CoversClass(Api::class)]
final class FeatureFlagApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function featureFlagManagerInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(FeatureFlagManagerInterface::class);
    }

    #[Test]
    public function flagStorageInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(FlagStorageInterface::class);
    }

    #[Test]
    public function flagDefinitionIsPublicApi(): void
    {
        self::assertHasApiAttribute(FlagDefinition::class);
        self::assertClassIsReadonly(FlagDefinition::class);
    }

    #[Test]
    public function flagContextIsPublicApi(): void
    {
        self::assertHasApiAttribute(FlagContext::class);
        self::assertClassIsReadonly(FlagContext::class);
    }

    #[Test]
    public function flagEvaluationIsPublicApi(): void
    {
        self::assertHasApiAttribute(FlagEvaluation::class);
        self::assertClassIsReadonly(FlagEvaluation::class);
    }

    #[Test]
    public function flagEnumsArePublicApi(): void
    {
        self::assertHasApiAttribute(FlagEvaluationReason::class);
        self::assertHasApiAttribute(FlagStorageDriver::class);
        self::assertHasApiAttribute(FlagType::class);
    }

    #[Test]
    public function featureFlagExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(FeatureFlagException::class);
    }

    #[Test]
    public function featureFlagExceptionHasStaticFactories(): void
    {
        self::assertStaticFactoryExists(FeatureFlagException::class, 'storageError');
        self::assertStaticFactoryExists(FeatureFlagException::class, 'invalidDefinition');
    }
}
