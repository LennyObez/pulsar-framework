<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Forum\Http\Controller\Auth\LoginController;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(LoginController::class)]
final class LoginControllerTest extends TestCase
{
    #[Test]
    public function showFormReturnsLoginPage(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $controller = new LoginController($connection);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/login',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->showForm($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Sign In', $body['page_title']);
        self::assertSame([], $body['errors']);
        self::assertFalse($body['registered']);
    }

    #[Test]
    public function showFormWithRegisteredQueryParam(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $controller = new LoginController($connection);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/login',
            headers: ['Accept' => 'application/json'],
            queryParams: ['registered' => '1'],
        );

        $response = $controller->showForm($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['registered']);
    }

    #[Test]
    public function loginWithNullBodyReturns422(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $controller = new LoginController($connection);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/login',
            headers: ['Accept' => 'application/json'],
        );
        // parsedBody is null by default

        $response = $controller->login($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('errors', $body);
    }

    #[Test]
    public function loginWithEmptyCredentialsReturns422(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $controller = new LoginController($connection);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/login',
            headers: ['Accept' => 'application/json'],
        )->withParsedBody(['email' => '', 'password' => '']);

        $response = $controller->login($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Email and password are required.', $body['errors']['form']);
    }

    #[Test]
    public function loginWithMissingFieldsReturns422(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $controller = new LoginController($connection);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/login',
            headers: ['Accept' => 'application/json'],
        )->withParsedBody([]);

        $response = $controller->login($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Email and password are required.', $body['errors']['form']);
    }

    #[Test]
    public function logoutRedirectsToLogin(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $controller = new LoginController($connection);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/logout',
        );

        $response = $controller->logout($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
    }
}
