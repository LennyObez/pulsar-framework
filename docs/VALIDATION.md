# Request Validation

Pulsar provides a typed, rule-based validation system for request data. The system is stateless, composable, and produces consistent JSON error responses.

## Overview

The validation system consists of:

- **Rules** - Small, focused classes that validate a single aspect of a value
- **Validator** - Stateless service that runs rules against input data
- **ValidationResult** - Immutable collection of violations
- **ValidationException** - HTTP exception (422) carrying the validation result
- **ValidationMiddleware** - Abstract middleware for route-level validation

## Validator Usage

```php
use Pulsar\Http\Validation\Validator;
use Pulsar\Http\Validation\Rule\{Required, StringType, Email, MinLength};

$validator = new Validator();

$result = $validator->validate($request->all(), [
    'name'  => [new Required(), new StringType(), new MinLength(2)],
    'email' => [new Required(), new Email()],
]);

if ($result->failed()) {
    // Handle violations
    foreach ($result->violations as $violation) {
        echo "{$violation->field}: {$violation->message}\n";
    }
}
```

### Validate or Fail

Throws `ValidationException` on failure (caught by the ExceptionHandler for automatic 422 JSON):

```php
$validator->validateOrFail($request->all(), [
    'name'  => [new Required(), new StringType()],
    'email' => [new Required(), new Email()],
]);
// Execution continues only if validation passes
```

## Built-in Rules

All rules are `readonly class` implementations of `RuleInterface`. All skip `null` values except `Required`. All accept an optional custom `$message` parameter.

| Rule          | Constructor                                                | Behavior                                    |
| ------------- | ---------------------------------------------------------- | ------------------------------------------- |
| `Required`    | `(string $message = '')`                                   | Fails on `null`, `''`, `[]`                 |
| `StringType`  | `(string $message = '')`                                   | Must be `string`                            |
| `IntegerType` | `(string $message = '')`                                   | Must be `int` or pass `FILTER_VALIDATE_INT` |
| `MinLength`   | `(int $min, string $message = '')`                         | `mb_strlen >= $min`                         |
| `MaxLength`   | `(int $max, string $message = '')`                         | `mb_strlen <= $max`                         |
| `Min`         | `(int\|float $min, string $message = '')`                  | Numeric `>= $min`                           |
| `Max`         | `(int\|float $max, string $message = '')`                  | Numeric `<= $max`                           |
| `Between`     | `(int\|float $min, int\|float $max, string $message = '')` | Numeric range inclusive                     |
| `Email`       | `(string $message = '')`                                   | `FILTER_VALIDATE_EMAIL`                     |
| `In`          | `(array $allowed, string $message = '')`                   | Loose comparison (HTTP strings)             |
| `Regex`       | `(string $pattern, string $message = '')`                  | `preg_match`                                |

### Required Short-Circuit

When a `Required` rule fails, remaining rules for that field are skipped. This prevents misleading cascading errors (e.g., "must be at least 3 characters" for a missing field).

## Error Response Shape

All validation errors produce a consistent JSON response:

```json
{
  "error": "Validation Failed",
  "status": 422,
  "violations": [
    {
      "field": "email",
      "message": "The email field is required.",
      "rule": "required"
    },
    {
      "field": "name",
      "message": "The name field must be at least 2 characters.",
      "rule": "min_length"
    }
  ]
}
```

HTTP status is always **422 Unprocessable Entity**.

## Custom Rules

Implement `RuleInterface` to create custom rules:

```php
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

readonly class Unique implements RuleInterface
{
    public function __construct(
        private UserRepository $users,
        private string $message = '',
    ) {}

    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if ($this->users->existsByEmail((string) $value)) {
            return new Violation(
                field: $field,
                message: $this->message !== '' ? $this->message : "The {$field} has already been taken.",
                rule: $this->name(),
            );
        }

        return null;
    }

    public function name(): string
    {
        return 'unique';
    }
}
```

### Rule Contract

```php
interface RuleInterface
{
    /**
     * @param array<string, mixed> $data Full input data (for cross-field rules)
     */
    public function validate(string $field, mixed $value, array $data): ?Violation;

    public function name(): string;
}
```

- Return `null` when validation passes
- Return a `Violation` when validation fails
- The `$data` parameter provides full input for cross-field validation
- Skip `null` values unless the rule is specifically about presence (like `Required`)

## ValidationMiddleware

For route-level validation, extend `ValidationMiddleware`:

```php
use Pulsar\Http\Middleware\ValidationMiddleware;

class UpdateProfileValidation extends ValidationMiddleware
{
    protected function rules(Request $request): array
    {
        return [
            'name'  => [new Required(), new StringType(), new MinLength(1), new MaxLength(255)],
            'email' => [new Required(), new Email()],
            'age'   => [new IntegerType(), new Between(18, 120)],
        ];
    }
}
```

Attach to a route:

```php
$router->add(new Route(
    methods: [Method::PUT],
    path: '/api/profile',
    handler: UpdateProfileHandler::class,
    middleware: [UpdateProfileValidation::class],
));
```

When validation fails, the middleware throws `ValidationException`. The `ExceptionHandler` catches it and returns the 422 JSON response. The route handler is never invoked.
