<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\FeatureFlag;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagType;

#[CoversClass(FlagDefinition::class)]
final class FlagDefinitionCoverageTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $flag = new FlagDefinition(
            name: 'test-flag',
            enabled: true,
            type: FlagType::Percentage,
            percentage: 50,
            allowedTenants: ['acme'],
            allowedUsers: ['user-1'],
            allowedEnvironments: ['production'],
            description: 'A test flag',
        );

        self::assertSame('test-flag', $flag->name);
        self::assertTrue($flag->enabled);
        self::assertSame(FlagType::Percentage, $flag->type);
        self::assertSame(50, $flag->percentage);
        self::assertSame(['acme'], $flag->allowedTenants);
        self::assertSame(['user-1'], $flag->allowedUsers);
        self::assertSame(['production'], $flag->allowedEnvironments);
        self::assertSame('A test flag', $flag->description);
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $flag = new FlagDefinition(name: 'minimal', enabled: false);

        self::assertSame(FlagType::Boolean, $flag->type);
        self::assertSame(100, $flag->percentage);
        self::assertSame([], $flag->allowedTenants);
        self::assertSame([], $flag->allowedUsers);
        self::assertSame([], $flag->allowedEnvironments);
        self::assertSame('', $flag->description);
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $flag = FlagDefinition::fromArray('feature-x', [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 75,
            'allowed_tenants' => ['acme', 'globex'],
            'allowed_users' => ['user-1', 'user-2'],
            'allowed_environments' => ['staging', 'production'],
            'description' => 'Feature X rollout',
        ]);

        self::assertSame('feature-x', $flag->name);
        self::assertTrue($flag->enabled);
        self::assertSame(FlagType::Percentage, $flag->type);
        self::assertSame(75, $flag->percentage);
        self::assertSame(['acme', 'globex'], $flag->allowedTenants);
        self::assertSame(['user-1', 'user-2'], $flag->allowedUsers);
        self::assertSame(['staging', 'production'], $flag->allowedEnvironments);
        self::assertSame('Feature X rollout', $flag->description);
    }

    #[Test]
    public function fromArrayWithMinimalData(): void
    {
        $flag = FlagDefinition::fromArray('bare', []);

        self::assertSame('bare', $flag->name);
        self::assertFalse($flag->enabled);
        self::assertSame(FlagType::Boolean, $flag->type);
        self::assertSame(100, $flag->percentage);
        self::assertSame([], $flag->allowedTenants);
        self::assertSame([], $flag->allowedUsers);
        self::assertSame([], $flag->allowedEnvironments);
        self::assertSame('', $flag->description);
    }

    #[Test]
    public function fromArrayWithContextualType(): void
    {
        $flag = FlagDefinition::fromArray('ctx', [
            'enabled' => true,
            'type' => 'contextual',
            'allowed_tenants' => ['acme'],
        ]);

        self::assertSame(FlagType::Contextual, $flag->type);
        self::assertSame(['acme'], $flag->allowedTenants);
    }

    #[Test]
    public function toArrayProducesSerializableOutput(): void
    {
        $flag = new FlagDefinition(
            name: 'test',
            enabled: true,
            type: FlagType::Percentage,
            percentage: 50,
            allowedTenants: ['acme'],
            allowedUsers: ['user-1'],
            allowedEnvironments: ['prod'],
            description: 'Test flag',
        );

        $array = $flag->toArray();

        self::assertTrue($array['enabled']);
        self::assertSame('percentage', $array['type']);
        self::assertSame(50, $array['percentage']);
        self::assertSame(['acme'], $array['allowed_tenants']);
        self::assertSame(['user-1'], $array['allowed_users']);
        self::assertSame(['prod'], $array['allowed_environments']);
        self::assertSame('Test flag', $array['description']);
    }

    #[Test]
    public function roundTripFromArrayToArray(): void
    {
        $original = [
            'enabled' => true,
            'type' => 'contextual',
            'percentage' => 100,
            'allowed_tenants' => ['t1', 't2'],
            'allowed_users' => ['u1'],
            'allowed_environments' => ['prod'],
            'description' => 'Round trip test',
        ];

        $flag = FlagDefinition::fromArray('rt', $original);
        $output = $flag->toArray();

        self::assertSame($original, $output);
    }

    #[Test]
    public function fromArrayCoercesEnabledToBoolean(): void
    {
        $flag = FlagDefinition::fromArray('coerce', ['enabled' => 1]);

        self::assertTrue($flag->enabled);

        $flag2 = FlagDefinition::fromArray('coerce2', ['enabled' => 0]);
        self::assertFalse($flag2->enabled);
    }
}
