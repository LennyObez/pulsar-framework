<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Guard\SessionGuard;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Forum\Http\Controller\Page\RendersForumView;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_array;
use function is_string;
use function password_verify;
use function trim;

/**
 * Login controller: authenticate existing forum users.
 */
#[Internal(reason: 'Forum auth controller; implementation detail')]
final readonly class LoginController
{
    use RendersForumView;

    public function __construct(
        private ConnectionInterface $connection,
        private ?SessionGuard $sessionGuard = null,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    private function getTemplateEngine(): ?TemplateEngineInterface
    {
        return $this->templateEngine;
    }

    /**
     * GET /login: Show login form.
     */
    public function showForm(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $registered = ($params['registered'] ?? '') === '1';

        return $this->respondWithView($request, 'auth.login', [
            'page_title' => 'Sign In',
            'errors' => [],
            'registered' => $registered,
        ]);
    }

    /**
     * POST /login: Process login.
     */
    public function login(ServerRequestInterface $request): Response
    {
        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return $this->respondWithView($request, 'auth.login', [
                'page_title' => 'Sign In',
                'errors' => ['form' => 'Invalid form submission.'],
            ], 422);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        /** @var mixed $rawEmail */
        $rawEmail = $body['email'] ?? null;
        $email = is_string($rawEmail) ? trim($rawEmail) : '';
        /** @var mixed $rawPassword */
        $rawPassword = $body['password'] ?? null;
        $password = is_string($rawPassword) ? $rawPassword : '';

        if ($email === '' || $password === '') {
            return $this->respondWithView($request, 'auth.login', [
                'page_title' => 'Sign In',
                'errors' => ['form' => 'Email and password are required.'],
                'email' => $email,
            ], 422);
        }

        $result = $this->connection->query(
            'SELECT id, password_hash, is_locked FROM auth_users WHERE email = :email LIMIT 1',
            ['email' => $email],
        );

        if ($result->rowCount === 0) {
            return $this->respondWithView($request, 'auth.login', [
                'page_title' => 'Sign In',
                'errors' => ['form' => 'Invalid email or password.'],
                'email' => $email,
            ], 422);
        }

        $userRow = $result->rows[0];
        $passwordHash = $userRow->getString('password_hash');

        if (!password_verify($password, $passwordHash)) {
            return $this->respondWithView($request, 'auth.login', [
                'page_title' => 'Sign In',
                'errors' => ['form' => 'Invalid email or password.'],
                'email' => $email,
            ], 422);
        }

        if ($userRow->getInt('is_locked') === 1) {
            return $this->respondWithView($request, 'auth.login', [
                'page_title' => 'Sign In',
                'errors' => ['form' => 'This account has been locked. Contact an administrator.'],
                'email' => $email,
            ], 403);
        }

        $userId = $userRow->getString('id');
        $identity = new Identity(
            id: $userId,
            displayName: $email,
            roles: ['user'],
        );

        $this->sessionGuard?->login($identity);

        return Response::redirect('/');
    }

    /**
     * POST /logout; Log out the current user.
     */
    public function logout(ServerRequestInterface $request): Response
    {
        $this->sessionGuard?->logout();

        return Response::redirect('/login');
    }
}
