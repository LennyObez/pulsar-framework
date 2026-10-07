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
     * Minimum accepted password length, taken from the resolved configuration.
     *
     * Not form data, so not writable from outside: a password policy is something
     * the server decides and the client is told. `private(set)` is what says so,
     * and LiveForm::fill() honours it, so request data cannot reach this field at
     * all. The clamp in rules() stays as the second line — a policy this important
     * should not rest on one mechanism.
     */
    public private(set) int $minPasswordLength = PasswordHasherInterface::MIN_LENGTH;

    /**
     * Raise the minimum password length for this form.
     *
     * Never lowers it: the framework floor is a floor, and a configuration that
     * asks for less than the framework accepts gets the framework's answer.
     */
    public function requireAtLeast(int $characters): void
    {
        $this->minPasswordLength = max(PasswordHasherInterface::MIN_LENGTH, $characters);
    }

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
