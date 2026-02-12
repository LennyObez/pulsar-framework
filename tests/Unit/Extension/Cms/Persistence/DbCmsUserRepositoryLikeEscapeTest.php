<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Internal\Persistence\DbCmsUserRepository;

#[CoversClass(DbCmsUserRepository::class)]
final class DbCmsUserRepositoryLikeEscapeTest extends TestCase
{
    #[Test]
    public function list_users_escapes_like_special_characters_in_role_filter(): void
    {
        /** @var list<array<string, mixed>> $capturedBindings */
        $capturedBindings = [];

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$capturedBindings): Result {
                $capturedBindings[] = $bindings;

                // First call = count query, second = select query
                return new Result([new Row([
                    'total' => 0,
                    'id' => 'u1',
                    'tenant_id' => null,
                    'display_name' => 'Test',
                    'email' => 'test@example.com',
                    'roles' => '["cms.editor"]',
                    'two_factor_status' => 'disabled',
                    'last_active_at' => null,
                    'created_at' => '2025-01-01 00:00:00',
                    'is_locked' => 0,
                    'content_count' => 0,
                    'comment_count' => 0,
                ])]);
            });

        $repository = new DbCmsUserRepository($db);
        $repository->listUsers(role: 'cms.editor%admin');

        self::assertCount(2, $capturedBindings);

        // Both the count and select queries receive the same escaped binding
        self::assertSame('%cms.editor\%admin%', $capturedBindings[0]['role_pattern']);
        self::assertSame('%cms.editor\%admin%', $capturedBindings[1]['role_pattern']);
    }

    #[Test]
    public function list_users_escapes_underscore_in_role_filter(): void
    {
        /** @var list<array<string, mixed>> $capturedBindings */
        $capturedBindings = [];

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$capturedBindings): Result {
                $capturedBindings[] = $bindings;

                return new Result([new Row([
                    'total' => 0,
                    'id' => 'u1',
                    'tenant_id' => null,
                    'display_name' => 'Test',
                    'email' => 'test@example.com',
                    'roles' => '["cms.editor"]',
                    'two_factor_status' => 'disabled',
                    'last_active_at' => null,
                    'created_at' => '2025-01-01 00:00:00',
                    'is_locked' => 0,
                    'content_count' => 0,
                    'comment_count' => 0,
                ])]);
            });

        $repository = new DbCmsUserRepository($db);
        $repository->listUsers(role: 'cms_editor');

        self::assertSame('%cms\_editor%', $capturedBindings[0]['role_pattern']);
    }

    #[Test]
    public function list_users_escapes_backslash_in_role_filter(): void
    {
        /** @var list<array<string, mixed>> $capturedBindings */
        $capturedBindings = [];

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$capturedBindings): Result {
                $capturedBindings[] = $bindings;

                return new Result([new Row([
                    'total' => 0,
                    'id' => 'u1',
                    'tenant_id' => null,
                    'display_name' => 'Test',
                    'email' => 'test@example.com',
                    'roles' => '["cms.editor"]',
                    'two_factor_status' => 'disabled',
                    'last_active_at' => null,
                    'created_at' => '2025-01-01 00:00:00',
                    'is_locked' => 0,
                    'content_count' => 0,
                    'comment_count' => 0,
                ])]);
            });

        $repository = new DbCmsUserRepository($db);
        $repository->listUsers(role: 'cms\\admin');

        self::assertSame('%cms\\\\admin%', $capturedBindings[0]['role_pattern']);
    }
}
