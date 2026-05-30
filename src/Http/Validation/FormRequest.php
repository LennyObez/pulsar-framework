<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

use function array_key_exists;
use function is_array;

/**
 * Base class for auto-validated form requests.
 *
 * Extend this class and implement rules() to define validation.
 * Optionally override authorize() to add authorization checks.
 *
 * When used as a controller parameter type-hint, the framework
 * validates the request automatically and throws ValidationException
 * on failure, or returns 403 if authorize() returns false.
 *
 * Usage:
 *   class CreateUserRequest extends FormRequest
 *   {
 *       public function rules(): array
 *       {
 *           return [
 *               'email' => 'required|email',
 *               'name' => 'required|string|max_length:255',
 *           ];
 *       }
 *
 *       public function authorize(): bool
 *       {
 *           return $this->user() !== null;
 *       }
 *   }
 * @api
 */
#[Api(since: '1.0.0')]
abstract class FormRequest
{
    private ServerRequestInterface $request;

    /** @var array<string, mixed> */
    private array $validatedData = [];

    /**
     * Define validation rules.
     *
     * Keys are field names, values are pipe-separated rule strings
     * or arrays of RuleInterface objects.
     *
     * @return array<string, string|list<RuleInterface>>
     */
    abstract public function rules(): array;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Override to add authorization logic. Defaults to true.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Set the underlying server request. Called by the framework.
     */
    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    /**
     * Get the underlying server request.
     */
    public function request(): ServerRequestInterface
    {
        return $this->request;
    }

    /**
     * Validate the request data against the defined rules.
     *
     * @throws ValidationException When validation fails
     *
     * @return array<string, mixed> The validated data
     */
    public function validated(): array
    {
        if ($this->validatedData !== []) {
            return $this->validatedData;
        }

        $data = $this->inputData();
        $builder = ValidatorBuilder::make($data)->rules($this->rules());

        $builder->validateOrFail();
        $this->validatedData = $this->extractValidated($data, $this->rules());

        return $this->validatedData;
    }

    /**
     * Get a single validated value.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $data = $this->inputData();

        return $data[$key] ?? $default;
    }

    /**
     * Get the authenticated user from the request attributes.
     */
    public function user(): mixed
    {
        return $this->request->getAttribute('_user');
    }

    /**
     * Merge all input sources into a single array.
     *
     * @return array<string, mixed>
     */
    private function inputData(): array
    {
        /** @var array<string, mixed> $query */
        $query = $this->request->getQueryParams();
        $body = $this->request->getParsedBody();

        if (is_array($body)) {
            /** @var array<string, mixed> $merged */
            $merged = [...$query, ...$body];
            return $merged;
        }

        return $query;
    }

    /**
     * Extract only the fields that have validation rules defined.
     *
     * @param array<string, mixed> $data
     * @param array<string, string|list<RuleInterface>> $rules
     *
     * @return array<string, mixed>
     */
    private function extractValidated(array $data, array $rules): array
    {
        $validated = [];

        foreach ($rules as $field => $_) {
            if (array_key_exists($field, $data)) {
                $validated = [...$validated, $field => $data[$field]];
            }
        }

        return $validated;
    }
}
