<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Password\PasswordHasherInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Forum\Http\Controller\Page\RendersForumView;
use Pulsar\Extension\Forum\Support\UuidGenerator;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_array;
use function is_string;
use function max;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Registration controller: create a new forum account.
 */
#[Internal(reason: 'Forum auth controller; implementation detail')]
final readonly class RegisterController
{
    use RendersForumView;

    /**
     * @param PasswordHasherInterface|null $passwordHasher Bound by the composition
     *                                                     root; registration refuses
     *                                                     to run without it rather
     *                                                     than fall back to a weaker
     *                                                     algorithm.
     * @param int                          $passwordMinLength Minimum accepted password
     *                                                        length. The shipped templates
     *                                                        state 8; raise both together.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private ?TemplateEngineInterface $templateEngine = null,
        private ?PasswordHasherInterface $passwordHasher = null,
        private int $passwordMinLength = PasswordHasherInterface::MIN_LENGTH,
    ) {}

    private function getTemplateEngine(): ?TemplateEngineInterface
    {
        return $this->templateEngine;
    }

    /**
     * GET /register: Show registration form.
     */
    public function showForm(ServerRequestInterface $request): Response
    {
        return $this->respondWithView($request, 'auth.register', [
            'page_title' => 'Create Account',
            'errors' => [],
        ]);
    }

    /**
     * POST /register: Process registration.
     */
    public function register(ServerRequestInterface $request): Response
    {
        if ($this->passwordHasher === null) {
            return $this->respondWithView($request, 'auth.register', [
                'page_title' => 'Create Account',
                'errors' => ['form' => 'Registration is unavailable: no password hasher is configured.'],
            ], 503);
        }

        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return $this->respondWithView($request, 'auth.register', [
                'page_title' => 'Create Account',
                'errors' => ['form' => 'Invalid form submission.'],
            ], 422);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        /** @var mixed $rawDisplayName */
        $rawDisplayName = $body['display_name'] ?? null;
        $displayName = is_string($rawDisplayName) ? trim($rawDisplayName) : '';
        /** @var mixed $rawEmail */
        $rawEmail = $body['email'] ?? null;
        $email = is_string($rawEmail) ? trim($rawEmail) : '';
        /** @var mixed $rawPassword */
        $rawPassword = $body['password'] ?? null;
        $password = is_string($rawPassword) ? $rawPassword : '';
        /** @var mixed $rawPasswordConfirm */
        $rawPasswordConfirm = $body['password_confirm'] ?? null;
        $passwordConfirm = is_string($rawPasswordConfirm) ? $rawPasswordConfirm : '';

        $errors = $this->validate($displayName, $email, $password, $passwordConfirm);

        if ($errors !== []) {
            return $this->respondWithView($request, 'auth.register', [
                'page_title' => 'Create Account',
                'errors' => $errors,
                'display_name' => $displayName,
                'email' => $email,
            ], 422);
        }

        // Check if email already exists
        $existing = $this->connection->query(
            'SELECT id FROM auth_users WHERE email = :email LIMIT 1',
            ['email' => $email],
        );

        if ($existing->rowCount > 0) {
            return $this->respondWithView($request, 'auth.register', [
                'page_title' => 'Create Account',
                'errors' => ['email' => 'An account with this email already exists.'],
                'display_name' => $displayName,
                'email' => $email,
            ], 422);
        }

        $userId = UuidGenerator::v7();
        $passwordHash = $this->passwordHasher->hash($password);

        $this->connection->execute(
            <<<'SQL'
                INSERT INTO auth_users (id, display_name, email, password_hash, roles, two_factor_status)
                VALUES (:id, :name, :email, :hash, '["member"]', 'disabled')
                SQL,
            [
                'id' => $userId,
                'name' => $displayName,
                'email' => $email,
                'hash' => $passwordHash,
            ],
        );

        // Create forum profile
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO forum_profiles (id, user_id, reputation_score, post_count, thread_count, is_banned, created_at, updated_at)
                VALUES (:id, :user_id, 0, 0, 0, 0, datetime('now'), datetime('now'))
                SQL,
            [
                'id' => UuidGenerator::v7(),
                'user_id' => $userId,
            ],
        );

        return Response::redirect('/login?registered=1');
    }

    /**
     * @return array<string, string>
     */
    private function validate(string $displayName, string $email, string $password, string $passwordConfirm): array
    {
        $errors = [];

        if ($displayName === '' || mb_strlen($displayName) < 2) {
            $errors['display_name'] = 'Display name must be at least 2 characters.';
        }

        if ($displayName !== '' && mb_strlen($displayName) > 100) {
            $errors['display_name'] = 'Display name must be at most 100 characters.';
        }

        if ($email === '' || preg_match('/^[^@]+@[^@]+\.[^@]+$/', $email) !== 1) {
            $errors['email'] = 'A valid email address is required.';
        }

        $minLength = max(PasswordHasherInterface::MIN_LENGTH, $this->passwordMinLength);
        $passwordLength = mb_strlen($password);

        if ($passwordLength < $minLength) {
            $errors['password'] = sprintf('Password must be at least %d characters.', $minLength);
        } elseif ($passwordLength > PasswordHasherInterface::MAX_LENGTH) {
            $errors['password'] = sprintf('Password must be at most %d characters.', PasswordHasherInterface::MAX_LENGTH);
        }

        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }

        return $errors;
    }
}
