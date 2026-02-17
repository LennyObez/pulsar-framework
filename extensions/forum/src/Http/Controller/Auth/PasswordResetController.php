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
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';

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
            $firstRow = $result->rows[0]->toArray();
            $userId = is_string($firstRow['id'] ?? null) ? $firstRow['id'] : '';
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
     */
    public function showResetForm(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $token = is_string($params['token'] ?? null) ? $params['token'] : '';

        return $this->respondWithView($request, 'auth.reset-password', [
            'page_title' => 'Set New Password',
            'token' => $token,
            'errors' => [],
        ]);
    }

    /**
     * POST /reset-password: Process password reset.
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

        $token = is_string($body['token'] ?? null) ? $body['token'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $passwordConfirm = is_string($body['password_confirm'] ?? null) ? $body['password_confirm'] : '';

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

        $resetRow = $resetResult->rows[0]->toArray();
        $userId = is_string($resetRow['user_id'] ?? null) ? $resetRow['user_id'] : '';
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
