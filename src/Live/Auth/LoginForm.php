<?php

declare(strict_types=1);

namespace Pulsar\Live\Auth;

use Pulsar\Api\Api;
use Pulsar\Live\LiveForm;

/**
 * Typed form object for the login page.
 */
#[Api(since: '1.0.0')]
final class LoginForm extends LiveForm
{
    public string $email = '';

    public string $password = '';

    public bool $rememberMe = false;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required'],
        ];
    }
}
