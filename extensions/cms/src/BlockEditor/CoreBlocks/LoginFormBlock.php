<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;

use function htmlspecialchars;
use function is_bool;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class LoginFormBlock implements BlockTypeInterface
{
    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {}

    #[Override]
    public function type(): string
    {
        return 'login-form';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'format' => 'uri'],
                'redirectUrl' => ['type' => 'string', 'format' => 'uri'],
                'showRememberMe' => ['type' => 'boolean'],
                'showForgotPassword' => ['type' => 'boolean'],
                'forgotPasswordUrl' => ['type' => 'string', 'format' => 'uri'],
                'registerUrl' => ['type' => 'string', 'format' => 'uri'],
                'isLoggedIn' => ['type' => 'boolean'],
                'logoutUrl' => ['type' => 'string', 'format' => 'uri'],
                'username' => ['type' => 'string'],
            ],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $isLoggedIn = ($data['isLoggedIn'] ?? false) === true;

        if ($isLoggedIn) {
            return $this->renderLoggedIn($data);
        }

        return $this->renderLoginForm($data);
    }

    /**
     * @param array{
     *     action?: string,
     *     redirectUrl?: string,
     *     showRememberMe?: bool,
     *     showForgotPassword?: bool,
     *     forgotPasswordUrl?: string,
     *     registerUrl?: string|null,
     * } $data
     */
    private function renderLoginForm(array $data): string
    {
        $action = htmlspecialchars($data['action'] ?? '/login', ENT_QUOTES, 'UTF-8');
        $redirectUrl = $data['redirectUrl'] ?? '';
        $showRememberMe = $data['showRememberMe'] ?? true;
        $showForgotPassword = $data['showForgotPassword'] ?? true;
        $forgotPasswordUrl = htmlspecialchars(
            $data['forgotPasswordUrl'] ?? '/forgot-password',
            ENT_QUOTES,
            'UTF-8',
        );
        $registerUrl = $data['registerUrl'] ?? null;

        $csrfToken = htmlspecialchars($this->csrfTokenManager->getToken(), ENT_QUOTES, 'UTF-8');

        $html = "<form class=\"login-form-block\" method=\"post\" action=\"$action\">"
            . "<input type=\"hidden\" name=\"_csrf_token\" value=\"$csrfToken\">";

        if ($redirectUrl !== '') {
            $escapedRedirect = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
            $html .= "<input type=\"hidden\" name=\"_redirect\" value=\"$escapedRedirect\">";
        }

        $html .= '<div class="login-form-block__field">'
            . '<label for="login-email">Email</label>'
            . '<input type="email" id="login-email" name="email" required autocomplete="email">'
            . '</div>'
            . '<div class="login-form-block__field">'
            . '<label for="login-password">Password</label>'
            . '<input type="password" id="login-password" name="password" required autocomplete="current-password">'
            . '</div>';

        if ($showRememberMe) {
            $html .= '<div class="login-form-block__remember">'
                . '<label><input type="checkbox" name="remember" value="1"> Remember me</label>'
                . '</div>';
        }

        $html .= '<button type="submit" class="login-form-block__submit">Log in</button>';

        if ($showForgotPassword) {
            $html .= "<a href=\"$forgotPasswordUrl\" class=\"login-form-block__forgot\">Forgot password?</a>";
        }

        if (is_string($registerUrl) && $registerUrl !== '') {
            $escapedRegister = htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8');
            $html .= "<a href=\"$escapedRegister\" class=\"login-form-block__register\">Create account</a>";
        }

        return $html . '</form>';
    }

    /**
     * @param array{username?: string, logoutUrl?: string} $data
     */
    private function renderLoggedIn(array $data): string
    {
        $username = htmlspecialchars($data['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
        $logoutUrl = htmlspecialchars($data['logoutUrl'] ?? '/logout', ENT_QUOTES, 'UTF-8');

        return '<div class="login-form-block login-form-block--logged-in">'
            . "<span class=\"login-form-block__greeting\">Welcome, $username</span>"
            . "<a href=\"$logoutUrl\" class=\"login-form-block__logout\">Log out</a>"
            . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (isset($data['action']) && !is_string($data['action'])) {
            $errors[] = 'action must be a string';
        }

        if (isset($data['redirectUrl']) && !is_string($data['redirectUrl'])) {
            $errors[] = 'redirectUrl must be a string';
        }

        if (isset($data['showRememberMe']) && !is_bool($data['showRememberMe'])) {
            $errors[] = 'showRememberMe must be a boolean';
        }

        if (isset($data['showForgotPassword']) && !is_bool($data['showForgotPassword'])) {
            $errors[] = 'showForgotPassword must be a boolean';
        }

        if (isset($data['forgotPasswordUrl']) && !is_string($data['forgotPasswordUrl'])) {
            $errors[] = 'forgotPasswordUrl must be a string';
        }

        if (isset($data['logoutUrl']) && !is_string($data['logoutUrl'])) {
            $errors[] = 'logoutUrl must be a string';
        }

        if (isset($data['username']) && !is_string($data['username'])) {
            $errors[] = 'username must be a string';
        }

        return $errors;
    }
}
