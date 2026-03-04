<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\TypeBridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\TypeBridge\ParameterDefinition;
use Pulsar\Api\TypeBridge\RouteDefinition;

#[CoversClass(RouteDefinition::class)]
final class RouteDefinitionTest extends TestCase
{
    #[Test]
    public function simple_route_signature(): void
    {
        $route = new RouteDefinition(
            name: 'health',
            method: 'GET',
            path: '/health',
            responseType: 'HealthStatus',
        );

        $sig = $route->toTypeScriptSignature();

        self::assertSame('health(): Promise<HealthStatus>', $sig);
    }

    #[Test]
    public function route_with_parameters(): void
    {
        $route = new RouteDefinition(
            name: 'users.show',
            method: 'GET',
            path: '/users/{id}',
            parameters: [
                new ParameterDefinition('id', 'number'),
            ],
            responseType: 'User',
        );

        $sig = $route->toTypeScriptSignature();

        self::assertSame('usersShow(id: number): Promise<User>', $sig);
    }

    #[Test]
    public function route_with_request_body(): void
    {
        $route = new RouteDefinition(
            name: 'users.create',
            method: 'POST',
            path: '/users',
            requestType: 'CreateUserRequest',
            responseType: 'User',
        );

        $sig = $route->toTypeScriptSignature();

        self::assertSame('usersCreate(data: CreateUserRequest): Promise<User>', $sig);
    }

    #[Test]
    public function route_with_optional_parameter(): void
    {
        $route = new RouteDefinition(
            name: 'users.list',
            method: 'GET',
            path: '/users',
            parameters: [
                new ParameterDefinition('page', 'number', optional: true),
            ],
            responseType: 'UserList',
        );

        $sig = $route->toTypeScriptSignature();

        self::assertStringContainsString('page: number | undefined', $sig);
    }

    #[Test]
    public function dotted_name_to_camel_case(): void
    {
        $route = new RouteDefinition(
            name: 'admin.users.roles',
            method: 'GET',
            path: '/admin/users/roles',
            responseType: 'RoleList',
        );

        $sig = $route->toTypeScriptSignature();

        self::assertStringStartsWith('adminUsersRoles(', $sig);
    }
}
