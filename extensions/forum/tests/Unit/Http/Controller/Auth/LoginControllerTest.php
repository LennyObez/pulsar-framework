<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Password\PasswordHasher;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Extension\Forum\Http\Controller\Auth\LoginController;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(LoginController::class)]
final class LoginControllerTest extends TestCase
{
    /** Cheap parameters: these tests measure the migration, not the KDF. */
    private function hasher(): PasswordHasher
    {
        return new PasswordHasher(
            memoryCost: 256,
            timeCost: 1,
            threads: 1,
            allowWeakParameters: true,
        );
    }

    private function loginRequest(string $password): ServerRequest
    {
        return new ServerRequest(
            method: 'POST',
            uri: '/login',
            headers: ['Accept' => 'application/json'],
        )->withParsedBody(['email' => 'user@example.com', 'password' => $password]);
    }

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

    /**
     * The controller hashed registrations with bcrypt and verified them with a
     * bare `password_verify()`, so an account's first 72 bytes were its whole
     * credential and no login ever moved it off that algorithm.
     */
    #[Test]
    public function successfulLoginMigratesABcryptHashToArgon2id(): void
    {
        $password = 'a-legacy-bcrypt-password';
        $legacyHash = password_hash($password, PASSWORD_BCRYPT);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('query')->willReturn(Result::fromArrays([
            ['id' => 'user-1', 'password_hash' => $legacyHash, 'is_locked' => 0],
        ]));

        $hasher = $this->hasher();
        $written = null;
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('UPDATE auth_users'),
                self::callback(static function (array $bindings) use (&$written): bool {
                    $written = $bindings['hash'] ?? null;

                    return true;
                }),
            )
            ->willReturn(1);

        $controller = new LoginController(connection: $connection, passwordHasher: $hasher);

        $response = $controller->login($this->loginRequest($password));

        self::assertSame(302, $response->getStatusCode());
        self::assertIsString($written);
        self::assertStringStartsWith('$argon2id$', $written);
        self::assertTrue($hasher->verify($password, $written));
        self::assertFalse($hasher->needsRehash($written));
    }

    #[Test]
    public function anUpToDateHashIsNotRewrittenOnLogin(): void
    {
        $hasher = $this->hasher();
        $password = 'an-argon2id-password';

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('query')->willReturn(Result::fromArrays([
            ['id' => 'user-1', 'password_hash' => $hasher->hash($password), 'is_locked' => 0],
        ]));
        $connection->expects(self::never())->method('execute');

        $controller = new LoginController(connection: $connection, passwordHasher: $hasher);

        $response = $controller->login($this->loginRequest($password));

        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * A password predating the current floor still logs in; rehashing it would
     * throw, and refusing it would lock the account out.
     */
    #[Test]
    public function aLegacyPasswordBelowTheFloorLogsInWithoutRehashing(): void
    {
        $password = 'secret';
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('query')->willReturn(Result::fromArrays([
            ['id' => 'user-1', 'password_hash' => password_hash($password, PASSWORD_BCRYPT), 'is_locked' => 0],
        ]));
        $connection->expects(self::never())->method('execute');

        $controller = new LoginController(connection: $connection, passwordHasher: $this->hasher());

        $response = $controller->login($this->loginRequest($password));

        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * ASVS 4.0.3 §2.1.3. Under bcrypt this request authenticated.
     */
    #[Test]
    public function aSeventyTwoBytePrefixOfTheRealPasswordIsRejected(): void
    {
        $hasher = $this->hasher();
        $prefix = str_repeat('A', 72);
        $password = $prefix . 'and-a-divergent-suffix-of-29c';

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('query')->willReturn(Result::fromArrays([
            ['id' => 'user-1', 'password_hash' => $hasher->hash($password), 'is_locked' => 0],
        ]));
        $connection->expects(self::never())->method('execute');

        $controller = new LoginController(connection: $connection, passwordHasher: $hasher);

        $response = $controller->login($this->loginRequest($prefix));

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Invalid email or password.', $body['errors']['form']);
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
