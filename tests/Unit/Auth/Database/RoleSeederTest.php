<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Database\RoleSeeder;
use Pulsar\Database\ConnectionInterface;

use function count;

#[CoversClass(RoleSeeder::class)]
final class RoleSeederTest extends TestCase
{
    #[Test]
    public function definitionsReturnsFiveRoles(): void
    {
        $definitions = RoleSeeder::definitions();

        self::assertCount(5, $definitions);
    }

    #[Test]
    public function definitionsContainAllExpectedSlugs(): void
    {
        $definitions = RoleSeeder::definitions();
        $slugs = array_map(static fn(array $d): string => $d['slug'], $definitions);

        $expected = ['admin', 'moderator', 'trusted', 'member', 'guest'];

        foreach ($expected as $slug) {
            self::assertContains($slug, $slugs, "Missing expected role slug: $slug");
        }
    }

    #[Test]
    public function eachDefinitionHasRequiredFields(): void
    {
        $definitions = RoleSeeder::definitions();

        foreach ($definitions as $definition) {
            self::assertArrayHasKey('slug', $definition);
            self::assertArrayHasKey('name', $definition);
            self::assertArrayHasKey('description', $definition);
            self::assertArrayHasKey('permissions', $definition);
            self::assertNotEmpty($definition['slug']);
            self::assertNotEmpty($definition['name']);
            self::assertNotEmpty($definition['description']);
            self::assertIsArray($definition['permissions']);
            self::assertNotEmpty($definition['permissions'], "Role '{$definition['slug']}' should have at least one permission");
        }
    }

    #[Test]
    public function runRegistersAllRoles(): void
    {
        $registry = new InMemoryRoleRegistry();
        $seeder = new RoleSeeder($registry);
        $connection = $this->createStub(ConnectionInterface::class);

        $seeder->run($connection);

        self::assertNotNull($registry->findByName('admin'));
        self::assertNotNull($registry->findByName('moderator'));
        self::assertNotNull($registry->findByName('trusted'));
        self::assertNotNull($registry->findByName('member'));
        self::assertNotNull($registry->findByName('guest'));
    }

    #[Test]
    public function adminRoleHasWildcardPermission(): void
    {
        $registry = new InMemoryRoleRegistry();
        $seeder = new RoleSeeder($registry);
        $connection = $this->createStub(ConnectionInterface::class);

        $seeder->run($connection);

        $admin = $registry->findByName('admin');
        self::assertNotNull($admin);
        self::assertTrue($admin->hasPermission('anything.at.all'));
        self::assertTrue($admin->hasPermission('content.delete'));
    }

    #[Test]
    public function moderatorRoleHasContentAndModerationPermissions(): void
    {
        $registry = new InMemoryRoleRegistry();
        $seeder = new RoleSeeder($registry);
        $connection = $this->createStub(ConnectionInterface::class);

        $seeder->run($connection);

        $moderator = $registry->findByName('moderator');
        self::assertNotNull($moderator);
        self::assertTrue($moderator->hasPermission('content.create'));
        self::assertTrue($moderator->hasPermission('content.moderate'));
        self::assertTrue($moderator->hasPermission('comments.moderate'));
        self::assertTrue($moderator->hasPermission('users.ban'));
    }

    #[Test]
    public function memberRoleLacksModerateAndDeletePermissions(): void
    {
        $registry = new InMemoryRoleRegistry();
        $seeder = new RoleSeeder($registry);
        $connection = $this->createStub(ConnectionInterface::class);

        $seeder->run($connection);

        $member = $registry->findByName('member');
        self::assertNotNull($member);
        self::assertTrue($member->hasPermission('content.read'));
        self::assertTrue($member->hasPermission('comments.create'));
        self::assertFalse($member->hasPermission('content.create'));
        self::assertFalse($member->hasPermission('content.delete'));
        self::assertFalse($member->hasPermission('comments.moderate'));
    }

    #[Test]
    public function guestRoleIsReadOnly(): void
    {
        $registry = new InMemoryRoleRegistry();
        $seeder = new RoleSeeder($registry);
        $connection = $this->createStub(ConnectionInterface::class);

        $seeder->run($connection);

        $guest = $registry->findByName('guest');
        self::assertNotNull($guest);
        self::assertTrue($guest->hasPermission('content.read'));
        self::assertTrue($guest->hasPermission('comments.read'));
        self::assertTrue($guest->hasPermission('media.read'));
        self::assertFalse($guest->hasPermission('content.create'));
        self::assertFalse($guest->hasPermission('comments.create'));
        self::assertFalse($guest->hasPermission('profile.update'));
    }

    #[Test]
    public function trustedMemberHasExtendedPermissions(): void
    {
        $registry = new InMemoryRoleRegistry();
        $seeder = new RoleSeeder($registry);
        $connection = $this->createStub(ConnectionInterface::class);

        $seeder->run($connection);

        $trusted = $registry->findByName('trusted');
        self::assertNotNull($trusted);
        self::assertTrue($trusted->hasPermission('content.create'));
        self::assertTrue($trusted->hasPermission('content.update'));
        self::assertTrue($trusted->hasPermission('media.upload'));
        self::assertFalse($trusted->hasPermission('content.delete'));
        self::assertFalse($trusted->hasPermission('users.ban'));
    }

    #[Test]
    public function permissionsAreProperlyEscalated(): void
    {
        $definitions = RoleSeeder::definitions();

        // Build a map of slug => permission count
        $permCounts = [];
        foreach ($definitions as $def) {
            $permCounts[$def['slug']] = count($def['permissions']);
        }

        // Admin has wildcard so only 1 entry, but conceptually the most privileged
        // For non-admin roles: moderator > trusted > member > guest
        self::assertGreaterThan($permCounts['trusted'], $permCounts['moderator']);
        self::assertGreaterThan($permCounts['member'], $permCounts['trusted']);
        self::assertGreaterThan($permCounts['guest'], $permCounts['member']);
    }
}
