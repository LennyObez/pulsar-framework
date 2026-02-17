<?php

declare(strict_types=1);

namespace App\Request;

use Pulsar\Http\Validation\FormRequest;

/**
 * Validated form request for user creation.
 *
 * Pulsar FormRequests provide automatic validation and authorization:
 * - Define rules() to specify validation constraints
 * - Override authorize() to add access control
 * - The framework validates before the controller runs
 * - On failure, a 422 response is returned automatically
 *
 * Supported validation rules include:
 *   required, string, email, min_length, max_length,
 *   integer, boolean, in, regex, confirmed, unique, url
 */
final class CreateUserRequest extends FormRequest
{
    /**
     * Define the validation rules for creating a user.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|min_length:2|max_length:255',
            'email' => 'required|email|max_length:255',
            'role' => 'required|in:admin,editor,viewer',
            'bio' => 'string|max_length:1000',
        ];
    }

    /**
     * Custom error messages (optional).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A display name is required.',
            'email.email' => 'Please provide a valid email address.',
            'role.in' => 'Role must be one of: admin, editor, viewer.',
        ];
    }

    /**
     * Only authenticated users may create other users.
     */
    public function authorize(): bool
    {
        // In a real app, check $this->user() !== null
        return true;
    }
}
