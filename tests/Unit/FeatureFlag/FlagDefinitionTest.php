<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\FeatureFlag;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagType;

#[CoversClass(FlagDefinition::class)]
final class FlagDefinitionTest extends TestCase
{
    #[Test]
    public function constructionWithAllFields(): void
    {
        $flag = new FlagDefinition(
            name: 'dark-mode',
            enabled: true,
            type: FlagType::Contextual,
            percentage: 75,
            allowedTenants: ['acme', 'globex'],
            allowedUsers: ['user-1', 'user-2'],
            allowedEnvironments: ['production', 'staging'],
            description: 'Enable dark mode UI',
        );

        self::assertSame('dark-mode', $flag->name);
        self::assertTrue($flag->enabled);
        self::assertSame(FlagType::Contextual, $flag->type);
        self::assertSame(75, $flag->percentage);
        self::assertSame(['acme', 'globex'], $flag->allowedTenants);
        self::assertSame(['user-1', 'user-2'], $flag->allowedUsers);
        self::assertSame(['production', 'staging'], $flag->allowedEnvironments);
        self::assertSame('Enable dark mode UI', $flag->description);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $data = [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 50,
            'allowed_tenants' => ['acme'],
            'allowed_users' => ['user-42'],
            'allowed_environments' => ['production'],
            'description' => 'Rollout feature X',
        ];

        $flag = FlagDefinition::fromArray('feature-x', $data);

        self::assertSame('feature-x', $flag->name);
        self::assertTrue($flag->enabled);
        self::assertSame(FlagType::Percentage, $flag->type);
        self::assertSame(50, $flag->percentage);
        self::assertSame(['acme'], $flag->allowedTenants);
        self::assertSame(['user-42'], $flag->allowedUsers);
        self::assertSame(['production'], $flag->allowedEnvironments);
        self::assertSame('Rollout feature X', $flag->description);
    }

    #[Test]
    public function fromArrayWithMinimalDataAppliesDefaults(): void
    {
        $flag = FlagDefinition::fromArray('minimal-flag', []);

        self::assertSame('minimal-flag', $flag->name);
        self::assertFalse($flag->enabled);
        self::assertSame(FlagType::Boolean, $flag->type);
        self::assertSame(100, $flag->percentage);
        self::assertSame([], $flag->allowedTenants);
        self::assertSame([], $flag->allowedUsers);
        self::assertSame([], $flag->allowedEnvironments);
        self::assertSame('', $flag->description);
    }

    #[Test]
    public function toArrayRoundTripsCorrectly(): void
    {
        $original = new FlagDefinition(
            name: 'round-trip',
            enabled: true,
            type: FlagType::Contextual,
            percentage: 80,
            allowedTenants: ['tenant-a'],
            allowedUsers: ['user-b'],
            allowedEnvironments: ['staging'],
            description: 'Round-trip test',
        );

        $array = $original->toArray();
        $restored = FlagDefinition::fromArray('round-trip', $array);

        self::assertSame($original->name, $restored->name);
        self::assertSame($original->enabled, $restored->enabled);
        self::assertSame($original->type, $restored->type);
        self::assertSame($original->percentage, $restored->percentage);
        self::assertSame($original->allowedTenants, $restored->allowedTenants);
        self::assertSame($original->allowedUsers, $restored->allowedUsers);
        self::assertSame($original->allowedEnvironments, $restored->allowedEnvironments);
        self::assertSame($original->description, $restored->description);
    }
}
