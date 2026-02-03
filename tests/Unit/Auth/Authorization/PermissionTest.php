<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Permission;

#[CoversClass(Permission::class)]
final class PermissionTest extends TestCase
{
    #[Test]
    public function exactMatchWorks(): void
    {
        $permission = new Permission('users.create');

        self::assertTrue($permission->matches('users.create'));
    }

    #[Test]
    public function wildcardMatchesEverything(): void
    {
        $permission = new Permission('*');

        self::assertTrue($permission->matches('users.create'));
        self::assertTrue($permission->matches('posts.delete'));
        self::assertTrue($permission->matches('anything'));
    }

    #[Test]
    public function prefixWildcardMatchesWithinNamespace(): void
    {
        $permission = new Permission('users.*');

        self::assertTrue($permission->matches('users.create'));
        self::assertTrue($permission->matches('users.delete'));
    }

    #[Test]
    public function prefixWildcardDoesNotMatchDifferentNamespace(): void
    {
        $permission = new Permission('users.*');

        self::assertFalse($permission->matches('posts.create'));
    }

    #[Test]
    public function exactPermissionDoesNotMatchDifferentPermission(): void
    {
        $permission = new Permission('users.create');

        self::assertFalse($permission->matches('users.delete'));
    }
}
