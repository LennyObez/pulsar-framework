<?php

declare(strict_types=1);

namespace Pulsar\Live\Auth;

use Pulsar\Api\Api;
use Pulsar\Auth\Password\PasswordHasherInterface;
use Pulsar\Live\LiveForm;

use function max;

/**
 * Typed form object for the signup page.
 *
 * The password rule is built from {@see self::$minPasswordLength} so the enforced
 * minimum is the one the surface resolved from configuration and displayed to the
 * user, rather than a second number compiled in here.
 * @api
 */
#[Api(since: '1.0.0')]
final class SignupForm extends LiveForm
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Minimum accepted password length, assigned from the resolved configuration.
     *
     * Clamped to the framework floor when the rules are built, so a form filled
     * from request data cannot weaken the policy below it.
     */
    public int $minPasswordLength = PasswordHasherInterface::MIN_LENGTH;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $min = max(PasswordHasherInterface::MIN_LENGTH, $this->minPasswordLength);

        return [
            // Length rules, not the numeric pair: a password of digits is a password.
            'name' => ['required', 'min_length:2', 'max_length:100'],
            'email' => ['required', 'email'],
            'password' => [
                'required',
                "min_length:{$min}",
                'max_length:' . PasswordHasherInterface::MAX_LENGTH,
                'confirmed',
            ],
            'password_confirmation' => ['required'],
        ];
    }
}
