<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Forum\Http\Controller\Page\RendersForumView;
use Pulsar\Extension\Forum\Internal\Mail\PasswordResetMailable;
use Pulsar\Extension\Forum\Support\UuidGenerator;
use Pulsar\Http\Message\Response;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\View\Engine\TemplateEngineInterface;

use function bin2hex;
use function hash;
use function is_array;
use function is_string;
use function mb_strlen;
use function password_hash;
use function random_bytes;
use function trim;

use const PASSWORD_BCRYPT;

/**
 * Password reset controller: request and complete password resets.
 */
#[Internal(reason: 'Forum auth controller; implementation detail')]
final readonly class PasswordResetController
{
    use RendersForumView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private ConnectionInterface $connection,
        private ?MailManagerInterface $mailManager = null,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    private function getTemplateEngine(): ?TemplateEngineInterface
    {
        return $this->templateEngine;
    }

    /**
     * GET /forgot-password: Show forgot password form.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function showRequestForm(ServerRequestInterface $request): Response
    {
        return $this->respondWithView($request, 'auth.forgot-password', [
            'page_title' => 'Reset Password',
            'errors' => [],
            'sent' => false,
        ]);
    }

    /**
     * POST /forgot-password: Process password reset request.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function sendResetLink(ServerRequestInterface $request): Response
    {
        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return $this->respondWithView($request, 'auth.forgot-password', [
                'page_title' => 'Reset Password',
                'errors' => ['form' => 'Invalid form submission.'],
                'sent' => false,
            ], 422);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;
        /** @var mixed $rawEmail */
        $rawEmail = $body['email'] ?? null;
        $email = is_string($rawEmail) ? trim($rawEmail) : '';

        // Always show success to prevent email enumeration
        if ($email === '') {
            return $this->respondWithView($request, 'auth.forgot-password', [
                'page_title' => 'Reset Password',
                'errors' => ['email' => 'A valid email address is required.'],
                'sent' => false,
            ], 422);
        }

        $result = $this->connection->query(
            'SELECT id FROM auth_users WHERE email = :email LIMIT 1',
            ['email' => $email],
        );

        if ($result->rowCount > 0) {
            $userId = $result->rows[0]->getString('id');
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            $this->connection->execute(
                <<<'SQL'
                    INSERT INTO password_resets (id, user_id, token_hash, expires_at)
                    VALUES (:id, :user_id, :token_hash, datetime('now', '+1 hour'))
                    SQL,
                [
                    'id' => UuidGenerator::v7(),
                    'user_id' => $userId,
                    'token_hash' => $tokenHash,
                ],
            );

            if ($this->mailManager !== null) {
                $mailable = new PasswordResetMailable($email, $token);
                $this->mailManager->send($mailable);
            }
        }

        // Always show success to prevent email enumeration
        return $this->respondWithView($request, 'auth.forgot-password', [
            'page_title' => 'Reset Password',
            'errors' => [],
            'sent' => true,
        ]);
    }

    /**
     * GET /reset-password: Show password reset form (with token).
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function showResetForm(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawToken */
        $rawToken = $params['token'] ?? null;
        $token = is_string($rawToken) ? $rawToken : '';

        return $this->respondWithView($request, 'auth.reset-password', [
            'page_title' => 'Set New Password',
            'token' => $token,
            'errors' => [],
        ]);
    }

    /**
     * POST /reset-password: Process password reset.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function resetPassword(ServerRequestInterface $request): Response
    {
        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return $this->respondWithView($request, 'auth.reset-password', [
                'page_title' => 'Set New Password',
                'token' => '',
                'errors' => ['form' => 'Invalid form submission.'],
            ], 422);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        /** @var mixed $rawToken */
        $rawToken = $body['token'] ?? null;
        $token = is_string($rawToken) ? $rawToken : '';
        /** @var mixed $rawPassword */
        $rawPassword = $body['password'] ?? null;
        $password = is_string($rawPassword) ? $rawPassword : '';
        /** @var mixed $rawPasswordConfirm */
        $rawPasswordConfirm = $body['password_confirm'] ?? null;
        $passwordConfirm = is_string($rawPasswordConfirm) ? $rawPasswordConfirm : '';

        $errors = [];

        if ($token === '') {
            $errors['token'] = 'Invalid or expired reset token.';
        }

        if (mb_strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }

        if ($errors !== []) {
            return $this->respondWithView($request, 'auth.reset-password', [
                'page_title' => 'Set New Password',
                'token' => $token,
                'errors' => $errors,
            ], 422);
        }

        $tokenHash = hash('sha256', $token);

        $resetResult = $this->connection->query(
            <<<'SQL'
                SELECT user_id FROM password_resets
                WHERE token_hash = :hash AND used_at IS NULL AND expires_at > datetime('now')
                LIMIT 1
                SQL,
            ['hash' => $tokenHash],
        );

        if ($resetResult->rowCount === 0) {
            return $this->respondWithView($request, 'auth.reset-password', [
                'page_title' => 'Set New Password',
                'token' => $token,
                'errors' => ['token' => 'Invalid or expired reset token.'],
            ], 422);
        }

        $userId = $resetResult->rows[0]->getString('user_id');
        $newHash = password_hash($password, PASSWORD_BCRYPT);

        $this->connection->execute(
            'UPDATE auth_users SET password_hash = :hash WHERE id = :id',
            ['hash' => $newHash, 'id' => $userId],
        );

        $this->connection->execute(
            "UPDATE password_resets SET used_at = datetime('now') WHERE token_hash = :hash",
            ['hash' => $tokenHash],
        );

        return Response::redirect('/login?reset=1');
    }
}
