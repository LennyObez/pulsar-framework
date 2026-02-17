<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\TypeBridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\TypeBridge\InterfaceDefinition;
use Pulsar\Api\TypeBridge\ParameterDefinition;
use Pulsar\Api\TypeBridge\PropertyDefinition;
use Pulsar\Api\TypeBridge\RouteDefinition;
use Pulsar\Api\TypeBridge\TypeScriptClientGenerator;

#[CoversClass(TypeScriptClientGenerator::class)]
final class TypeScriptClientGeneratorTest extends TestCase
{
    #[Test]
    public function generates_empty_client(): void
    {
        $generator = new TypeScriptClientGenerator(routes: []);
        $output = $generator->generate();

        self::assertStringContainsString('Auto-generated Pulsar API client', $output);
        self::assertStringContainsString('class PulsarApiClient', $output);
    }

    #[Test]
    public function generates_interfaces(): void
    {
        $generator = new TypeScriptClientGenerator(
            routes: [],
            interfaces: [
                new InterfaceDefinition('User', [
                    new PropertyDefinition('id', 'number'),
                    new PropertyDefinition('name', 'string'),
                    new PropertyDefinition('email', 'string'),
                    new PropertyDefinition('avatar', 'string', optional: true),
                ]),
            ],
        );

        $output = $generator->generate();

        self::assertStringContainsString('export interface User {', $output);
        self::assertStringContainsString('id: number;', $output);
        self::assertStringContainsString('name: string;', $output);
        self::assertStringContainsString('avatar?: string;', $output);
    }

    #[Test]
    public function generates_route_methods(): void
    {
        $generator = new TypeScriptClientGenerator(
            routes: [
                new RouteDefinition('users.list', 'GET', '/users', responseType: 'User[]'),
                new RouteDefinition(
                    'users.show',
                    'GET',
                    '/users/{id}',
                    parameters: [new ParameterDefinition('id', 'number')],
                    responseType: 'User',
                ),
                new RouteDefinition(
                    'users.create',
                    'POST',
                    '/users',
                    requestType: 'CreateUserRequest',
                    responseType: 'User',
                ),
            ],
        );

        $output = $generator->generate();

        self::assertStringContainsString('readonly users = {', $output);
        self::assertStringContainsString("'GET'", $output);
        self::assertStringContainsString("'POST'", $output);
    }

    #[Test]
    public function generates_with_custom_base_url(): void
    {
        $generator = new TypeScriptClientGenerator(
            routes: [],
            baseUrl: 'https://api.example.com',
            clientName: 'MyApiClient',
        );

        $output = $generator->generate();

        self::assertStringContainsString('class MyApiClient', $output);
        self::assertStringContainsString("'https://api.example.com'", $output);
    }

    #[Test]
    public function includes_request_helper(): void
    {
        $generator = new TypeScriptClientGenerator(routes: []);
        $output = $generator->generate();

        self::assertStringContainsString('private async request<T>', $output);
        self::assertStringContainsString('interface RequestOptions', $output);
    }

    #[Test]
    public function ungrouped_routes(): void
    {
        $generator = new TypeScriptClientGenerator(
            routes: [
                new RouteDefinition('health', 'GET', '/health', responseType: 'HealthStatus'),
            ],
        );

        $output = $generator->generate();

        self::assertStringContainsString('async health(', $output);
    }
}
