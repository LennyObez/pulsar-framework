<?php

declare(strict_types=1);

namespace Pulsar\Live\Auth;

use Pulsar\Api\Api;

/**
 * Contract for the auth UI authenticator service.
 *
 * Implementors connect the auth UI components to the application's
 * actual authentication backend (session guard, token guard, etc.).
 * @api
 */
#[Api(since: '1.0.0')]
interface AuthenticatorInterface
{
    /**
     * Attempt email/password authentication.
     */
    public function attempt(string $email, string $password, bool $rememberMe = false): AuthResult;

    /**
     * Register a new user account.
     *
     * @param array<string, mixed> $data Registration data (name, email, password)
     */
    public function register(array $data): AuthResult;

    /**
     * Verify a TOTP/MFA code for a pending authentication.
     */
    public function verifyMfa(string $identityId, string $code): AuthResult;

    /**
     * Get the currently authenticated user's profile data.
     *
     * @return array<string, mixed>|null Null if not authenticated
     */
    public function currentUser(): ?array;

    /**
     * Update the current user's profile.
     *
     * @param array<string, mixed> $data
     */
    public function updateProfile(array $data): AuthResult;

    /**
     * Change the current user's password.
     */
    public function changePassword(string $currentPassword, string $newPassword): AuthResult;

    /**
     * Log out the current user.
     */
    public function logout(): void;
}
