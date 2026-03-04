<?php

declare(strict_types=1);

namespace Pulsar\Live\Auth;

use Pulsar\Api\Api;
use Pulsar\Live\LiveForm;

/**
 * Typed form object for the signup page.
 */
#[Api(since: '1.0.0')]
final class SignupForm extends LiveForm
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'min:2', 'max:100'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8', 'confirmed'],
            'password_confirmation' => ['required'],
        ];
    }
}
