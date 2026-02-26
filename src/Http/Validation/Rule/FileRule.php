<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function array_any;
use function array_key_exists;
use function is_array;
use function sprintf;

use const UPLOAD_ERR_OK;

/**
 * Value must be a valid uploaded file array with expected keys and no upload error.
 * Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class FileRule implements RuleInterface
{
    public function __construct(
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if ($this->isValidUpload($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be a valid uploaded file.', $field),
            rule: $this->name(),
        );
    }

    private function isValidUpload(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        if (array_any(['tmp_name', 'error', 'size', 'name', 'type'], static fn(string $key): bool => !array_key_exists($key, $value))) {
            return false;
        }

        return $value['error'] === UPLOAD_ERR_OK;
    }

    #[Override]
    public function name(): string
    {
        return 'file';
    }
}
